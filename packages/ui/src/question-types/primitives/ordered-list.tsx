import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { ArrowDown, ArrowUp, GripVertical } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Icon } from '@/shared/components';
import { primitiveStrings as strings } from '../strings';
import { InlineError, describedBy, type PrimitiveProps } from './common';
import { buttonTarget, movableRowClass } from './styles';
import { useSelectThenPlace } from './select-then-place';

/*
 * OrderedList: the client counterpart of the order primitive.
 *
 * Three modes, all of them real:
 *
 *   pointer without drag  the move buttons, and select then place (activate
 *                         the grab toggle, then activate the row to put it
 *                         before)
 *   keyboard              Enter or Space to grab, arrows to move, Enter or
 *                         Space to drop, Escape to cancel, which restores
 *                         the order the list had when the grab started
 *   linear                a position number per item, the single-input
 *                         equivalent
 *
 * Focus stays on the grabbed item and never jumps to the target, and it
 * returns to the same logical item in its new position after a move. When a
 * move lands an item on an edge and disables the button that was pressed,
 * focus hops to that item's other move button, so the keyboard never falls
 * off the widget.
 *
 * Reorder announcements go to the log channel, which is incremental; the
 * grab and drop messages go to status.
 */

export interface OrderedItem {
    key: string;
    label: string;
}

export interface OrderedListProps extends PrimitiveProps {
    items: readonly OrderedItem[];
    /** The keys in the learner's order. */
    value: readonly string[];
    onChange: (order: string[]) => void;
}

function moveWithin(order: readonly string[], from: number, to: number): string[] {
    const next = [...order];
    const [key] = next.splice(from, 1);
    if (key === undefined) {
        return [...order];
    }
    next.splice(to, 0, key);
    return next;
}

export function OrderedList({
    items,
    value,
    onChange,
    controlId,
    labelledBy,
    label,
    announce,
    shell,
    readOnly = false,
    presentation = 'rich',
    error,
    className,
}: OrderedListProps) {
    const byKey = new Map(items.map((item) => [item.key, item] as const));
    const order = value.length === items.length && value.every((key) => byKey.has(key))
        ? [...value]
        : items.map((item) => item.key);
    const count = order.length;
    const errorId = controlId('error');

    const [lastMoved, setLastMoved] = useState<string | null>(null);
    const buttonRefs = useRef(new Map<string, HTMLButtonElement | null>());
    const pendingFocusRef = useRef<string | null>(null);
    const orderAtGrabRef = useRef<string[] | null>(null);
    const orderRef = useRef(order);
    orderRef.current = order;

    useEffect(() => {
        const pending = pendingFocusRef.current;
        if (pending === null) {
            return;
        }
        pendingFocusRef.current = null;
        buttonRefs.current.get(pending)?.focus();
    });

    const labelOf = (key: string) => byKey.get(key)?.label ?? key;

    const applyMove = (from: number, to: number, direction: 'up' | 'down' | null) => {
        if (to < 0 || to >= count || from === to) {
            return;
        }
        const key = orderRef.current[from];
        if (key === undefined) {
            return;
        }
        const next = moveWithin(orderRef.current, from, to);
        onChange(next);
        setLastMoved(key);
        if (direction === 'up' && to === 0) {
            pendingFocusRef.current = `${key}:down`;
        } else if (direction === 'down' && to === count - 1) {
            pendingFocusRef.current = `${key}:up`;
        }
        announce('log', strings.reordered(labelOf(key), to + 1, count));
    };

    const place = useSelectThenPlace({
        announce,
        shell,
        onPlace: (heldId, targetId) => {
            const from = orderRef.current.indexOf(heldId);
            const to = orderRef.current.indexOf(targetId);
            if (from < 0 || to < 0) {
                return false;
            }
            applyMove(from, to, null);
            return true;
        },
        onCancel: () => {
            const restored = orderAtGrabRef.current;
            orderAtGrabRef.current = null;
            if (restored !== null) {
                onChange(restored);
            }
        },
    });

    const onGrabKeyDown = (event: KeyboardEvent<HTMLButtonElement>, key: string) => {
        if (!place.isHeld(key)) {
            return;
        }
        const from = orderRef.current.indexOf(key);
        if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
            event.preventDefault();
            applyMove(from, from - 1, null);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
            event.preventDefault();
            applyMove(from, from + 1, null);
        } else if (event.key === 'Enter' || event.key === ' ') {
            /* Drop where it stands: the click handler toggles, so stop the
               synthesised click from cancelling the move that just landed. */
            event.preventDefault();
            orderAtGrabRef.current = null;
            place.place(key, labelOf(key));
        }
    };

    if (presentation === 'linear') {
        return (
            <div className={className}>
                <ol
                    aria-labelledby={labelledBy}
                    aria-label={labelledBy === undefined ? label : undefined}
                    aria-describedby={describedBy(error !== undefined && errorId)}
                    className="grid max-w-[620px] gap-2"
                >
                    {order.map((key, index) => (
                        <li key={key} className="flex min-h-[44px] items-center gap-2.5">
                            <label className="flex w-full items-center gap-2 text-[13.5px]">
                                <span className="min-w-0 flex-1">{labelOf(key)}</span>
                                {/* One native control per item, which is the
                                    single-input route: no grab, no move, no
                                    pointer path gesture. */}
                                <select
                                    value={index + 1}
                                    disabled={readOnly}
                                    aria-label={strings.positionLabel(labelOf(key))}
                                    onChange={(event) => applyMove(index, Number(event.target.value) - 1, null)}
                                    className="h-[38px] min-h-[24px] w-20 rounded border border-border bg-surface px-2 text-sm"
                                >
                                    {order.map((_item, position) => (
                                        <option key={position} value={position + 1}>
                                            {position + 1}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </li>
                    ))}
                </ol>
                <InlineError id={errorId} message={error} />
            </div>
        );
    }

    return (
        <div className={className} onKeyDown={place.onKeyDown}>
            <ol
                aria-labelledby={labelledBy}
                aria-label={labelledBy === undefined ? label : undefined}
                aria-describedby={describedBy(error !== undefined && errorId)}
                className="grid max-w-[620px] gap-2"
            >
                {order.map((key, index) => {
                    const rowLabel = labelOf(key);
                    const held = place.isHeld(key);
                    const holding = place.held !== null;
                    return (
                        <li
                            key={key}
                            className={movableRowClass({
                                held,
                                moved: lastMoved === key && !holding,
                                target: holding && !held,
                            })}
                        >
                            {/* Every row but the held one is a destination
                                here, so the row stays reachable and its
                                buttons do not: the design says Tab visits
                                only accepting destinations during a grab,
                                and a move button is not one. */}
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'w-3.5 shrink-0 font-mono text-[11px] font-bold',
                                    lastMoved === key || held ? 'text-accent' : 'text-fg-2',
                                )}
                            >
                                {index + 1}
                            </span>
                            {holding && !held ? (
                                <button
                                    type="button"
                                    onClick={() => place.place(key, rowLabel)}
                                    className="min-h-[24px] min-w-0 flex-1 cursor-pointer text-left text-accent underline"
                                    {...place.target()}
                                >
                                    {strings.placeHere(rowLabel)}
                                </button>
                            ) : (
                                <span className={cn('min-w-0 flex-1', (lastMoved === key || held) && 'font-medium')}>
                                    {rowLabel}
                                    {held ? (
                                        <span className="ml-2 text-[11px] font-semibold text-accent">
                                            {strings.holding(rowLabel)}
                                        </span>
                                    ) : null}
                                </span>
                            )}
                            <span
                                className="flex shrink-0 gap-1"
                                {...place.contained(key)}
                                {...place.heldProps(key)}
                            >
                                <button
                                    type="button"
                                    aria-pressed={held}
                                    aria-label={strings.grab(rowLabel)}
                                    disabled={readOnly}
                                    onClick={() => {
                                        if (!place.isHeld(key)) {
                                            orderAtGrabRef.current = [...orderRef.current];
                                        }
                                        place.toggle(key, rowLabel);
                                    }}
                                    onKeyDown={(event) => onGrabKeyDown(event, key)}
                                    className={cn(buttonTarget, held && 'border-2 border-accent bg-accent-soft')}
                                >
                                    <Icon icon={GripVertical} size={14} />
                                </button>
                                <button
                                    type="button"
                                    ref={(el) => {
                                        buttonRefs.current.set(`${key}:up`, el);
                                    }}
                                    aria-label={strings.moveUp(rowLabel)}
                                    disabled={readOnly || index === 0}
                                    onClick={() => applyMove(index, index - 1, 'up')}
                                    className={buttonTarget}
                                >
                                    <Icon icon={ArrowUp} size={14} />
                                </button>
                                <button
                                    type="button"
                                    ref={(el) => {
                                        buttonRefs.current.set(`${key}:down`, el);
                                    }}
                                    aria-label={strings.moveDown(rowLabel)}
                                    disabled={readOnly || index === count - 1}
                                    onClick={() => applyMove(index, index + 1, 'down')}
                                    className={buttonTarget}
                                >
                                    <Icon icon={ArrowDown} size={14} />
                                </button>
                            </span>
                        </li>
                    );
                })}
            </ol>
            <InlineError id={errorId} message={error} />
        </div>
    );
}
