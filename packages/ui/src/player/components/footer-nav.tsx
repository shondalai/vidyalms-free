import type { ReactNode } from 'react';
import { Button } from '@/shared/components';
import { playerStrings } from '../strings';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { buttonVariants } from '@/shared/components';

/*
 * The lesson footer bar (artboards 1a, t2): Previous on the left, the
 * per-type hint in the middle-right, and the primary action (Continue, or
 * the quiz pane's Start/Resume) on the right.
 */
export interface FooterNavProps {
    onPrev: (() => void) | null;
    onNext: (() => void) | null;
    nextLabel?: string;
    hint?: string | null;
    /** Replaces the Continue button entirely (the quiz Start/Resume link). */
    action?: ReactNode;
    nextTitle?: string;
    returnUrl?: string;
}

export function FooterNav({ onPrev, onNext, nextLabel, hint, action, nextTitle, returnUrl }: FooterNavProps) {
    return (
        <footer className="vy-lesson-footer">
            {onPrev !== null && (
                <Button variant="outline" onClick={onPrev}>
                    <ArrowLeft size={16} aria-hidden="true" /> {playerStrings.nav.previous}
                </Button>
            )}
            <div className="vy-lesson-footer-note">
                {nextTitle && <strong>{nextTitle}</strong>}
                {hint !== undefined && hint !== null && <span>{hint}</span>}
            </div>
            {action ??
                (onNext !== null && (
                    <Button onClick={onNext}>
                        {nextLabel ?? playerStrings.nav.continue} <ArrowRight size={16} aria-hidden="true" />
                    </Button>
                ))}
            {!action && onNext === null && returnUrl && <a className={buttonVariants({variant: 'outline'})} href={returnUrl}>{playerStrings.states.notEnrolled.backToMyLearning}</a>}
        </footer>
    );
}
