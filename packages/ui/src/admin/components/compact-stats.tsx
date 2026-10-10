import type { ComponentProps, ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Icon } from '@/shared/components';

export type CompactStatTone = 'neutral' | 'accent' | 'positive' | 'warning' | 'danger';

export interface CompactStat {
    /** Stable key when labels may be repeated or translated. */
    id?: string;
    label: string;
    value: ReactNode;
    /** A short comparison or scope, such as “this quarter”. */
    detail?: ReactNode;
    icon?: LucideIcon;
    tone?: CompactStatTone;
}

export interface CompactStatsProps extends Omit<ComponentProps<'ul'>, 'children'> {
    items: readonly CompactStat[];
    /** Accessible name for this collection of summary metrics. */
    label?: string;
    columns?: 2 | 3 | 4;
}

const columnClasses: Record<NonNullable<CompactStatsProps['columns']>, string> = {
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
    4: 'sm:grid-cols-2 xl:grid-cols-4',
};

const toneClasses: Record<CompactStatTone, string> = {
    neutral: 'bg-surface-2 text-fg-2',
    accent: 'bg-accent-soft text-accent',
    positive: 'bg-ok-soft text-ok-strong',
    warning: 'bg-warn-soft text-warn-strong',
    danger: 'bg-danger-soft text-danger-strong',
};

/** Compact summary cards designed to leave vertical space for the list below. */
export function CompactStats({ items, label = 'Summary', columns = 4, className, ...props }: CompactStatsProps) {
    return (
        <ul
            aria-label={label}
            className={cn('grid w-full grid-cols-1 gap-2.5', columnClasses[columns], className)}
            {...props}
        >
            {items.map((item, index) => {
                const tone = item.tone ?? 'neutral';
                return (
                    <li
                        key={item.id ?? `${item.label}-${index}`}
                        className="vy-metric-card flex min-w-0 items-center gap-3 rounded-lg border border-border bg-surface px-3.5 py-3 text-fg"
                    >
                        {item.icon !== undefined && (
                            <span
                                className={cn(
                                    'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded',
                                    toneClasses[tone],
                                )}
                            >
                                <Icon icon={item.icon} size={17} />
                            </span>
                        )}
                        <div className="min-w-0 flex-1">
                            <div className="flex min-w-0 flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                <span className="min-w-0 text-[11.5px] font-medium text-fg-2">{item.label}</span>
                                <strong className="font-display text-[19px] font-semibold leading-none tabular-nums text-fg">
                                    {item.value}
                                </strong>
                            </div>
                            {item.detail !== undefined && (
                                <div className="mt-1 text-[11px] leading-tight text-fg-2">{item.detail}</div>
                            )}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
