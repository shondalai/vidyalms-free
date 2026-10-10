import { studioStrings as studio } from './studio-strings';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import { useEffect, useRef, useState } from 'react';
import { ExternalLink } from 'lucide-react';
import { ProblemError } from '@/shared/api';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    Icon,
    SessionExpired,
    buttonVariants,
} from '@/shared/components';
import { useSessionExpired } from '@/shared/session';
import { useManagedCourse } from './api';
import { fillUrlTemplate, hasRoute, useInstructorConfig } from './config';
import { instructorStrings as strings } from './strings';
import { NARROW_BELOW_PX, useContainerWidth, useHashTab } from './lib/layout';
import { Tabs, TabPanel, type TabDefinition } from './components/tabs';
import { NotFoundState, PermissionDenied, WorkbenchSkeleton } from './components/states';
import { CourseListScreen } from './tabs/course-list';
import { CurriculumTab } from './tabs/curriculum-tab';
import { QuizzesTab } from './tabs/quizzes-tab';
import { GradebookTab } from './tabs/gradebook-tab';
import { LearnersTab } from './tabs/learners-tab';
import { PublishingTab, SettingsTab } from './tabs/settings-tab';
import { ReportsTab } from './tabs/reports-tab';
import { CohortsTab } from './tabs/cohorts-tab';
import { NotificationsTab } from './tabs/notifications-tab';
import { flowStrings } from './flow-strings';
import { assessmentStrings } from './assessment-strings';
import { courseCertificateStrings } from './tabs/course-certificate-strings';

/*
 * The instructor workbench (artboard 04): one course, tabs covering build
 * (Curriculum, Quizzes), run (Gradebook, Learners, Reports) and Settings.
 *
 * The mount decides which of the two screens this is: a course in the
 * config opens its workbench, no course opens the caller's course list.
 * Under NARROW_BELOW_PX of MOUNT width (never viewport width; the app sits
 * inside administrator chrome that owns the page) the header and the tab
 * contents stack.
 *
 * Reports sits beside Gradebook rather than inside Quizzes because it is
 * about the course: item analysis keys on the question version and the
 * marking rule, and the same banked version can be served by two quizzes of
 * one course.
 */

const TAB_IDS = [
    'curriculum',
    'quizzes',
    'gradebook',
    'learners',
    'reports',
    'cohorts',
    'notifications',
    'settings',
    'publishing',
] as const;
type TabId = (typeof TAB_IDS)[number];

const panelClass =
    'vy-course-workbench overflow-hidden rounded-lg border border-border bg-surface font-body text-fg';

export function InstructorApp() {
    const config = useInstructorConfig();
    const rootRef = useRef<HTMLDivElement>(null);
    const width = useContainerWidth(rootRef);
    const narrow = width < NARROW_BELOW_PX;
    const sessionExpired = useSessionExpired();

    if (sessionExpired) {
        return (
            <div ref={rootRef} role="region" aria-label={strings.app.region} className={panelClass}>
                <SessionExpired />
            </div>
        );
    }

    if (config.course === null) {
        return (
            <div ref={rootRef} role="region" aria-label={strings.app.coursesRegion} className={panelClass}>
                <CourseListScreen narrow={narrow} />
            </div>
        );
    }

    return (
        <div ref={rootRef} role="region" aria-label={strings.app.region} className={panelClass}>
            <Workbench courseId={config.course.id} slug={config.course.slug} narrow={narrow} />
        </div>
    );
}

function Workbench({ courseId, slug, narrow }: { courseId: number; slug: string; narrow: boolean }) {
    const config = useInstructorConfig();
    const courseQuery = useManagedCourse(courseId);
    const [routeTab, setRouteTab] = useHashTab<TabId>(TAB_IDS, 'curriculum');
    const cohortsAvailable = hasRoute(config, '/manage/courses/{id}/cohorts');
    const reportsAvailable = hasRoute(config, '/manage/courses/{id}/reports/item-analysis');
    const availableRouteTab = (routeTab === 'cohorts' && !cohortsAvailable) || (routeTab === 'reports' && !reportsAvailable)
        ? 'curriculum' : routeTab;
    const [tab, setActiveTab] = useState(availableRouteTab);
    const [preferredQuizId, setPreferredQuizId] = useState<number | null>(null);
    const [certificateDirty, setCertificateDirty] = useState(false);
    const [pendingTab, setPendingTab] = useState<TabId | null>(null);
    useEffect(() => {
        if (routeTab !== availableRouteTab) setRouteTab(availableRouteTab);
        if (availableRouteTab === tab) return;
        if (certificateDirty || hasUnsavedWorkspaceChanges()) {
            setPendingTab(availableRouteTab);
            setRouteTab(tab);
        } else setActiveTab(availableRouteTab);
    }, [routeTab, availableRouteTab, tab, certificateDirty, setRouteTab]);
    const setTab = (next: TabId) => {
        if (next === tab) {
            // An explicit selection still canonicalizes a missing/host hash,
            // without unmounting this panel or treating its draft as abandoned.
            setRouteTab(next);
            return;
        }
        if (certificateDirty || hasUnsavedWorkspaceChanges()) setPendingTab(next);
        else {
            setActiveTab(next);
            setRouteTab(next);
        }
    };

    if (courseQuery.isPending) {
        return <WorkbenchSkeleton narrow={narrow} />;
    }

    if (courseQuery.isError) {
        const status = courseQuery.error instanceof ProblemError ? courseQuery.error.status : 0;
        if (status === 403) {
            return <PermissionDenied />;
        }
        if (status === 404) {
            return <NotFoundState />;
        }
        return (
            <div className="grid gap-3 p-5">
                <ErrorState
                    error={courseQuery.error}
                    title={strings.states.error.title}
                    onRetry={() => void courseQuery.refetch()}
                />
                <div>
                    <a
                        className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        href={config.urls.courses}
                    >
                        {strings.app.backToCourses}
                    </a>
                </div>
            </div>
        );
    }

    const course = courseQuery.data;
    const ruleText =
        course.completion_rule === 'percentage'
            ? strings.header.rule.percentage(course.completion_threshold ?? 0)
            : course.completion_rule === 'pass_final'
              ? strings.header.rule.pass_final
              : strings.header.rule.all_lessons;

    const tabs: TabDefinition<TabId>[] = [
        { id: 'curriculum', label: strings.tabs.curriculum },
        { id: 'quizzes', label: strings.tabs.quizzes },
        {
            id: 'gradebook',
            label: strings.tabs.gradebook,
            badge: course.pending_grading_count,
            badgeLabel: strings.tabs.pendingCount(course.pending_grading_count),
        },
        { id: 'learners', label: strings.tabs.learners },
        ...(reportsAvailable ? [{ id: 'reports' as const, label: assessmentStrings.tabs.reports }] : []),
        ...(cohortsAvailable ? [{ id: 'cohorts' as const, label: flowStrings.cohorts.tab }] : []),
        { id: 'notifications', label: flowStrings.notifications.tab },
        { id: 'settings', label: strings.tabs.settings },
        { id: 'publishing', label: studio.sections.publishing[0] },
    ];

    return (
        <>
            <div className="vy-studio-header">
                <div className="min-w-0">
                    <p className="vy-studio-eyebrow">{studio.eyebrow}</p>
                    <h2 className="font-display text-lg font-bold tracking-[-0.015em] text-fg">
                        {course.title}
                    </h2>
                    <p className="text-xs text-fg-2">
                        {strings.header.state[course.state]} ·{' '}
                        {strings.header.enrolled(course.enrolled_count)} · {ruleText}
                    </p>
                </div>
                <div className={narrow ? 'flex flex-wrap gap-2' : 'ml-auto flex flex-wrap gap-2'}>
                    <a
                        className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        href={fillUrlTemplate(config.urls.preview, { slug: course.slug || slug })}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <Icon icon={ExternalLink} size={14} />
                        {strings.app.previewAsLearner}
                    </a>
                    <Button size="sm" onClick={() => setTab('publishing')}>
                        {studio.review}
                    </Button>
                    <a
                        className={buttonVariants({ variant: 'ghost', size: 'sm' })}
                        href={config.urls.courses}
                    >
                        {strings.app.backToCourses}
                    </a>
                </div>
            </div>
            <Tabs
                className="px-5 pt-2.5"
                label={strings.tabs.label}
                tabs={tabs}
                current={tab}
                onSelect={setTab}
            />
            <div className="bg-bg p-5">
                {tab === 'curriculum' && (
                    <TabPanel id="curriculum">
                        <CurriculumTab
                            courseId={courseId}
                            narrow={narrow}
                            onOpenQuiz={(quizId) => {
                                setPreferredQuizId(quizId);
                                setTab('quizzes');
                            }}
                        />
                    </TabPanel>
                )}
                {tab === 'quizzes' && (
                    <TabPanel id="quizzes">
                        <QuizzesTab courseId={courseId} narrow={narrow} initialQuizId={preferredQuizId} />
                    </TabPanel>
                )}
                {tab === 'gradebook' && (
                    <TabPanel id="gradebook">
                        <GradebookTab courseId={courseId} narrow={narrow} />
                    </TabPanel>
                )}
                {tab === 'learners' && (
                    <TabPanel id="learners">
                        <LearnersTab courseId={courseId} narrow={narrow} />
                    </TabPanel>
                )}
                {tab === 'reports' && reportsAvailable && (
                    <TabPanel id="reports">
                        <ReportsTab courseId={courseId} narrow={narrow} />
                    </TabPanel>
                )}
                {tab === 'cohorts' && cohortsAvailable && (
                    <TabPanel id="cohorts">
                        <CohortsTab courseId={courseId} narrow={narrow} />
                    </TabPanel>
                )}
                {tab === 'notifications' && (
                    <TabPanel id="notifications">
                        <NotificationsTab courseId={courseId} narrow={narrow} />
                    </TabPanel>
                )}
                {tab === 'publishing' && (
                    <TabPanel id="publishing">
                        <PublishingTab course={course} />
                    </TabPanel>
                )}
                {tab === 'settings' && (
                    <TabPanel id="settings">
                        <SettingsTab
                            course={course}
                            narrow={narrow}
                            onCertificateDirtyChange={setCertificateDirty}
                        />
                    </TabPanel>
                )}
            </div>
            <Dialog
                open={pendingTab !== null}
                onOpenChange={(open) => {
                    if (!open) setPendingTab(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{courseCertificateStrings.leaveTitle}</DialogTitle>
                    <DialogDescription>{courseCertificateStrings.leaveBody}</DialogDescription>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setPendingTab(null)}>
                            {courseCertificateStrings.cancel}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (pendingTab !== null) {
                                    setCertificateDirty(false);
                                    setActiveTab(pendingTab);
                                    setRouteTab(pendingTab);
                                    setPendingTab(null);
                                }
                            }}
                        >
                            {courseCertificateStrings.leave}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
