import type {
    CourseDetail,
    Curriculum,
    CurriculumLesson,
    CurriculumModule,
    LessonQuizSummary,
    LessonView,
} from '@/shared/api';
import type { MountConfig } from '@/shared/config';
import { playerStrings } from './strings';

/** One lesson with its place in the course: "Lesson 2.3" needs both numbers. */
export interface FlatLesson {
    lesson: CurriculumLesson;
    module: CurriculumModule;
    /** 1-based order of the module as rendered, not the stored position. */
    moduleNumber: number;
    /** 1-based order of the lesson inside its module. */
    lessonNumber: number;
}

export function flattenCurriculum(curriculum: Curriculum): FlatLesson[] {
    const flat: FlatLesson[] = [];
    curriculum.modules.forEach((module, moduleIndex) => {
        module.lessons.forEach((lesson, lessonIndex) => {
            flat.push({
                lesson,
                module,
                moduleNumber: moduleIndex + 1,
                lessonNumber: lessonIndex + 1,
            });
        });
    });
    return flat;
}

export function findFlatLesson(flat: FlatLesson[], lessonId: number): FlatLesson | null {
    return flat.find((entry) => entry.lesson.id === lessonId) ?? null;
}

/**
 * Where the player opens: the deep link when it points at a real lesson,
 * otherwise resume at the first in-progress lesson, otherwise the first
 * incomplete lesson, otherwise the first lesson of the course.
 */
export function resolveInitialLessonId(curriculum: Curriculum, deepLink: number | null): number | null {
    const flat = flattenCurriculum(curriculum);
    if (flat.length === 0) {
        return null;
    }
    if (deepLink !== null && flat.some((entry) => entry.lesson.id === deepLink)) {
        return deepLink;
    }
    const inProgress = flat.find((entry) => entry.lesson.progress?.status === 'in_progress');
    if (inProgress !== undefined) {
        return inProgress.lesson.id;
    }
    const incomplete = flat.find((entry) => entry.lesson.progress?.status !== 'completed');
    if (incomplete !== undefined) {
        return incomplete.lesson.id;
    }
    return flat[0]?.lesson.id ?? null;
}

export function neighbours(
    flat: FlatLesson[],
    lessonId: number,
): { prev: FlatLesson | null; next: FlatLesson | null } {
    const index = flat.findIndex((entry) => entry.lesson.id === lessonId);
    if (index < 0) {
        return { prev: null, next: null };
    }
    return {
        prev: index > 0 ? (flat[index - 1] ?? null) : null,
        next: index < flat.length - 1 ? (flat[index + 1] ?? null) : null,
    };
}

/** "8 min" for most types, "6 min read" for readings; null without a duration. */
export function formatDuration(seconds: number | null, type: string): string | null {
    if (seconds === null || seconds <= 0) {
        return null;
    }
    const minutes = Math.max(1, Math.round(seconds / 60));
    return type === 'reading' ? playerStrings.meta.minutesRead(minutes) : playerStrings.meta.minutes(minutes);
}

/** The course completion rule, phrased for the header meta line. */
export function courseRuleText(course: CourseDetail): string {
    switch (course.completion_rule) {
        case 'percentage':
            return playerStrings.header.rule.percentage(course.completion_threshold ?? 100);
        case 'pass_final':
            return playerStrings.header.rule.passFinal;
        case 'all_lessons':
        default:
            return playerStrings.header.rule.allLessons;
    }
}

/**
 * The per-type completion sentence of the lesson meta line: every pane
 * states its rule in the same spot, so "done" is never a mystery.
 */
export function lessonCompletionText(type: string, quiz: LessonQuizSummary | null): string | null {
    switch (type) {
        case 'reading':
            return playerStrings.meta.completes.reading;
        case 'video':
            return playerStrings.meta.completes.video;
        case 'resource':
            return playerStrings.meta.completes.resource;
        case 'assignment':
            return playerStrings.meta.completes.assignment;
        case 'quiz':
            return quiz !== null && quiz.pass_mark > 0
                ? playerStrings.meta.completes.quizPass(quiz.pass_mark)
                : playerStrings.meta.completes.quizAny;
        default:
            return null;
    }
}

/** Short chip labels of the rail (artboard 1a): READ, VIDEO, FILE, QUIZ, TASK. */
export function railChipLabel(type: string): string {
    return playerStrings.chips.rail[type] ?? type.toUpperCase().slice(0, 6);
}

/** Full chip labels of the pane meta line (artboard t2). */
export function paneChipLabel(type: string): string {
    return type.toUpperCase().replace(/[^A-Z0-9]+/g, ' ').trim();
}

/** Quiz and assignment chips render in accent (they gate completion on people or passing). */
export function isAccentType(type: string): boolean {
    return type === 'quiz' || type === 'assignment';
}

/**
 * Embeddable player URL for the hosted providers; null when the address
 * does not parse (the pane then offers a plain link instead of an iframe).
 */
export function videoEmbedSrc(provider: string, url: string): string | null {
    try {
        const parsed = new URL(url);
        if (provider === 'youtube') {
            let id: string | null = null;
            if (parsed.hostname === 'youtu.be') {
                id = parsed.pathname.split('/')[1] ?? null;
            } else if (parsed.hostname.endsWith('youtube.com') || parsed.hostname.endsWith('youtube-nocookie.com')) {
                if (parsed.pathname === '/watch') {
                    id = parsed.searchParams.get('v');
                } else {
                    const match = parsed.pathname.match(/^\/(?:embed|shorts|live)\/([^/]+)/);
                    id = match?.[1] ?? null;
                }
            }
            if (id === null || !/^[\w-]{6,}$/.test(id)) {
                return null;
            }
            return `https://www.youtube-nocookie.com/embed/${id}`;
        }
        if (provider === 'vimeo') {
            if (!parsed.hostname.endsWith('vimeo.com')) {
                return null;
            }
            const match = parsed.pathname.match(/(\d{6,})/);
            if (match?.[1] === undefined) {
                return null;
            }
            return `https://player.vimeo.com/video/${match[1]}`;
        }
        return null;
    } catch {
        return null;
    }
}

export function formatBytes(bytes: number | null | undefined): string | null {
    if (typeof bytes !== 'number' || bytes <= 0) {
        return null;
    }
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** The dot-extension of a file name, uppercased for the file chip; "FILE" fallback. */
export function fileExt(name: string): string {
    const match = name.match(/\.([A-Za-z0-9]{1,5})$/);
    return match?.[1] !== undefined ? match[1].toUpperCase() : playerStrings.chips.fileFallback;
}

/**
 * The assignment lifecycle stage the learner contract can prove. Graded is
 * the lesson's own completed progress (grading completes the lesson through
 * a server-side listener). Between submission and grading the contract has
 * no learner read endpoint yet; when the lesson content carries a
 * submission entry (a forward-compatible enrichment), its status drives the
 * awaiting stage. Nothing is invented: without data the assignment is open.
 */
export type AssignmentStage = 'open' | 'awaiting' | 'graded';

export function assignmentStage(lesson: LessonView): AssignmentStage {
    if (lesson.progress?.status === 'completed') {
        return 'graded';
    }
    const submission = lesson.content?.submission;
    if (typeof submission === 'object' && submission !== null) {
        const status = (submission as Record<string, unknown>).status;
        if (status === 'submitted') {
            return 'awaiting';
        }
    }
    return 'open';
}

/**
 * The quiz runner handoff. The runner is its own mount on a host page; the
 * only host URL the fixed mount contract carries is urls.course, so the
 * handoff is that URL plus a vy-quiz parameter naming the quiz lesson. The
 * Mount phase adapter routes it: seeing vy-quiz it renders the quiz-runner
 * mount for that lesson instead of the player mount.
 */
export function quizRunnerHref(config: MountConfig, lessonId: number): string {
    const base = config.urls.course;
    const hashIndex = base.indexOf('#');
    const withoutHash = hashIndex >= 0 ? base.slice(0, hashIndex) : base;
    const hash = hashIndex >= 0 ? base.slice(hashIndex) : '';
    const separator = withoutHash.includes('?') ? '&' : '?';
    return `${withoutHash}${separator}vy-quiz=${encodeURIComponent(String(lessonId))}${hash}`;
}

export function clampPercent(value: number): number {
    if (Number.isNaN(value)) {
        return 0;
    }
    return Math.min(100, Math.max(0, value));
}
