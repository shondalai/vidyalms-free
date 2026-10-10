import { cn } from '@/shared/cn';
import { instructorStrings } from '../strings';

/*
 * Foundations artboard, piece 3 (LessonType chip) in its authoring form:
 * a short mono kicker beside the title. The short labels below are display
 * shorthand for the types this bundle knows; a type from an add-on gets a
 * shorthand derived from its key, so the chip never goes blank and never
 * pretends to know a type it does not.
 *
 * Accent-tinted chips are the two types that complete through work (quiz,
 * assignment); the rest are neutral. The chip is never the only carrier of
 * meaning: the full type label is in the add menu and the edit dialog.
 */

const LESSON_SHORTHAND: Record<string, string> = {
    video: 'VIDEO',
    reading: 'READ',
    resource: 'FILE',
    assignment: 'TASK',
    quiz: 'QUIZ',
};

const QUESTION_SHORTHAND: Record<string, string> = {
    single_choice: 'SINGLE',
    multiple_choice: 'MULTI',
    true_false: 'TF',
    short_answer: 'SHORT',
    fill_blank: 'BLANK',
    ordering: 'ORDER',
};

const ACCENT_LESSON_TYPES = new Set(['quiz', 'assignment']);

function shorthandOf(map: Record<string, string>, key: string): string {
    return map[key] ?? key.replace(/[^A-Za-z0-9]+/g, ' ').trim().slice(0, 6).toUpperCase();
}

export function lessonShorthand(type: string): string {
    return shorthandOf(LESSON_SHORTHAND, type);
}

export function questionShorthand(type: string): string {
    return shorthandOf(QUESTION_SHORTHAND, type);
}

export function TypeChip({
    text,
    tone = 'neutral',
    className,
}: {
    text: string;
    tone?: 'neutral' | 'accent';
    className?: string;
}) {
    return (
        <span
            className={cn(
                'shrink-0 rounded-sm px-1.5 py-0.5 font-mono text-[9px] font-semibold leading-[1.6]',
                tone === 'accent' ? 'bg-accent-soft text-accent' : 'bg-surface-2 text-fg-2',
                className,
            )}
        >
            {text}
        </span>
    );
}

export function LessonTypeChip({ type, className }: { type: string; className?: string }) {
    return (
        <TypeChip
            text={lessonShorthand(type)}
            tone={ACCENT_LESSON_TYPES.has(type) ? 'accent' : 'neutral'}
            className={className}
        />
    );
}

export function QuestionTypeChip({ type, className }: { type: string; className?: string }) {
    return <TypeChip text={questionShorthand(type)} className={className} />;
}

const STATE_TEXT: Record<string, string> = {
    published: instructorStrings.curriculum.lessonState.published,
    draft: instructorStrings.curriculum.lessonState.draft,
    archived: instructorStrings.curriculum.lessonState.archived,
};

/**
 * The state as a word, never a colour alone. The prop is a plain string
 * because the states are the server's: a state this bundle has no word for
 * is shown as the server named it rather than as nothing.
 */
export function StateText({ state, className }: { state: string; className?: string }) {
    return (
        <span
            className={cn(
                'shrink-0 text-[11px] font-semibold',
                state === 'published' ? 'text-ok-strong' : 'text-fg-2',
                className,
            )}
        >
            {STATE_TEXT[state] ?? state}
        </span>
    );
}
