import { Card } from './card';

/*
 * Artboard 3e, lower half: the permission card. A 401 or 403 on a learner
 * surface is the server's answer to "may this person see it", so it is
 * stated as a fact with one way forward, never as a failed load with a retry
 * that cannot succeed. The quiz runner and the player's lesson pane render
 * this one card with their own copy.
 */
export interface PermissionCardProps {
    /** The bold claim: what is not available. */
    title: string;
    /** Why, in one sentence. */
    body: string;
    /** The one way forward, usually the course page. */
    action: { href: string; label: string };
}

export function PermissionCard({ title, body, action }: PermissionCardProps) {
    return (
        <Card className="flex items-center gap-3 px-[22px] py-[18px] text-[13px] text-fg-2" data-testid="permission-card">
            <span
                aria-hidden="true"
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-2"
            >
                ◇
            </span>
            <span>
                <strong className="font-semibold text-fg">{title}</strong> {body}{' '}
                <a href={action.href} className="font-semibold text-accent">
                    {action.label}
                </a>
            </span>
        </Card>
    );
}
