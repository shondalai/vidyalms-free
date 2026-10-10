import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ArrowLeft, BookOpen, ChevronDown, Eye, Focus, Layers3 } from 'lucide-react';
import {
    Badge,
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
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import { useSessionExpired } from '@/shared/session';
import { ProblemError } from '@/shared/api';
import { useAuthoringCurriculum, useManagedCourse } from './api';
import { fillUrlTemplate, hasRoute, useInstructorConfig } from './config';
import { Tabs, TabPanel } from './components/tabs';
import { PermissionDenied, NotFoundState, WorkbenchSkeleton } from './components/states';
import { useContainerWidth, useHashTab } from './lib/layout';
import { CurriculumTab } from './tabs/curriculum-tab';
import {
    CourseFields,
    CourseGrantsPanel,
    InstructorsPanel,
    PrerequisitesPanel,
    PublishingTab,
    SequencingPanel,
} from './tabs/settings-tab';
import { SubjectRestrictions } from './tabs/availability/subject-restrictions';
import { CourseCertificatePolicyPanel } from './tabs/course-certificate-panel';
import { GradebookTab } from './tabs/gradebook-tab';
import { CohortsTab } from './tabs/cohorts-tab';
import { builderStrings as b } from './builder-strings';
import { instructorStrings as strings } from './strings';
import { studioStrings as studio } from './studio-strings';

const ROUTES = [
    'curriculum',
    'details',
    'access',
    'assessment',
    'publishing',
    'gradebook',
    'cohorts',
] as const;
type BuilderRoute = (typeof ROUTES)[number];
type AccessSection = keyof typeof b.accessSections;
type AssessmentSection = keyof typeof b.assessmentSections;

function Intro({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="vy-builder-section-intro">
            <h3>{title}</h3>
            <p>{children}</p>
        </div>
    );
}

function SectionNavigation<T extends string>({
    label,
    items,
    current,
    onChange,
}: {
    label: string;
    items: readonly (readonly [T, readonly [string, string]])[];
    current: T;
    onChange: (id: T) => void;
}) {
    return (
        <nav className="vy-builder-section-nav" aria-label={label}>
            {items.map(([id, [title, description]]) => (
                <button
                    type="button"
                    key={id}
                    aria-current={current === id ? 'page' : undefined}
                    onClick={() => onChange(id)}
                >
                    <strong>{title}</strong>
                    <small>{description}</small>
                </button>
            ))}
        </nav>
    );
}

/** Administrator authoring workspace. Delivery tools do not occupy authoring tabs. */
export function CourseBuilder({ courseId }: { courseId: number }) {
    const config = useInstructorConfig();
    const query = useManagedCourse(courseId);
    const curriculum = useAuthoringCurriculum(courseId);
    const sessionExpired = useSessionExpired();
    const root = useRef<HTMLDivElement>(null);
    const narrow = useContainerWidth(root, query.isSuccess) < 760;
    const [route, setRoute] = useHashTab<BuilderRoute>(ROUTES, 'curriculum');
    const cohortsAvailable = hasRoute(config, '/manage/courses/{id}/cohorts');
    const availableRoute = route === 'cohorts' && !cohortsAvailable ? 'curriculum' : route;
    const [tab, setTab] = useState(availableRoute);
    const [focus, setFocus] = useState(false);
    const [access, setAccess] = useState<AccessSection>('visibility');
    const [assessment, setAssessment] = useState<AssessmentSection>('completion');
    const [certificateDirty, setCertificateDirty] = useState(false);
    const [pending, setPending] = useState<(() => void) | null>(null);
    const request = (action: () => void) => {
        if (certificateDirty || hasUnsavedWorkspaceChanges()) setPending(() => action);
        else action();
    };
    const select = (next: BuilderRoute) => {
        if (tab === next) return;
        request(() => {
            setTab(next);
            setRoute(next);
        });
    };
    useEffect(() => {
        if (route !== availableRoute) setRoute(availableRoute);
        if (availableRoute === tab) return;
        if (certificateDirty || hasUnsavedWorkspaceChanges()) {
            setPending(() => () => {
                setTab(availableRoute);
                setRoute(availableRoute);
            });
            setRoute(tab);
        } else setTab(availableRoute);
    }, [route, availableRoute, tab, certificateDirty, setRoute]);
    // Unsupported management hashes open the authoring landing page and receive its canonical URL.
    const canonicalized = useRef(false);
    useEffect(() => {
        if (canonicalized.current) return;
        canonicalized.current = true;
        setRoute(availableRoute);
    }, [availableRoute, setRoute]);

    if (sessionExpired) return <SessionExpired />;
    if (query.isPending) return <WorkbenchSkeleton narrow={narrow} />;
    if (query.isError) {
        const status = query.error instanceof ProblemError ? query.error.status : 0;
        if (status === 403) return <PermissionDenied />;
        if (status === 404) return <NotFoundState />;
        return <ErrorState error={query.error} onRetry={() => void query.refetch()} />;
    }
    const course = query.data;
    const modules = (curriculum.data?.modules ?? []).filter((module) => module.state !== 'archived');
    const lessons = modules.flatMap((module) =>
        module.lessons.filter((lesson) => lesson.state !== 'archived'),
    );
    const minutes = Math.ceil(
        lessons.reduce((total, lesson) => total + (lesson.duration_seconds ?? 0), 0) / 60,
    );
    const operations = tab === 'gradebook' || tab === 'cohorts';
    const tabs = Object.entries(b.tabs).map(([id, label]) => ({ id: id as BuilderRoute, label }));
    const accessItems = Object.entries(b.accessSections) as [AccessSection, readonly [string, string]][];
    const assessmentItems = (
        Object.entries(b.assessmentSections) as [AssessmentSection, readonly [string, string]][]
    ).filter(([id]) => id !== 'certificates' || hasRoute(config, '/manage/courses/{id}/certificate-policy'));

    return (
        <div ref={root} role="region" aria-label={b.region} className="vy-course-builder" data-focus={focus}>
            <a className="vy-builder-back" href={config.urls.courses}>
                <Icon icon={ArrowLeft} size={15} />
                {b.back}
            </a>
            <header className="vy-builder-header">
                <div>
                    <p className="vy-studio-eyebrow">{studio.eyebrow}</p>
                    <h1>{course.title}</h1>
                </div>
                <div className="vy-builder-header-actions">
                    <Badge variant={course.state === 'published' ? 'ok' : 'neutral'}>
                        {strings.header.state[course.state]}
                    </Badge>
                    <Button variant="ghost" size="sm" aria-pressed={focus} onClick={() => setFocus(!focus)}>
                        <Icon icon={Focus} size={16} />
                        {b.focus}
                    </Button>
                    <a
                        className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        href={fillUrlTemplate(config.urls.preview, { slug: course.slug })}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <Icon icon={Eye} size={15} />
                        {b.preview}
                    </a>
                    <Button size="sm" onClick={() => select('publishing')}>
                        {studio.review}
                    </Button>
                </div>
            </header>
            <div className="vy-builder-meta">
                <span>
                    <Icon icon={Layers3} size={15} />
                    {curriculum.isSuccess ? b.moduleCount(modules.length) : studio.outline}
                </span>
                <span>
                    <Icon icon={BookOpen} size={15} />
                    {b.lessonCount(course.lesson_count)}
                </span>
                {minutes > 0 && <span>{b.learningTime(minutes)}</span>}
                <details className="vy-builder-tools">
                    <summary>
                        {b.tools}
                        <Icon icon={ChevronDown} size={14} />
                    </summary>
                    <div onClick={(event) => event.currentTarget.closest('details')?.removeAttribute('open')}>
                        <small>{b.toolsHint}</small>
                        <button type="button" onClick={() => select('gradebook')}>
                            {b.gradebook}
                            {course.pending_grading_count > 0 && <span>{course.pending_grading_count}</span>}
                        </button>
                        {cohortsAvailable && (
                            <button type="button" onClick={() => select('cohorts')}>
                                {b.cohorts}
                            </button>
                        )}
                        {config.urls.notifications && (
                            <a href={config.urls.notifications}>{b.notifications}</a>
                        )}
                    </div>
                </details>
            </div>
            {!operations && (
                <Tabs
                    label={b.sections}
                    tabs={tabs}
                    current={tab}
                    onSelect={select}
                    className="vy-builder-tabs"
                />
            )}
            {operations && (
                <div className="vy-builder-operation-heading">
                    <Button variant="outline" onClick={() => select('curriculum')}>
                        <Icon icon={ArrowLeft} size={15} />
                        {b.backToBuilder}
                    </Button>
                    <h2>{b[tab]}</h2>
                </div>
            )}
            <div className="vy-builder-content">
                {tab === 'curriculum' && (
                    <TabPanel id="curriculum">
                        <CurriculumTab courseId={courseId} narrow={narrow} studioMode />
                    </TabPanel>
                )}
                {tab === 'details' && (
                    <TabPanel id="details">
                        <Intro title={b.detailsTitle}>{b.detailsIntro}</Intro>
                        <CourseFields course={course} mode="details" />
                        <div className="vy-builder-team">
                            <Intro title={b.teamTitle}>{b.teamHint}</Intro>
                            <InstructorsPanel course={course} />
                        </div>
                    </TabPanel>
                )}
                {tab === 'access' && (
                    <TabPanel id="access">
                        <Intro title={b.accessTitle}>{b.accessIntro}</Intro>
                        <div className="vy-builder-settings-layout">
                            <SectionNavigation
                                label={b.tabs.access}
                                items={accessItems}
                                current={access}
                                onChange={(next) => request(() => setAccess(next))}
                            />
                            <div className="vy-builder-setting-content" key={access}>
                                {access === 'visibility' && (
                                    <>
                                        <CourseFields course={course} mode="access" />
                                        <div className="vy-builder-note">
                                            <strong>
                                                {b.currentEnrolment}: {course.enrolment_rule}
                                            </strong>
                                            <p>{b.providerPolicy}</p>
                                        </div>
                                    </>
                                )}
                                {access === 'prerequisites' && <PrerequisitesPanel course={course} />}
                                {access === 'release' && (
                                    <>
                                        <CourseFields course={course} mode="schedule" />
                                        <SequencingPanel course={course} />
                                    </>
                                )}
                                {access === 'restrictions' && (
                                    <SubjectRestrictions
                                        subjectType="course"
                                        subjectId={courseId}
                                        courseId={courseId}
                                    />
                                )}
                                {access === 'exceptions' && <CourseGrantsPanel courseId={courseId} />}
                            </div>
                        </div>
                    </TabPanel>
                )}
                {tab === 'assessment' && (
                    <TabPanel id="assessment">
                        <Intro title={b.assessmentTitle}>{b.assessmentIntro}</Intro>
                        <SectionNavigation
                            label={b.tabs.assessment}
                            items={assessmentItems}
                            current={assessment}
                            onChange={(next) => request(() => setAssessment(next))}
                        />
                        <div className="vy-builder-assessment-panel" key={assessment}>
                            {assessment === 'completion' && (
                                <CourseFields course={course} mode="completion" />
                            )}
                            {assessment === 'certificates' && (
                                <CourseCertificatePolicyPanel
                                    courseId={courseId}
                                    onDirtyChange={setCertificateDirty}
                                />
                            )}
                        </div>
                    </TabPanel>
                )}
                {tab === 'publishing' && (
                    <TabPanel id="publishing">
                        <Intro title={b.publishingTitle}>{b.publishingIntro}</Intro>
                        <PublishingTab course={course} />
                    </TabPanel>
                )}
                {tab === 'gradebook' && <GradebookTab courseId={courseId} narrow={narrow} />}
                {tab === 'cohorts' && cohortsAvailable && <CohortsTab courseId={courseId} narrow={narrow} />}
            </div>
            <Dialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) setPending(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{b.unsavedTitle}</DialogTitle>
                    <DialogDescription>{b.unsavedBody}</DialogDescription>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setPending(null)}>
                            {strings.lesson.keepEditing}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setCertificateDirty(false);
                                pending?.();
                                setPending(null);
                            }}
                        >
                            {strings.lesson.discard}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
