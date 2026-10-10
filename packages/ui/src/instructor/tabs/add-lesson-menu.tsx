import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { ChevronDown } from 'lucide-react';
import type { LessonTypeInfo } from '@vidyalms/api-sdk';
import { cn } from '@/shared/cn';
import { Icon } from '@/shared/components';
import { instructorStrings as strings } from '../strings';
import { LessonTypeChip } from '../components/chips';

/*
 * Artboard 1a, the add-lesson menu: every type GET /lesson-types answers,
 * with the completion mode beside it, because "how does this lesson
 * complete" is the question an author is really asking. The list is the
 * registry's, never a hardcoded five: a site with an add-on lists more from
 * the same route, and nothing here knows what free or Pro means.
 *
 * The ARIA menu-button pattern: Enter, Space or Down opens onto the first
 * item, arrows move, Escape closes back onto the trigger, and a click
 * outside dismisses.
 */

const COMPLETION_TEXT: Record<string, string> = {
    view: strings.curriculum.completion.view,
    pass: strings.curriculum.completion.pass,
    submission: strings.curriculum.completion.submission,
};

export function AddLessonMenu({
    types,
    moduleTitle,
    onSelect,
}: {
    types: LessonTypeInfo[];
    moduleTitle: string;
    onSelect: (type: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const itemRefs = useRef(new Map<string, HTMLButtonElement | null>());

    useEffect(() => {
        if (!open) {
            return;
        }
        const onPointerDown = (event: MouseEvent) => {
            const target = event.target;
            if (
                target instanceof Node &&
                menuRef.current?.contains(target) !== true &&
                triggerRef.current?.contains(target) !== true
            ) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    useEffect(() => {
        if (!open) {
            return;
        }
        const type = types[active];
        if (type !== undefined) {
            itemRefs.current.get(type.key)?.focus();
        }
    }, [open, active, types]);

    const close = (focusTrigger: boolean) => {
        setOpen(false);
        if (focusTrigger) {
            triggerRef.current?.focus();
        }
    };

    const onMenuKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close(true);
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((current) => (current + 1) % Math.max(1, types.length));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((current) => (current - 1 + types.length) % Math.max(1, types.length));
        } else if (event.key === 'Home') {
            event.preventDefault();
            setActive(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            setActive(Math.max(0, types.length - 1));
        }
    };

    return (
        <div className="relative inline-block">
            <button
                ref={triggerRef}
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                disabled={types.length === 0}
                onClick={() => {
                    setActive(0);
                    setOpen((current) => !current);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown' && !open) {
                        event.preventDefault();
                        setActive(0);
                        setOpen(true);
                    }
                }}
                className="flex h-8 cursor-pointer items-center gap-1.5 rounded border border-dashed border-fg-2/50 bg-surface px-3 font-display text-xs font-semibold text-fg-2 hover:text-fg disabled:cursor-default disabled:opacity-50"
            >
                <span className="sr-only">{strings.curriculum.addLessonTo(moduleTitle)}</span>
                <span aria-hidden="true">{strings.curriculum.addLesson}</span>
                <Icon icon={ChevronDown} size={13} />
            </button>
            {open && (
                <div
                    ref={menuRef}
                    role="menu"
                    aria-label={strings.curriculum.addLessonMenu}
                    onKeyDown={onMenuKeyDown}
                    className="absolute left-0 top-9 z-50 w-[280px] rounded-lg border border-border bg-surface p-1.5 shadow-[0_14px_34px_-14px_color-mix(in_srgb,var(--_vy-fg)_30%,transparent)]"
                >
                    {types.map((type, index) => (
                        <button
                            key={type.key}
                            type="button"
                            role="menuitem"
                            tabIndex={index === active ? 0 : -1}
                            ref={(el) => {
                                itemRefs.current.set(type.key, el);
                            }}
                            onFocus={() => setActive(index)}
                            onClick={() => {
                                close(false);
                                onSelect(type.key);
                            }}
                            className={cn(
                                'flex w-full cursor-pointer items-center gap-2.5 rounded px-2.5 py-2 text-left text-[12.5px]',
                                index === active ? 'bg-bg' : 'bg-transparent',
                            )}
                        >
                            <LessonTypeChip type={type.key} />
                            <span className="min-w-0 flex-1 truncate text-fg">{type.label}</span>
                            <span className="shrink-0 text-[11px] text-fg-2">
                                {COMPLETION_TEXT[type.completion_mode] ?? type.completion_mode}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
