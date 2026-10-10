import { X } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Icon } from '@/shared/components';
import { primitiveStrings as strings } from '../strings';
import { InlineError, describedBy, type PrimitiveProps } from './common';
import { buttonTarget, movableRowClass } from './styles';
import { useSelectThenPlace } from './select-then-place';

/*
 * AssociationSet: the client counterpart of the associate primitive, used by
 * matching, gap match and graphic association.
 *
 * The response is a list of directed pairs. Placement is select then place
 * in every mode: activate a source, then activate a target. The keyboard
 * model is the same two activations, so there is one state machine.
 *
 * The linear presentation is a native select per source, which is literally
 * qti-inline-choice-interaction, so one definition renders as chips, as
 * selects or as typed entry with no second authoring pass.
 */

export interface AssociationEnd {
    key: string;
    label: string;
    /** How many sources this target accepts; 0 or undefined means one. */
    matchMax?: number;
}

export type DirectedPair = readonly [source: string, target: string];

export interface AssociationSetProps extends PrimitiveProps {
    sources: readonly AssociationEnd[];
    targets: readonly AssociationEnd[];
    value: readonly DirectedPair[];
    onChange: (pairs: DirectedPair[]) => void;
    maxAssociations?: number;
    maxMessage?: string;
}

export function AssociationSet({
    sources,
    targets,
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
    maxAssociations,
    maxMessage,
    className,
}: AssociationSetProps) {
    const errorId = controlId('error');
    const sourceLabel = (key: string) => sources.find((s) => s.key === key)?.label ?? key;
    const targetLabel = (key: string) => targets.find((t) => t.key === key)?.label ?? key;
    const pairs = value.filter(([source, target]) => source !== '' && target !== '');
    const targetOf = (source: string) => pairs.find(([s]) => s === source)?.[1] ?? null;
    const placedOn = (target: string) => pairs.filter(([, t]) => t === target).map(([s]) => s);

    const assign = (source: string, target: string | null): boolean => {
        const without = pairs.filter(([s]) => s !== source);
        if (target === null) {
            onChange(without);
            return true;
        }
        const capacity = targets.find((t) => t.key === target)?.matchMax ?? 1;
        if (capacity > 0 && placedOn(target).filter((s) => s !== source).length >= capacity) {
            announce('status', maxMessage ?? strings.maxReached(capacity));
            return false;
        }
        if (maxAssociations !== undefined && maxAssociations > 0 && without.length >= maxAssociations) {
            announce('status', maxMessage ?? strings.maxReached(maxAssociations));
            return false;
        }
        onChange([...without, [source, target] as DirectedPair]);
        return true;
    };

    const place = useSelectThenPlace({
        announce,
        shell,
        onPlace: (heldId, targetId) => assign(heldId, targetId),
    });

    if (presentation === 'linear') {
        return (
            <div className={className}>
                <div
                    role="group"
                    aria-labelledby={labelledBy}
                    aria-label={labelledBy === undefined ? label : undefined}
                    aria-describedby={describedBy(error !== undefined && errorId)}
                    className="grid max-w-[620px] gap-2.5"
                >
                    {sources.map((source) => (
                        <label key={source.key} className="flex min-h-[44px] flex-wrap items-center gap-2.5 text-sm">
                            <span className="min-w-[8rem] flex-1">{source.label}</span>
                            <select
                                value={targetOf(source.key) ?? ''}
                                disabled={readOnly}
                                aria-label={strings.associationFor(source.label)}
                                onChange={(event) =>
                                    assign(source.key, event.target.value === '' ? null : event.target.value)
                                }
                                className="min-h-[44px] w-full max-w-[280px] rounded border border-border bg-surface px-3 text-sm text-fg"
                            >
                                <option value="">{strings.associationTargetNone}</option>
                                {targets.map((target) => (
                                    <option key={target.key} value={target.key}>
                                        {target.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ))}
                </div>
                <InlineError id={errorId} message={error} />
            </div>
        );
    }

    return (
        <div className={className} onKeyDown={place.onKeyDown}>
            <div
                role="group"
                aria-labelledby={labelledBy}
                aria-label={labelledBy === undefined ? label : undefined}
                aria-describedby={describedBy(error !== undefined && errorId)}
                className="grid max-w-[720px] gap-4 sm:grid-cols-2"
            >
                <ul className="grid gap-2">
                    {sources.map((source) => {
                        const held = place.isHeld(source.key);
                        const target = targetOf(source.key);
                        return (
                            /* Everything but the held row leaves the tab
                               sequence while a grab is live, so Tab reaches
                               only the destinations and the item in hand. */
                            <li key={source.key} className={movableRowClass({ held })} {...place.contained(source.key)}>
                                <span className="min-w-0 flex-1">
                                    {source.label}
                                    {target !== null ? (
                                        <span className="ml-2 text-[11.5px] font-semibold text-accent">
                                            {targetLabel(target)}
                                        </span>
                                    ) : null}
                                </span>
                                <button
                                    type="button"
                                    aria-pressed={held}
                                    aria-label={strings.grab(source.label)}
                                    disabled={readOnly}
                                    onClick={() => place.toggle(source.key, source.label)}
                                    className={cn(buttonTarget, held && 'border-2 border-accent bg-accent-soft')}
                                    {...place.heldProps(source.key)}
                                >
                                    <span aria-hidden="true" className="text-[11px] font-bold">
                                        {held ? '■' : '⇄'}
                                    </span>
                                </button>
                                {target !== null ? (
                                    <button
                                        type="button"
                                        aria-label={strings.associationRemove(source.label, targetLabel(target))}
                                        disabled={readOnly}
                                        {...place.contained()}
                                        onClick={() => {
                                            assign(source.key, null);
                                            announce('status', strings.associationCleared(source.label));
                                        }}
                                        className={buttonTarget}
                                    >
                                        <Icon icon={X} size={14} />
                                    </button>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
                <ul className="grid gap-2">
                    {targets.map((target) => {
                        const holding = place.held !== null;
                        const on = placedOn(target.key);
                        return (
                            <li key={target.key} className={movableRowClass({ target: holding })}>
                                {holding ? (
                                    <button
                                        type="button"
                                        onClick={() => place.place(target.key, target.label)}
                                        className="min-h-[24px] min-w-0 flex-1 cursor-pointer text-left text-accent underline"
                                        {...place.target()}
                                    >
                                        {strings.placeHere(target.label)}
                                    </button>
                                ) : (
                                    <span className="min-w-0 flex-1">
                                        {target.label}
                                        {on.length > 0 ? (
                                            <span className="ml-2 text-[11.5px] text-fg-2">
                                                {on.map(sourceLabel).join(', ')}
                                            </span>
                                        ) : null}
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>
            <InlineError id={errorId} message={error} />
        </div>
    );
}
