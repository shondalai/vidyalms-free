import { sharedStrings } from '../strings';
import { Button } from './button';

/*
 * The one state an expired host token renders, shared by both apps: never
 * the offline banner (the network is fine) and never the enrolment
 * messaging (the learner is enrolled). One action: reload the page, which
 * sends the learner through the host's sign-in and back here.
 */
export function SessionExpired() {
    return (
        <div role="alert" className="px-8 py-11 text-center" data-testid="session-expired">
            <span
                aria-hidden="true"
                className="mb-3 inline-flex h-11 w-11 items-center justify-center rounded-full bg-surface-2 text-lg text-fg-2"
            >
                ◷
            </span>
            <p className="mb-1.5 font-display text-[15px] font-bold text-fg">
                {sharedStrings.session.expiredTitle}
            </p>
            <p className="mx-auto mb-4 max-w-[46ch] text-[13px] text-fg-2">{sharedStrings.session.expiredBody}</p>
            <Button onClick={() => window.location.reload()}>{sharedStrings.session.reload}</Button>
        </div>
    );
}
