import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Icon } from '@/shared/components';
import { initials, parseApiDate } from '../lib';
import { displayValue } from '../learners/workspace';
import { orgWorkspaceStrings as s } from './workspace-strings';

export function OrgAvatar({ name, id, large = false }: { name: string; id: number; large?: boolean }) {
    return (
        <span
            aria-hidden="true"
            className={`vy-org-avatar vy-org-tone-${id % 4}${large ? ' vy-org-avatar-large' : ''}`}
        >
            {initials(name)}
        </span>
    );
}

export function OrgStats({
    items,
}: {
    items: { label: string; value: ReactNode; icon: LucideIcon; detail?: string }[];
}) {
    return (
        <dl className="vy-org-stats">
            {items.map(({ label, value, icon, detail }) => (
                <div key={label}>
                    <Icon icon={icon} size={22} />
                    <dd>{value}</dd>
                    <dt>{label}</dt>
                    {detail && <small>{detail}</small>}
                </div>
            ))}
        </dl>
    );
}

export function OrgPanel({
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
        <section className="vy-org-panel" aria-label={title}>
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

export function OrgIntro({
    title,
    description,
    action,
}: {
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <header className="vy-org-intro">
            <div>
                <h2>{title}</h2>
                <p>{description}</p>
            </div>
            {action}
        </header>
    );
}

export function OrgSegments({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <div role="group" aria-label={label} className="vy-org-segments">
            {options.map((option) => (
                <button
                    type="button"
                    key={option.value}
                    aria-pressed={option.value === value}
                    onClick={() => onChange(option.value)}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

export function orgType(value: string, unresolved = false) {
    return unresolved ? s.missingType : displayValue(value);
}
export function orgDate(value: string | null | undefined) {
    const date = value ? parseApiDate(value) : null;
    return date
        ? new Intl.DateTimeFormat(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              timeZone: 'UTC',
          }).format(date)
        : s.unset;
}
