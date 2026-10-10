import type { ComponentProps } from 'react';
import { ProblemError } from '@vidyalms/api-sdk';
import { cn } from '../cn';
import { sharedStrings } from '../strings';

/*
 * Foundations artboard, piece 12, error banner form: hairline box with a
 * 3px danger edge, bold claim, factual detail, inline "Try again". Inline
 * and quiet; no toast, no modal.
 */
export interface ErrorStateProps extends ComponentProps<'div'> {
    /** Whatever was caught. ProblemError renders its own detail. */
    error?: unknown;
    /** Overrides the bold claim; defaults to "Couldn't load." */
    title?: string;
    onRetry?: () => void;
}

function detailOf(error: unknown): string {
    if (error instanceof ProblemError) {
        if (error.problem.detail !== undefined && error.problem.detail !== '') {
            return error.problem.detail;
        }
        switch (error.status) {
            case 401:
                return sharedStrings.errors.signInRequired;
            case 403:
                return sharedStrings.errors.notAllowed;
            case 404:
                return sharedStrings.errors.notFound;
            case 409:
                return sharedStrings.errors.conflict;
            case 422:
                return sharedStrings.errors.validation;
            default:
                return error.problem.title;
        }
    }
    if (error instanceof TypeError) {
        /* fetch network failure */
        return sharedStrings.errors.noResponse;
    }
    return sharedStrings.errors.somethingWrong;
}

export function ErrorState({ error, title, onRetry, className, ...props }: ErrorStateProps) {
    return (
        <div
            role="alert"
            className={cn(
                'flex flex-wrap items-baseline gap-x-2.5 gap-y-1 rounded border border-border border-l-[3px] border-l-danger bg-surface px-3.5 py-2.5 text-[12.5px]',
                className,
            )}
            {...props}
        >
            <strong className="shrink-0 font-semibold text-danger">
                {title ?? sharedStrings.errors.couldNotLoad}
            </strong>
            <span className="text-fg-2">{detailOf(error)}</span>
            {onRetry && (
                <button
                    type="button"
                    onClick={onRetry}
                    className="cursor-pointer font-semibold text-accent underline-offset-2 hover:underline"
                >
                    {sharedStrings.retry}
                </button>
            )}
        </div>
    );
}
