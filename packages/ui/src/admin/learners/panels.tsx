import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import type { EnrolmentRecord } from '@vidyalms/api-sdk';
import { ProblemError } from '@vidyalms/api-sdk';
import { Activity, Award, BookOpen, Clock, Download, FileCheck, ShieldCheck } from 'lucide-react';
import {
    Badge,
    Button,
    DataTable,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    EmptyState,
    Icon,
    ProgressBar,
    Skeleton,
} from '@/shared/components';
import { useAdminConfig } from '../config';
import { formatDate, roundPercent } from '../lib';
import { ScreenError } from '../components/states';
import {
    useAccessEvidence,
    useLearnerActivity,
    useLearnerCurriculum,
    useLearnerGradeItems,
    useLearnerGrades,
    useLearningProgress,
} from './api';
import { learnerWorkspaceStrings as s } from './strings';
import { displayValue, downloadFile, LearnerFilter, LearnerPanel, LearnerSummary } from './workspace';

export function CourseChoice({
    enrolments,
    value,
    onChange,
    all = false,
}: {
    enrolments: EnrolmentRecord[];
    value: string;
    onChange: (value: string) => void;
    all?: boolean;
}) {
    return (
        <LearnerFilter
            label={s.course}
            value={value}
            onValueChange={onChange}
            options={[
                { value: '', label: all ? s.allCourses : s.chooseCourse },
                ...enrolments.map((row) => ({ value: String(row.course.id), label: row.course.title })),
            ]}
        />
    );
}

export function ProgressDialog({
    userId,
    enrolment,
    onClose,
}: {
    userId: number;
    enrolment: EnrolmentRecord;
    onClose: () => void;
}) {
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className="vy-learner-detail-dialog">
                <DialogTitle>{enrolment.course.title}</DialogTitle>
                <DialogDescription>{s.progressDescription}</DialogDescription>
                <CourseProgress userId={userId} courseId={enrolment.course.id} />
            </DialogContent>
        </Dialog>
    );
}

function CourseProgress({ userId, courseId }: { userId: number; courseId: number }) {
    const config = useAdminConfig();
    const progress = useLearningProgress(userId, courseId);
    const curriculum = useLearnerCurriculum(courseId);
    if (progress.isPending) return <Skeleton className="h-40 w-full" />;
    if (progress.isError)
        return <ScreenError error={progress.error} onRetry={() => void progress.refetch()} />;
    const rows = progress.data.lessons;
    const lessons = curriculum.data?.modules.flatMap((module) => module.lessons) ?? [];
    const summary = progress.data.summary;
    return (
        <div className="vy-learner-panel-content">
            <LearnerSummary
                label={s.progressDetail}
                items={[
                    {
                        label: s.lessonsCompleted,
                        value: summary ? `${summary.lessons_completed} / ${summary.lessons_total}` : '-',
                        icon: BookOpen,
                    },
                    {
                        label: s.average,
                        value: `${roundPercent(summary?.percent ?? progress.data.enrolment.progress_percent) ?? 0}%`,
                        icon: Activity,
                    },
                    {
                        label: uiText("vidyalms.ui.60e64253bdbeeaca", "Recorded learning time"),
                        value: s.minutes(rows.reduce((sum, row) => sum + row.seconds_spent, 0)),
                        icon: Clock,
                    },
                ]}
            />
            {curriculum.isError && (
                <ScreenError error={curriculum.error} onRetry={() => void curriculum.refetch()} />
            )}
            <DataTable
                caption={uiText('vidyalms.ui.learnerLessonProgress', 'Lesson progress')}
                rows={rows}
                rowKey={(row) => row.lesson_id}
                columns={[
                    {
                        key: 'lesson',
                        header: uiText('vidyalms.ui.learnerLesson', 'Lesson'),
                        cell: (row) =>
                            lessons.find((lesson) => lesson.id === row.lesson_id)?.title ??
                            s.lesson(row.lesson_id),
                    },
                    {
                        key: 'progress',
                        header: uiText('vidyalms.ui.learnerProgress', 'Progress'),
                        minWidth: 145,
                        cell: (row) => (
                            <ProgressBar
                                value={roundPercent(row.progress_percent) ?? 0}
                                label={s.lesson(row.lesson_id)}
                                showValue
                            />
                        ),
                    },
                    {
                        key: 'status',
                        header: uiText('vidyalms.ui.learnerStatus', 'Status'),
                        cell: (row) => (
                            <Badge variant={row.status === 'completed' ? 'ok' : 'neutral'}>
                                {displayValue(row.status)}
                            </Badge>
                        ),
                    },
                    { key: 'time', header: uiText('vidyalms.ui.learnerTime', 'Time'), cell: (row) => s.minutes(row.seconds_spent) },
                    {
                        key: 'completed',
                        header: s.completedOn,
                        cell: (row) => formatDate(row.completed_at, config.locale) ?? '-',
                    },
                ]}
                empty={<EmptyState title={s.noLessons} />}
            />
        </div>
    );
}

export function AssessmentsPanel({ userId, enrolments }: { userId: number; enrolments: EnrolmentRecord[] }) {
    const [courseId, setCourseId] = useState(String(enrolments[0]?.course.id ?? ''));
    return (
        <LearnerPanel
            title={s.assessmentTitle}
            description={s.assessmentDescription}
            action={<CourseChoice enrolments={enrolments} value={courseId} onChange={setCourseId} />}
        >
            {courseId ? (
                <GradeResults key={courseId} userId={userId} courseId={Number(courseId)} />
            ) : (
                <EmptyState title={s.noCourseSelection} />
            )}
        </LearnerPanel>
    );
}

function gradeValue(value: string | null | undefined, maximum: string | null | undefined) {
    if (value == null) return s.notGraded;
    return maximum == null ? value : `${value} / ${maximum}`;
}

function GradeResults({ userId, courseId }: { userId: number; courseId: number }) {
    const config = useAdminConfig();
    const grades = useLearnerGrades(userId, courseId);
    const items = useLearnerGradeItems(courseId);
    const rows = grades.data?.pages.flatMap((page) => page.cells) ?? [];
    const total = grades.data?.pages[0]?.totals.find((row) => row.user_id === userId);
    return (
        <div className="vy-learner-panel-content">
            {grades.isPending && <Skeleton className="h-36 w-full" />}
            {grades.isError && <ScreenError error={grades.error} onRetry={() => void grades.refetch()} />}
            {items.isError && <ScreenError error={items.error} onRetry={() => void items.refetch()} />}
            {grades.isSuccess && (
                <>
                    <LearnerSummary
                        label={s.assessmentTitle}
                        items={[
                            {
                                label: s.currentGrade,
                                value:
                                    total?.aggregation_status === 'unknown'
                                        ? s.unavailable
                                        : gradeValue(total?.current, total?.grade_max),
                                detail: s.currentGradeHint,
                                icon: Award,
                            },
                            {
                                label: s.finalGrade,
                                value:
                                    total?.aggregation_status === 'unknown'
                                        ? s.unavailable
                                        : gradeValue(total?.final, total?.final_grade_max),
                                detail: s.finalGradeHint,
                                icon: FileCheck,
                            },
                        ]}
                    />
                    {total?.aggregation_status === 'unknown' && <p role="status">{s.totalsUnavailable}</p>}
                    <DataTable
                        caption={s.assessmentTitle}
                        rows={rows}
                        rowKey={(row) => row.id}
                        columns={[
                            {
                                key: 'item',
                                header: uiText('vidyalms.ui.learnerAssessmentItem', 'Assessment / grade item'),
                                minWidth: 200,
                                cell: (row) => (
                                    <div className="vy-learner-cell">
                                        <strong>
                                            {items.data?.items.find((item) => item.id === row.grade_item_id)
                                                ?.title ?? s.gradeItem(row.grade_item_id)}
                                        </strong>
                                        <small>
                                            {displayValue(row.activity_progress)}
                                            {row.excluded ? uiText("vidyalms.ui.26624ae55fe256af", " · Excluded") : ''}
                                            {row.hidden ? uiText("vidyalms.ui.db4806c1e27dbae6", " · Hidden from learner") : ''}
                                        </small>
                                    </div>
                                ),
                            },
                            {
                                key: 'grade',
                                header: s.grade,
                                cell: (row) => gradeValue(row.raw_grade, row.raw_grade_max),
                            },
                            {
                                key: 'state',
                                header: s.grading,
                                cell: (row) => (
                                    <Badge
                                        variant={row.grading_progress === 'FullyGraded' ? 'ok' : 'neutral'}
                                    >
                                        {displayValue(row.grading_progress)}
                                    </Badge>
                                ),
                            },
                            { key: 'feedback', header: s.feedback, cell: (row) => row.feedback || '-' },
                            {
                                key: 'date',
                                header: uiText('vidyalms.ui.learnerGraded', 'Graded'),
                                cell: (row) => formatDate(row.graded_at, config.locale) ?? '-',
                            },
                        ]}
                        empty={<EmptyState title={s.noGrades} />}
                    />
                </>
            )}
            {grades.hasNextPage && (
                <Button
                    variant="outline"
                    disabled={grades.isFetchingNextPage}
                    onClick={() => void grades.fetchNextPage()}
                >
                    {grades.isFetchingNextPage ? s.loading : s.loadMore}
                </Button>
            )}
        </div>
    );
}

export function ActivityPanel({ userId, enrolments }: { userId: number; enrolments: EnrolmentRecord[] }) {
    const [courseId, setCourseId] = useState('');
    return (
        <LearnerPanel
            title={s.timeline}
            description={s.timelineDescription}
            action={<CourseChoice enrolments={enrolments} value={courseId} onChange={setCourseId} all />}
        >
            <ActivityFeed
                key={courseId}
                userId={userId}
                courseId={courseId ? Number(courseId) : undefined}
                enrolments={enrolments}
            />
        </LearnerPanel>
    );
}

function ActivityFeed({
    userId,
    courseId,
    enrolments,
}: {
    userId: number;
    courseId?: number;
    enrolments: EnrolmentRecord[];
}) {
    const config = useAdminConfig();
    const activity = useLearnerActivity(userId, courseId);
    const rows = activity.data?.pages.flatMap((page) => page.orderedItems) ?? [];
    return (
        <div className="vy-learner-panel-content">
            {activity.isPending && <Skeleton className="h-36 w-full" />}
            {activity.isError &&
                (activity.error instanceof ProblemError && activity.error.status === 403 ? (
                    <EmptyState title={s.activityRestricted} description={s.activityRestrictedHint} />
                ) : (
                    <ScreenError error={activity.error} onRetry={() => void activity.refetch()} />
                ))}
            {activity.isSuccess && !rows.length && <EmptyState title={s.noActivity} />}
            <ol className="vy-learner-timeline">
                {rows.map((row) => (
                    <li key={row.id}>
                        <span className="vy-learner-event-icon">
                            <Icon icon={Activity} size={17} />
                        </span>
                        <div>
                            <strong>{displayValue(row.type.replace(/^vidyalms[._]/i, ''))}</strong>
                            <p>
                                {enrolments.find((item) => item.course.id === Number(row.target?.id))?.course
                                    .title ??
                                    (row.target?.id
                                        ? `Course #${String(row.target.id)}`
                                        : 'Learning activity')}
                                {row.object?.id
                                    ? uiText("vidyalms.ui.cad5368e88d078f3", " · {p0} #{p1}", { p0: displayValue(String(row.object.type ?? 'item')), p1: String(row.object.id) })
                                    : ''}
                            </p>
                            {Object.keys(row.context).length > 0 && (
                                <details>
                                    <summary>{s.activityDetails}</summary>
                                    <dl className="vy-learner-event-details">
                                        {Object.entries(row.context).map(([key, value]) => (
                                            <div key={key}>
                                                <dt>{displayValue(key)}</dt>
                                                <dd>
                                                    {typeof value === 'object'
                                                        ? JSON.stringify(value)
                                                        : String(value ?? '-')}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </details>
                            )}
                        </div>
                        <time dateTime={row.published}>
                            {formatDate(row.published, config.locale)}
                            <small>
                                {new Date(row.published).toLocaleTimeString(config.locale, {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                    timeZone: 'UTC',
                                })}
                            </small>
                        </time>
                    </li>
                ))}
            </ol>
            {activity.hasNextPage && (
                <Button
                    variant="outline"
                    disabled={activity.isFetchingNextPage}
                    onClick={() => void activity.fetchNextPage()}
                >
                    {activity.isFetchingNextPage ? s.loading : s.loadMore}
                </Button>
            )}
        </div>
    );
}

export function EvidenceDialog({
    userId,
    enrolment,
    onClose,
}: {
    userId: number;
    enrolment: EnrolmentRecord;
    onClose: () => void;
}) {
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className="vy-learner-detail-dialog">
                <DialogTitle>{s.evidenceTitle}</DialogTitle>
                <DialogDescription>{enrolment.course.title}</DialogDescription>
                <Evidence userId={userId} courseId={enrolment.course.id} />
            </DialogContent>
        </Dialog>
    );
}

function Evidence({ userId, courseId }: { userId: number; courseId: number }) {
    const config = useAdminConfig();
    const evidence = useAccessEvidence(userId, courseId);
    if (evidence.isPending) return <Skeleton className="h-36 w-full" />;
    if (evidence.isError)
        return <ScreenError error={evidence.error} onRetry={() => void evidence.refetch()} />;
    return (
        <div className="vy-learner-panel-content">
            <p>{s.evidenceDescription}</p>
            <LearnerSummary
                label={s.evidenceTitle}
                items={[
                    { label: s.accessTitle, value: evidence.data.entitlements.length, icon: ShieldCheck },
                    { label: s.lessonsCompleted, value: evidence.data.lessons_completed, icon: BookOpen },
                    { label: s.attempts, value: evidence.data.attempts, icon: FileCheck },
                ]}
            />
            <p className="text-xs text-fg-2">
                {s.collected}: {formatDate(evidence.data.collected_at, config.locale)}
            </p>
            {evidence.data.warnings.length > 0 && (
                <ul className="vy-learner-evidence-warnings">
                    {evidence.data.warnings.map((warning, index) => (
                        <li key={index}>{warning}</li>
                    ))}
                </ul>
            )}
            <Button
                variant="outline"
                onClick={() =>
                    downloadFile(
                        JSON.stringify(evidence.data, null, 2),
                        `learner-${userId}-course-${courseId}-evidence.json`,
                        'application/json',
                    )
                }
            >
                <Icon icon={Download} size={15} />
                {s.downloadEvidence}
            </Button>
        </div>
    );
}
