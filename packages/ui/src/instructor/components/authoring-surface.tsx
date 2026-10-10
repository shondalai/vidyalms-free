import type { ReactNode } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/shared/components';

/** The same editor can be a full lesson panel or a standalone management dialog. */
export function AuthoringSurface({
    inline = false,
    title,
    description,
    onClose,
    className,
    children,
}: {
    inline?: boolean;
    title: string;
    description: string;
    onClose: () => void;
    className?: string;
    children: ReactNode;
}) {
    if (inline)
        return (
            <section className="vy-quiz-settings-surface" aria-label={title}>
                <header>
                    <h3>{title}</h3>
                    <p>{description}</p>
                </header>
                {children}
            </section>
        );
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className={className}>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                {children}
            </DialogContent>
        </Dialog>
    );
}
