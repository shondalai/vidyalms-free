import { useId, type ComponentProps, type ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Icon } from '@/shared/components';

export interface PageHeaderProps extends Omit<ComponentProps<'header'>, 'title'> {
    /** The single, visible page heading. */
    title: ReactNode;
    /** Short context for the task on this page. */
    description?: ReactNode;
    /** Optional product-area label shown above the heading. */
    eyebrow?: ReactNode;
    /** Decorative icon that helps distinguish adjacent admin areas. */
    icon?: LucideIcon;
    /** Status badges or other compact context associated with the page. */
    meta?: ReactNode;
    /** Primary and secondary page-level actions. */
    actions?: ReactNode;
    /** Use h2 only when this header is nested below an existing page h1. */
    headingLevel?: 'h1' | 'h2';
}

/**
 * Fluid admin page heading. It owns no outer width or margin, so the host CMS
 * and the VidyaLMS shell remain free to define the available canvas.
 */
export function PageHeader({
    title,
    description,
    eyebrow,
    icon,
    meta,
    actions,
    headingLevel: Heading = 'h1',
    className,
    ...props
}: PageHeaderProps) {
    const titleId = useId();

    return (
        <header
            aria-labelledby={titleId}
            className={cn('flex w-full min-w-0 flex-col gap-4 sm:flex-row sm:items-start sm:justify-between', className)}
            {...props}
        >
            <div className="flex min-w-0 items-start gap-3">
                {icon !== undefined && (
                    <span className="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent">
                        <Icon icon={icon} size={19} />
                    </span>
                )}
                <div className="min-w-0">
                    {eyebrow !== undefined && (
                        <div className="mb-1 font-display text-[11px] font-semibold uppercase tracking-[0.08em] text-accent">
                            {eyebrow}
                        </div>
                    )}
                    <Heading id={titleId} className="vy-page-heading font-display text-[24px] font-semibold leading-[1.2] tracking-[-0.02em] text-fg">
                        {title}
                    </Heading>
                    {description !== undefined && (
                        <p className="mt-1.5 max-w-[72ch] text-[13px] leading-[1.55] text-fg-2">{description}</p>
                    )}
                    {meta !== undefined && <div className="mt-2 flex flex-wrap items-center gap-2">{meta}</div>}
                </div>
            </div>
            {actions !== undefined && (
                <div className="flex min-h-10 shrink-0 flex-wrap items-center gap-2 sm:justify-end">{actions}</div>
            )}
        </header>
    );
}
