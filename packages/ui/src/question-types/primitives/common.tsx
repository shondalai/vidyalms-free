import type { ReactNode } from 'react';
import { cn } from '@/shared/cn';
import type { Announcer } from '@/shared/a11y';
import type { QuestionShell } from '../contract';
import { primitiveStrings as strings } from '../strings';
import { linearPanelClass } from './styles';

/*
 * What every primitive is handed, and the two pieces of chrome they share.
 *
 * A primitive renders no live region and no error summary. It announces
 * through the shell's channel registry and it repeats an error message
 * inline, verbatim, beside the control the summary links to. That is the
 * whole reason the eight exist: a type that composes them inherits keyboard
 * operation, non-drag pointer operation, live region announcements and focus
 * management, and an audit can verify it in one place.
 */

/**
 * The three modes every primitive answers to. `rich` is the full
 * presentation, operable by pointer without dragging AND by keyboard.
 * `linear` is the parallel single-input presentation: a native select, a
 * position number, a coordinate field, the text list beside an image. It is
 * what a switch user, a screen reader user on a graphic surface and a
 * 320 pixel viewport all get, and it is never hidden behind a preference.
 */
export type Presentation = 'rich' | 'linear';

export interface PrimitiveProps {
    /** Builds DOM ids the shared error summary links to. */
    controlId: (local: string) => string;
    /** The id of the element naming this group; usually the question prompt. */
    labelledBy?: string;
    /** Used when there is no visible label to point at. */
    label?: string;
    announce: Announcer;
    shell?: Pick<QuestionShell, 'captureInteraction' | 'releaseInteraction'> | null;
    readOnly?: boolean;
    presentation?: Presentation;
    /**
     * Repeated verbatim from the module's getErrors() entry whose controlId
     * names this group, so the inline wording and the summary link wording
     * are the same string and not two that drift.
     */
    error?: string;
    className?: string;
}

export function InlineError({ id, message }: { id: string; message?: string }) {
    if (message === undefined || message === '') {
        return null;
    }
    return (
        <p id={id} className="mt-1.5 flex items-start gap-1.5 text-[12px] font-medium text-danger">
            <span aria-hidden="true">!</span>
            {message}
        </p>
    );
}

/** aria-describedby that stays undefined rather than pointing at nothing. */
export function describedBy(...ids: (string | undefined | false)[]): string | undefined {
    const kept = ids.filter((id): id is string => typeof id === 'string' && id !== '');
    return kept.length > 0 ? kept.join(' ') : undefined;
}

/**
 * The panel a spatial primitive always renders beside its canvas. Small
 * hotspot regions qualify under SC 2.5.8 Equivalent precisely because this
 * ships with the type: one component, four criteria.
 */
export function LinearAlternative({
    id,
    title,
    children,
    className,
}: {
    id?: string;
    title?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div id={id} className={cn(linearPanelClass, className)}>
            <div className="mb-2 font-display text-[11px] font-semibold uppercase tracking-[0.04em] text-fg-2">
                {title ?? strings.linearListTitle}
            </div>
            {children}
        </div>
    );
}
