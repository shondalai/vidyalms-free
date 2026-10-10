import { useRef, type ReactNode } from 'react';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import { PageHeader } from './page-header';

export function WorkspacePage({
    title,
    description,
    actions,
    children,
    className = '',
}: {
    title: ReactNode;
    description: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={`vy-modern-workspace ${className}`}>
            <PageHeader
                title={
                    <>
                        {title}
                        <span className="vy-workspace-dot">.</span>
                    </>
                }
                description={description}
                actions={actions}
            />
            {children}
        </div>
    );
}

export interface WorkspaceTab {
    key: string;
    label: string;
    description?: string;
    icon?: LucideIcon;
}

/** A reusable, keyboard-operable section selector. The screen owns routes and draft lifetime. */
export function WorkspaceTabs({
    label,
    tabs,
    current,
    onChange,
    vertical = false,
}: {
    label: string;
    tabs: readonly WorkspaceTab[];
    current: string;
    onChange: (key: string) => void;
    vertical?: boolean;
}) {
    const root = useRef<HTMLDivElement>(null);
    return (
        <div
            ref={root}
            role="tablist"
            aria-label={label}
            aria-orientation={vertical ? 'vertical' : 'horizontal'}
            className={vertical ? 'vy-workspace-sections' : 'vy-workspace-tabs'}
        >
            {tabs.map((tab, index) => (
                <button
                    key={tab.key}
                    type="button"
                    role="tab"
                    aria-label={tab.label}
                    aria-selected={current === tab.key}
                    tabIndex={current === tab.key ? 0 : -1}
                    onClick={() => onChange(tab.key)}
                    onKeyDown={(event) => {
                        const nextKey = vertical ? 'ArrowDown' : 'ArrowRight';
                        const previousKey = vertical ? 'ArrowUp' : 'ArrowLeft';
                        if (![nextKey, previousKey, 'Home', 'End'].includes(event.key)) return;
                        event.preventDefault();
                        const nextIndex =
                            event.key === 'Home'
                                ? 0
                                : event.key === 'End'
                                  ? tabs.length - 1
                                  : (index + (event.key === nextKey ? 1 : -1) + tabs.length) % tabs.length;
                        const next = tabs[nextIndex];
                        if (next) {
                            onChange(next.key);
                            root.current?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[nextIndex]?.focus();
                        }
                    }}
                >
                    {tab.icon && <tab.icon size={18} />}
                    <span>
                        <strong>{tab.label}</strong>
                        {tab.description && <small>{tab.description}</small>}
                    </span>
                    {vertical && <ChevronRight size={15} />}
                </button>
            ))}
        </div>
    );
}

export function WorkspaceCallout({
    icon: Icon,
    title,
    children,
    action,
    tone = 'accent',
}: {
    icon: LucideIcon;
    title: ReactNode;
    children: ReactNode;
    action?: ReactNode;
    tone?: 'accent' | 'warning' | 'neutral';
}) {
    return (
        <section className={`vy-workspace-callout vy-workspace-callout-${tone}`}>
            <span className="vy-workspace-glyph">
                <Icon size={23} />
            </span>
            <div>
                <h2>{title}</h2>
                <p>{children}</p>
            </div>
            {action}
        </section>
    );
}

export function downloadWorkspaceJson(name: string, data: unknown) {
    const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = name;
    anchor.click();
    URL.revokeObjectURL(url);
}
