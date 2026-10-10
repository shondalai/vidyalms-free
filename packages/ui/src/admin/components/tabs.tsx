import { translate as uiText } from '@/shared/i18n';
import { useRef, type KeyboardEvent, type ReactNode } from 'react';
import { cn } from '@/shared/cn';

/*
 * Foundations artboard, piece 9: underline tabs, arrow-key operable. The
 * ARIA tabs pattern with a roving tabindex, one stop for the whole strip,
 * arrows between tabs, Home and End to the ends, and the panel labelled by
 * its tab.
 *
 * The admin app's own, exactly as its field controls are its own: the
 * instructor workbench's copy belongs to the instructor entry and pulling it
 * across would put one app's module in the other's chunk for a hundred lines
 * of markup.
 */

export interface AdminTabDefinition<T extends string> {
    id: T;
    label: string;
    /** A count beside the label. */
    badge?: number;
    /** What the badge means, for people who cannot see it next to the label. */
    badgeLabel?: string;
}

export interface AdminTabsProps<T extends string> {
    label: string;
    tabs: readonly AdminTabDefinition<T>[];
    current: T;
    onSelect: (id: T) => void;
    className?: string;
}

export function adminTabPanelId(id: string): string {
    return `vy-admin-tabpanel-${id}`;
}

function adminTabId(id: string): string {
    return `vy-admin-tab-${id}`;
}

export function AdminTabs<T extends string>({ label, tabs, current, onSelect, className }: AdminTabsProps<T>) {
    const buttonRefs = useRef(new Map<string, HTMLButtonElement | null>());

    const focusTab = (index: number) => {
        const wrapped = (index + tabs.length) % tabs.length;
        const target = tabs[wrapped];
        if (target === undefined) {
            return;
        }
        onSelect(target.id);
        buttonRefs.current.get(target.id)?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const index = tabs.findIndex((tab) => tab.id === current);
        switch (event.key) {
            case 'ArrowRight':
                event.preventDefault();
                focusTab(index + 1);
                break;
            case 'ArrowLeft':
                event.preventDefault();
                focusTab(index - 1);
                break;
            case 'Home':
                event.preventDefault();
                focusTab(0);
                break;
            case 'End':
                event.preventDefault();
                focusTab(tabs.length - 1);
                break;
            default:
                break;
        }
    };

    return (
        <div
            role="tablist"
            aria-label={label}
            onKeyDown={onKeyDown}
            className={cn('flex flex-wrap gap-0.5 border-b border-border', className)}
        >
            {tabs.map((tab) => {
                const active = tab.id === current;
                return (
                    <button
                        key={tab.id}
                        type="button"
                        role="tab"
                        id={adminTabId(tab.id)}
                        ref={(el) => {
                            buttonRefs.current.set(tab.id, el);
                        }}
                        aria-selected={active}
                        /* Only the selected panel is mounted, so only the
                           selected tab points at one: a dangling
                           aria-controls is a broken promise to a screen
                           reader. */
                        aria-controls={active ? adminTabPanelId(tab.id) : undefined}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onSelect(tab.id)}
                        className={cn(
                            '-mb-px cursor-pointer border-b-2 px-3.5 py-2.5 text-[13px]',
                            active
                                ? 'border-accent font-semibold text-fg'
                                : 'border-transparent font-medium text-fg-2 hover:text-fg',
                        )}
                    >
                        {tab.label}
                        {tab.badge !== undefined && tab.badge > 0 && (
                            <>
                                <span
                                    aria-hidden="true"
                                    className="ml-1.5 rounded-full bg-accent-soft px-1.5 py-px text-[10.5px] font-bold text-accent"
                                >
                                    {tab.badge}
                                </span>
                                <span className="sr-only">
                                    {tab.badgeLabel !== undefined ? uiText("vidyalms.ui.8d40a097f137cfe1", ", {p0}", { p0: tab.badgeLabel }) : uiText("vidyalms.ui.8d40a097f137cfe1", ", {p0}", { p0: tab.badge })}
                                </span>
                            </>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

export function AdminTabPanel({
    id,
    labelledBy,
    children,
}: {
    id: string;
    labelledBy?: string;
    children: ReactNode;
}) {
    return (
        <div
            role="tabpanel"
            id={adminTabPanelId(id)}
            aria-labelledby={labelledBy ?? adminTabId(id)}
            tabIndex={-1}
            className="w-full min-w-0 max-w-full"
        >
            {children}
        </div>
    );
}
