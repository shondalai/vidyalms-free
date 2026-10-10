import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    ProblemError,
    useCourse,
    useCurriculum,
    useMyCertificates,
    useMyProgress,
} from '@/shared/api';
import type { SaveState } from '@/shared/components';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    EmptyState,
    SessionExpired,
    VisuallyHidden,
    buttonVariants,
} from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { useUnlockWatch, type UnlockCandidate } from '@/shared/availability';
import { useMountConfig } from '@/shared/context';
import { useSessionExpired } from '@/shared/session';
import { playerStrings } from './strings';
import { courseRuleText, flattenCurriculum, resolveInitialLessonId, type AssignmentStage } from './lib';
import type { HeartbeatEngine } from './heartbeat';
import { CourseHeader } from './components/course-header';
import { CurriculumRail } from './components/curriculum-rail';
import { LessonPane } from './components/lesson-pane';
import {
    CourseComplete,
    CourseError,
    NotEnrolled,
    OfflineBanner,
    PlayerSkeleton,
    Suspended,
} from './components/player-states';

/*
 * The learner player (artboard 02, direction 1a): the curriculum rail on
 * the left, the lesson pane on the right, the course header above both.
 * Under 900px of MOUNT width (never viewport width; the app is embedded)
 * the rail collapses into the Course map drawer. All whole-panel states of
 * artboard t3 render inside the same panel frame.
 */

/** The rail collapses to a drawer below this mount width (artboard 1a). */
const NARROW_BELOW_PX = 900;

function useContainerWidth(ref: React.RefObject<HTMLElement | null>): number {
    const [width, setWidth] = useState(Number.POSITIVE_INFINITY);
    useEffect(() => {
        const el = ref.current;
        if (el === null || typeof ResizeObserver === 'undefined') {
            return;
        }
        const observer = new ResizeObserver((entries) => {
            const rect = entries[0]?.contentRect;
            if (rect !== undefined && rect.width > 0) {
                setWidth(rect.width);
            }
        });
        observer.observe(el);
        return () => observer.disconnect();
    }, [ref]);
    return width;
}

interface SaveView {
    state: SaveState;
    savedAt: number | null;
    retry: () => void;
    offline: boolean;
}

const idleSave: SaveView = { state: 'idle', savedAt: null, retry: () => undefined, offline: false };

export function PlayerApp() {
    const config = useMountConfig();
    const announce = useAnnounce();
    const courseQuery = useCourse(config.course.slug);
    const curriculumQuery = useCurriculum(config.course.id);
    const curriculum = curriculumQuery.data ?? null;
    /* A guest has no progress and /me answers 401 by design; probing it from
       a free-preview visit would only trip error handling for nothing. The
       same is true for a signed-in author previewing without an enrolment;
       wait for the curriculum answer before enabling the progress request. */
    const guest = config.user.id <= 0;
    const progressQuery = useMyProgress(config.course.id, {
        enabled: !guest && curriculum?.enrolment != null,
    });

    const rootRef = useRef<HTMLDivElement>(null);
    const width = useContainerWidth(rootRef);
    const narrow = width < NARROW_BELOW_PX;

    const [selectedId, setSelectedId] = useState<number | null>(config.lessonId);
    const [mapOpen, setMapOpen] = useState(false);
    const [focused, setFocused] = useState(false);
    const [saveView, setSaveView] = useState<SaveView>(idleSave);
    const [suspended, setSuspended] = useState(false);
    const [reviewing, setReviewing] = useState(false);
    /* Assignments known to await grading (lifted by the open lesson pane);
       drives the rail's dashed awaiting-grade ring. */
    const [awaitingGradeIds, setAwaitingGradeIds] = useState<ReadonlySet<number>>(new Set());
    const sessionExpired = useSessionExpired();

    const course = courseQuery.data ?? null;
    const progress = progressQuery.data ?? null;

    const flat = useMemo(() => (curriculum !== null ? flattenCurriculum(curriculum) : []), [curriculum]);
    const enrolment = curriculum?.enrolment ?? null;
    const firstFreePreviewId = useMemo(
        () => flat.find((entry) => entry.lesson.free_preview)?.lesson.id ?? null,
        [flat],
    );
    const hasFreePreview = firstFreePreviewId !== null;

    /* Boot: honour the deep link, otherwise resume where the learner was. */
    useEffect(() => {
        if (curriculum === null) {
            return;
        }
        setSelectedId((current) => {
            if (current !== null && flat.some((entry) => entry.lesson.id === current)) {
                return current;
            }
            /* A public player URL has no lesson id. Without an enrolment it
               must open the first lesson the course deliberately exposed,
               not an earlier paid lesson that the lesson route will refuse. */
            if (enrolment === null && config.lessonId === null && firstFreePreviewId !== null) {
                return firstFreePreviewId;
            }
            return resolveInitialLessonId(curriculum, config.lessonId);
        });
    }, [curriculum, enrolment, firstFreePreviewId, flat, config.lessonId]);

    const enrolmentStatus = progress?.enrolment_status ?? enrolment?.status ?? null;

    /* The completion celebration: announce the transition when it happens
       while the learner is here; the celebration state itself renders for
       any completed enrolment until dismissed into review. */
    const previousStatusRef = useRef<string | null>(null);
    useEffect(() => {
        if (enrolmentStatus === null) {
            return;
        }
        if (previousStatusRef.current !== null && previousStatusRef.current !== 'completed' && enrolmentStatus === 'completed') {
            announce(playerStrings.announce.courseComplete, 'assertive');
            setReviewing(false);
        }
        previousStatusRef.current = enrolmentStatus;
    }, [enrolmentStatus, announce]);

    /*
     * An unlock that passes while the page is open is announced politely and
     * never navigates (SC 3.2.5, and the bundle's own rule against surprise
     * navigation). The passed instant is a hint and not the answer: a leaf
     * can name an instant while the node above it names none, so the server
     * is asked again and the refreshed outline decides what the row says.
     */
    const unlockCandidates = useMemo<UnlockCandidate[]>(
        () =>
            flat.map((entry) => ({
                id: entry.lesson.id,
                title: entry.lesson.title,
                access: entry.lesson.access,
            })),
        [flat],
    );
    const onUnlocked = useCallback(
        (item: UnlockCandidate) => {
            announce(playerStrings.announce.unlocked(item.title));
            void curriculumQuery.refetch();
        },
        [announce, curriculumQuery],
    );
    useUnlockWatch(unlockCandidates, onUnlocked);

    const showCelebration = enrolmentStatus === 'completed' && !reviewing;
    const certificatesQuery = useMyCertificates(showCelebration, { course_id: config.course.id, per_page: 25 });
    const certificate = useMemo(
        () =>
            (certificatesQuery.data?.items ?? []).find(
                (candidate) => candidate.course_id === config.course.id && candidate.revoked_at === null,
            ) ?? null,
        [certificatesQuery.data, config.course.id],
    );

    const onEngine = useCallback((engine: HeartbeatEngine) => {
        setSaveView({
            state: engine.offline ? 'offline' : engine.phase,
            savedAt: engine.savedAt,
            retry: engine.retryNow,
            offline: engine.offline,
        });
        if (engine.suspended) {
            setSuspended(true);
        }
    }, []);
    const onSuspended = useCallback(() => setSuspended(true), []);
    const onAssignmentStage = useCallback((lessonId: number, stage: AssignmentStage) => {
        setAwaitingGradeIds((current) => {
            const awaiting = stage === 'awaiting';
            if (current.has(lessonId) === awaiting) {
                return current;
            }
            const next = new Set(current);
            if (awaiting) {
                next.add(lessonId);
            } else {
                next.delete(lessonId);
            }
            return next;
        });
    }, []);

    const retryBoot = useCallback(() => {
        void courseQuery.refetch();
        void curriculumQuery.refetch();
        void progressQuery.refetch();
    }, [courseQuery, curriculumQuery, progressQuery]);

    /* One labelled region landmark for the whole app: the host page owns
       main; a second main inside the mount would break the host's outline. */
    const panelClass = 'vy-focused-player font-body text-fg';

    /* ── Boot states ────────────────────────────────────────────────── */

    /* An expired host token outranks every other presentation: it is not
       an offline problem and not an enrolment problem. */
    if (sessionExpired) {
        return (
            <div ref={rootRef} role="region" aria-label={playerStrings.app.region} className={panelClass}>
                <SessionExpired />
            </div>
        );
    }

    if (courseQuery.isPending || curriculumQuery.isPending) {
        return (
            <div ref={rootRef} role="region" aria-label={playerStrings.app.region} className={panelClass}>
                <PlayerSkeleton narrow={narrow} />
            </div>
        );
    }

    if (courseQuery.isError || curriculumQuery.isError) {
        const error = courseQuery.error ?? curriculumQuery.error;
        const status = error instanceof ProblemError ? error.status : 0;
        return (
            <div ref={rootRef} role="region" aria-label={playerStrings.app.region} className={panelClass}>
                {status === 401 || status === 403 ? (
                    <NotEnrolled courseUrl={config.urls.course} myLearningUrl={config.urls.myLearning} />
                ) : (
                    <CourseError error={error} onRetry={retryBoot} myLearningUrl={config.urls.myLearning} />
                )}
            </div>
        );
    }

    if (course === null || curriculum === null) {
        return null;
    }

    /* Free preview is intentionally a no-enrolment player mode. The lesson
       endpoint still withholds every non-preview body, while LessonPane's
       null enrolment keeps heartbeats and completion writes disabled. */
    if ((enrolment === null && !hasFreePreview) || enrolmentStatus === 'cancelled') {
        return (
            <div ref={rootRef} role="region" aria-label={playerStrings.app.region} className={panelClass}>
                <NotEnrolled courseUrl={config.urls.course} myLearningUrl={config.urls.myLearning} />
            </div>
        );
    }

    const lessonsTotal = progress?.summary?.lessons_total ?? flat.length;
    const lessonsDone =
        progress?.summary?.lessons_completed ??
        flat.filter((entry) => entry.lesson.progress?.status === 'completed').length;
    const percent = progress?.summary?.percent ?? enrolment?.progress_percent ?? 0;

    if (suspended || enrolmentStatus === 'suspended') {
        return (
            <div ref={rootRef} role="region" aria-label={playerStrings.app.region} className={panelClass}>
                <Suspended
                    courseTitle={course.title}
                    percent={percent}
                    lessonsDone={progress?.summary?.lessons_completed ?? lessonsDone}
                    lessonsTotal={progress?.summary?.lessons_total ?? lessonsTotal}
                    renewUrl={course.purchase_url ?? config.urls.course}
                    courseUrl={config.urls.course}
                />
            </div>
        );
    }

    if (showCelebration) {
        return (
            <div ref={rootRef} role="region" aria-label={playerStrings.app.region} className={panelClass}>
                <CourseComplete
                    lessonsTotal={lessonsTotal}
                    certificate={certificate}
                    myLearningUrl={config.urls.myLearning}
                    onReview={() => setReviewing(true)}
                />
            </div>
        );
    }

    /* ── The player ─────────────────────────────────────────────────── */

    return (
        <div ref={rootRef} role="region" aria-label={playerStrings.app.region} className={panelClass}>
            {saveView.offline && <OfflineBanner />}
            <CourseHeader
                title={course.title}
                percent={percent}
                lessonsDone={lessonsDone}
                lessonsTotal={lessonsTotal}
                ruleText={courseRuleText(course)}
                saveState={saveView.state}
                savedAt={saveView.savedAt}
                onRetrySave={saveView.retry}
                narrow={narrow}
                onOpenMap={() => setMapOpen(true)}
                courseUrl={config.urls.course}
                focused={focused}
                onToggleFocus={() => setFocused((value) => !value)}
                someNotCounted={progress?.summary != null && flat.length > lessonsTotal}
            />
            {flat.length === 0 ? (
                <div className="p-6">
                    <EmptyState
                        title={playerStrings.rail.empty.title}
                        description={playerStrings.rail.empty.body}
                        action={
                            <a className={buttonVariants({ variant: 'outline', size: 'sm' })} href={config.urls.course}>
                                {playerStrings.rail.empty.action}
                            </a>
                        }
                    />
                </div>
            ) : (
                <div className="vy-player-columns" data-curriculum={!narrow && !focused ? 'visible' : 'hidden'}>
                    {!narrow && !focused && (
                        <CurriculumRail
                            className="vy-desktop-curriculum"
                            percent={percent}
                            modules={curriculum.modules}
                            currentLessonId={selectedId}
                            onSelect={setSelectedId}
                            awaitingGradeIds={awaitingGradeIds}
                            locale={config.locale}
                        />
                    )}
                    {selectedId !== null && (
                        <LessonPane
                            lessonId={selectedId}
                            flat={flat}
                            courseId={config.course.id}
                            enrolment={enrolment}
                            offline={saveView.offline}
                            onSelect={setSelectedId}
                            onSuspended={onSuspended}
                            onEngine={onEngine}
                            onAssignmentStage={onAssignmentStage}
                        />
                    )}
                </div>
            )}
            {narrow && (
                <Dialog open={mapOpen} onOpenChange={setMapOpen}>
                    <DialogContent className="vy-course-map-dialog">
                        <DialogTitle>{playerStrings.header.courseMap}</DialogTitle>
                        <VisuallyHidden>
                            <DialogDescription>{playerStrings.header.courseMapDescription}</DialogDescription>
                        </VisuallyHidden>
                        <div className="vy-course-map-scroll">
                            <CurriculumRail
                                percent={percent}
                                modules={curriculum.modules}
                                currentLessonId={selectedId}
                                awaitingGradeIds={awaitingGradeIds}
                                locale={config.locale}
                                onSelect={(lessonId) => {
                                    setSelectedId(lessonId);
                                    setMapOpen(false);
                                }}
                            />
                        </div>
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}
