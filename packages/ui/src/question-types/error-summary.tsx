import { useEffect, useRef } from 'react';
import { moveFocus } from '@/shared/a11y';
import { isCoreErrorKind, type QuestionError } from './contract';
import { shellStrings as strings } from './strings';

/*
 * Errors, built once in the shell.
 *
 * Types contribute errors through getErrors(), each carrying a control id, a
 * kind and the message, and render no error UI of their own. On a failed
 * submit the runner renders this: role=alert at the top of the region above
 * the heading, an h2 title, one link per error targeting the offending
 * control with wording identical to the inline message, focus moved here,
 * and the document title prefixed with "Error: " (SC 3.3.1, 3.3.2, 3.3.3).
 *
 * A composite's per-part errors carry partId, so the one shared summary
 * groups them rather than every part growing a summary of its own.
 *
 * A construct error is not an input error and never surfaces before
 * submission, which is SC 3.3.3's own purpose-of-content clause and exactly
 * the invariant that correctness never leaves the server.
 */

export interface ErrorSummaryGroup {
    /** What the reader is told this group is: "Question 4 of 12". */
    label: string;
    /** Jumps the shell to the screen holding the control before focusing it. */
    onNavigate?: () => void;
    errors: readonly QuestionError[];
}

export interface ErrorSummaryProps {
    groups: readonly ErrorSummaryGroup[];
    title?: string;
    className?: string;
}

/** Anything the platform will focus without being given a tabindex. */
const FOCUSABLE = 'a[href], button, input, select, textarea, [tabindex]';

/*
 * A summary link lands on the control that failed, never on the sentence
 * describing the failure.
 *
 * A type reports the id of whatever names its error, and three of them
 * reported the id of the InlineError paragraph the primitives render. That
 * id resolves, so the summary focused a paragraph sitting after the control,
 * and the learner had to shift-tab back to fix the thing they had just been
 * sent to. The paragraph is not a dead end though: the control that failed
 * points at it through aria-describedby, so the association already exists
 * and is followed here rather than being re-declared in every family.
 */
function focusControl(controlId: string): void {
    const target = document.getElementById(controlId);

    if (target !== null && target.matches(FOCUSABLE)) {
        moveFocus(target);
        target.scrollIntoView({ block: 'center', behavior: 'auto' });
        return;
    }

    const described = document.querySelector<HTMLElement>(`[aria-describedby~="${CSS.escape(controlId)}"]`);

    if (described !== null) {
        moveFocus(described);
        described.scrollIntoView({ block: 'center', behavior: 'auto' });
        return;
    }

    /* A container the type gave the reported id to, which is the selection
       family's ErrorTarget: focusable once moveFocus gives it a tabindex. */
    if (target !== null) {
        moveFocus(target);
        target.scrollIntoView({ block: 'center', behavior: 'auto' });
    }
}

export function ErrorSummary({ groups, title, className }: ErrorSummaryProps) {
    const ref = useRef<HTMLDivElement>(null);
    const total = groups.reduce((sum, group) => sum + group.errors.length, 0);

    useEffect(() => {
        if (total === 0) {
            return;
        }
        moveFocus(ref.current);
        const previous = document.title;
        if (!previous.startsWith(strings.errorTitlePrefix)) {
            document.title = `${strings.errorTitlePrefix}${previous}`;
        }
        return () => {
            document.title = previous;
        };
    }, [total]);

    if (total === 0) {
        return null;
    }

    return (
        <div
            ref={ref}
            role="alert"
            data-vy-shell="error-summary"
            tabIndex={-1}
            className={
                className ??
                'rounded border-2 border-danger bg-surface px-4 py-3.5 text-[13px] leading-[1.55] text-fg'
            }
        >
            <h2 className="font-display text-[14px] font-bold text-danger">{title ?? strings.errorSummaryTitle}</h2>
            <ul className="mt-2 grid gap-1.5">
                {groups.flatMap((group) =>
                    group.errors.map((error) => {
                        const partLabel = error.partId === undefined ? '' : ` ${strings.partLabel(error.partId)}`;
                        return (
                            <li key={`${group.label}:${error.controlId}:${error.kind}`}>
                                <a
                                    href={`#${error.controlId}`}
                                    data-vy-error-kind={isCoreErrorKind(error.kind) ? error.kind : 'CONTRIBUTED'}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        group.onNavigate?.();
                                        /* After a screen change the control
                                           only exists on the next frame. */
                                        window.setTimeout(() => focusControl(error.controlId), 0);
                                    }}
                                    className="font-medium text-danger underline"
                                >
                                    {group.label}
                                    {partLabel}: {error.message}
                                </a>
                            </li>
                        );
                    }),
                )}
            </ul>
        </div>
    );
}
