import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Icon } from '@/shared/components';
import { initials } from '../lib';
import { FilterSelect, type FilterSelectProps } from '../components/filter-toolbar';

export function LearnerAvatar({ name, id, large = false }: { name: string; id: number; large?: boolean }) {
    return (
        <span
            aria-hidden="true"
            className={`vy-learner-avatar vy-learner-tone-${id % 5}${large ? ' vy-learner-avatar-large' : ''}`}
        >
            {initials(name)}
        </span>
    );
}

export function LearnerSummary({
    label,
    items,
}: {
    label: string;
    items: { label: string; value: ReactNode; icon: LucideIcon; detail?: string }[];
}) {
    return (
        <dl className="vy-learner-summary" aria-label={label}>
            {items.map((item) => (
                <div key={item.label}>
                    <span className="vy-learner-summary-icon">
                        <Icon icon={item.icon} size={21} />
                    </span>
                    <dd>{item.value}</dd>
                    <dt>{item.label}</dt>
                    {item.detail && <small>{item.detail}</small>}
                </div>
            ))}
        </dl>
    );
}

export function LearnerFilter(props: FilterSelectProps) {
    return (
        <div className="vy-learner-filter">
            <span aria-hidden="true">{props.label}</span>
            <FilterSelect {...props} />
        </div>
    );
}

export function LearnerPanel({
    title,
    description,
    action,
    children,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="vy-learner-panel" aria-label={title}>
            <header>
                <div>
                    <h2>{title}</h2>
                    {description && <p>{description}</p>}
                </div>
                {action}
            </header>
            {children}
        </section>
    );
}

export function displayValue(value: string) {
    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[_.-]+/g, ' ')
        .replace(/^\w/, (c) => c.toUpperCase());
}

export function csvContent(rows: (string | number | null | undefined)[][]) {
    return (
        '\uFEFF' +
        rows
            .map((row) =>
                row
                    .map((value) => {
                        let text = String(value ?? '');
                        if (/^[\s]*[=+@-]/.test(text)) text = `'${text}`;
                        return `"${text.replaceAll('"', '""')}"`;
                    })
                    .join(','),
            )
            .join('\r\n')
    );
}

export function downloadFile(contents: string, filename: string, type = 'text/csv;charset=utf-8') {
    const url = URL.createObjectURL(new Blob([contents], { type }));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
