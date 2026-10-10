import { Clock } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Icon, buttonVariants } from '@/shared/components';
import { accessLine, openingReading, reasonText, type AvailabilityAccess } from '@/shared/availability';
import { useMountConfig } from '@/shared/context';
import { playerStrings } from '../strings';

/*
 * How a blocked item reads.
 *
 * The design bundle bans padlocks twice and its four-state LessonState
 * component has no locked member, so nothing is reused from it and nothing
 * is invented: a blocked row follows the one treatment of withheld access
 * the artboards do specify, the suspended enrolment of 3e. State the fact,
 * say exactly what is kept, offer one forward action.
 *
 * Three accessibility rules are load-bearing rather than decorative. The row
 * keeps its title, stays focusable and is announced, and is never
 * aria-disabled or dropped from the tab order (SC 1.3.1, 2.1.1, 4.1.2). The
 * state is carried by a text label plus a shape and never by colour (SC
 * 1.4.1). And nothing here counts down or navigates when an instant passes.
 *
 * A hidden item never reaches this file: the contract omits it from the
 * array entirely rather than sending a flag, because a flag is eventually
 * rendered and the title itself is the leak.
 */

/** The neutral marker. Decorative: the row carries the words beside it. */
export function BlockedGlyph({ className }: { className?: string }) {
    return (
        <span
            aria-hidden="true"
            data-state="blocked"
            className={cn(
                'inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 border-dotted border-fg-2/60 text-fg-2',
                className,
            )}
        />
    );
}

/**
 * The one line under a blocked title in the rail, and under a blocked
 * module heading.
 */
export function AvailabilityNote({
    access,
    locale,
    className,
    id,
}: {
    access: AvailabilityAccess;
    locale: string;
    className?: string;
    id?: string;
}) {
    const opening = openingReading(access.opens_at, access.opens_at_timezone, locale);
    return (
        <span id={id} className={cn('block text-[11.5px] font-normal text-fg-2', className)}>
            {reasonText(access, locale)}
            {opening?.authoritative !== null && opening?.timezone !== null && opening !== null && (
                <span className="block text-[11px]">
                    {playerStrings.availability.authoritative(opening.authoritative, opening.timezone)}
                </span>
            )}
        </span>
    );
}

/**
 * The whole-pane state for a blocked lesson.
 *
 * It says what the learner keeps, because that is what the suspended state
 * says and a learner reading "not available" needs to know their work is not
 * the thing being withheld. One forward action, and it goes back to the
 * course rather than anywhere clever.
 */
export function BlockedPane({ access, locale }: { access: AvailabilityAccess; locale: string }) {
    const config = useMountConfig();
    const opening = openingReading(access.opens_at, access.opens_at_timezone, locale);
    return (
        <div className="rounded border border-border bg-bg px-5 py-4">
            <div className="flex items-start gap-3">
                <span
                    aria-hidden="true"
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-2 text-fg-2"
                >
                    <Icon icon={Clock} size={17} />
                </span>
                <div className="min-w-0">
                    <p className="font-display text-sm font-bold text-fg">{reasonText(access, locale)}</p>
                    {opening?.authoritative !== null && opening !== null && opening.timezone !== null && (
                        <p className="mt-0.5 text-[12px] text-fg-2">
                            {playerStrings.availability.authoritative(opening.authoritative, opening.timezone)}
                        </p>
                    )}
                    <p className="mt-1.5 text-[12.5px] text-fg-2">{playerStrings.availability.kept}</p>
                </div>
            </div>
            <div className="mt-3.5">
                <a className={buttonVariants({ variant: 'outline', size: 'sm' })} href={config.urls.course}>
                    {playerStrings.availability.backToCourse}
                </a>
            </div>
        </div>
    );
}

/** The full sentence a screen reader hears on a blocked row. */
export function blockedRowLabel(access: AvailabilityAccess, locale: string): string {
    return accessLine(access, locale);
}
