import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { cn } from '@/shared/cn';
import { useAnnounce } from '@/shared/a11y';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    EmptyState,
    ErrorState,
    Icon,
    SaveIndicator,
} from '@/shared/components';
import {
    useAddQuizSlot,
    useRemoveQuizSlot,
    useReplaceQuizSlots,
    useQuizSlots,
    useUpdateQuizSlot,
} from '../assessment-api';
import { assessmentStrings as strings } from '../assessment-strings';
import { deniedFor } from '../lib/problems';
import { useSaveTracker } from '../lib/save-state';
import { pointsText } from '../lib/format';
import { ListSkeleton, PermissionDenied, SaveError } from '../components/states';
import { CheckboxField, Field, Select, TextInput } from '../components/field';
import { OrderControls } from '../components/order-controls';
import { PoolQueryEditor, blankLeaf } from '../components/pool-query-editor';
import { BankPickerDialog } from './bank-picker-dialog';
import type {
    AddQuizSlotRequest,
    PoolQuery,
    ProviderOption,
    PublicationWarning,
    QuizSlot,
    QuizSlotInput,
    UpdateQuizSlotRequest,
} from '../lib/assessment-types';

/*
 * The ordered slot tree of one quiz: sections, placed questions and random
 * draws, following QTI's assessment test, test part, section, item
 * reference hierarchy.
 *
 * Two flags per node that must never be collapsed into one, and the labels
 * say which is which: "required" governs SELECTION (a child taken before the
 * random remainder) and "fixed" governs ORDERING (a child that never leaves
 * its index when the section is shuffled). Collapsing them is the mistake
 * this structure exists to make impossible.
 *
 * A field edit is a PATCH on its own node; a structural move is a PUT of the
 * whole tree, because a node changing position moves every sibling index
 * with it and three writes that each land separately would leave the tree
 * briefly inconsistent and permanently wrong if one of them failed.
 */

function toInput(slot: QuizSlot): QuizSlotInput {
    return {
        id: slot.id,
        kind: slot.kind,
        title: slot.title ?? null,
        bank_entry_id: slot.bank_entry_id ?? null,
        question_version_id: slot.question_version_id ?? null,
        points: slot.points,
        weight: slot.weight,
        required: slot.required,
        fixed: slot.fixed,
        visible: slot.visible,
        keep_together: slot.keep_together,
        selection: slot.selection ?? null,
        ordering: slot.ordering ?? null,
        pool: slot.pool ?? null,
        policy_key: slot.policy_key ?? null,
        policy_params: slot.policy_params ?? null,
        children: (slot.children ?? []).map(toInput),
    };
}

/** One sibling list with two entries swapped, everything else untouched. */
function moveWithin(slots: readonly QuizSlot[], from: number, to: number): QuizSlot[] {
    const next = [...slots];
    const moved = next[from];
    if (moved === undefined || to < 0 || to >= next.length) {
        return next;
    }
    next.splice(from, 1);
    next.splice(to, 0, moved);
    return next;
}

/**
 * The tree with one sibling list reordered, found by the parent id.
 *
 * A null parent is the top level, which is why the search cannot simply look
 * for a node: the top level has no node to find.
 */
function reorderTree(
    slots: readonly QuizSlot[],
    parentId: number | null,
    from: number,
    to: number,
): QuizSlot[] {
    if (parentId === null) {
        return moveWithin(slots, from, to);
    }
    return slots.map((slot) =>
        slot.id === parentId
            ? { ...slot, children: moveWithin(slot.children ?? [], from, to) }
            : { ...slot, children: reorderTree(slot.children ?? [], parentId, from, to) },
    );
}

/**
 * The stored pool with one member changed.
 *
 * A pool has two required members, so a partial update has to be layered
 * over the whole stored object rather than sent alone; writing that spread
 * inline five times is how one of them ends up silently reset.
 */
function poolWith(
    slot: QuizSlot,
    query: PoolQuery,
    patch: Partial<NonNullable<QuizSlot['pool']>>,
): NonNullable<QuizSlot['pool']> {
    const stored = slot.pool ?? null;
    return {
        ...(stored ?? {}),
        count: stored?.count ?? 1,
        strategy: stored?.strategy ?? 'uniform',
        query,
        ...patch,
    };
}

function labelOf(slot: QuizSlot): string {
    if (slot.kind === 'section') {
        return slot.title ?? strings.slots.untitledSection;
    }
    if (slot.kind === 'pool') {
        return strings.slots.pool;
    }
    const prompt = slot.question?.definition?.prompt;
    return typeof prompt === 'string' && prompt !== '' ? prompt : strings.slots.question;
}

const KIND_CLASS: Record<QuizSlot['kind'], string> = {
    section: 'bg-accent-soft text-accent',
    question: 'bg-surface-2 text-fg-2',
    pool: 'bg-warn-soft text-warn-strong',
};

function KindChip({ kind }: { kind: QuizSlot['kind'] }) {
    return (
        <span
            className={cn(
                'shrink-0 rounded px-1.5 py-px font-mono text-[9.5px] font-bold uppercase',
                KIND_CLASS[kind],
            )}
        >
            {kind === 'section'
                ? strings.slots.section
                : kind === 'pool'
                  ? strings.slots.pool
                  : strings.slots.question}
        </span>
    );
}

function WarningLine({ warning }: { warning: PublicationWarning }) {
    const blocking = warning.severity === 'blocking';
    return (
        <p
            className={cn(
                'rounded border border-border border-l-[3px] px-2.5 py-1.5 text-[11.5px] leading-[1.5]',
                blocking ? 'border-l-danger text-fg' : 'border-l-warn text-fg-2',
            )}
        >
            <strong className={cn('font-semibold', blocking ? 'text-danger' : 'text-warn-strong')}>
                {blocking ? strings.slots.warningBlocking : strings.slots.warningAdvisory}
            </strong>{' '}
            {warning.message}
        </p>
    );
}

/**
 * The three provider-keyed slot fields, from the registries the site
 * actually has. Empty lists are the honest answer for a server that sends
 * none, and every control falls back to the free vocabulary rather than to a
 * blank list an author cannot choose from.
 */
export interface SlotVocabularies {
    selection_strategies: readonly ProviderOption[];
    exposure_controls: readonly ProviderOption[];
    pool_predicates: readonly ProviderOption[];
}

/**
 * A select over registered provider keys that keeps an unresolvable stored
 * key selected rather than silently replacing it on the next save, which is
 * the same rule the quiz settings form follows for a stored behaviour.
 */
function ProviderSelect({
    id,
    describedBy,
    value,
    options,
    disabled,
    allowNone,
    onChange,
}: {
    id: string;
    describedBy?: string;
    value: string;
    options: readonly ProviderOption[];
    disabled: boolean;
    allowNone?: string;
    onChange: (next: string) => void;
}) {
    const known = value === '' || options.some((option) => option.key === value);

    if (options.length === 0) {
        // The site sent no vocabulary at all, which is a free build with no
        // placement package. A text input is still honest there.
        return (
            <TextInput
                id={id}
                aria-describedby={describedBy}
                defaultValue={value}
                disabled={disabled}
                onBlur={(event) => onChange(event.target.value.trim())}
            />
        );
    }

    return (
        <Select
            id={id}
            aria-describedby={describedBy}
            value={value}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value)}
        >
            {allowNone !== undefined && <option value="">{allowNone}</option>}
            {!known && <option value={value}>{strings.slots.providerUnavailable(value)}</option>}
            {options.map((option) => (
                <option key={option.key} value={option.key}>
                    {option.label}
                </option>
            ))}
        </Select>
    );
}

interface NodeProps {
    slot: QuizSlot;
    vocabularies: SlotVocabularies;
    index: number;
    siblings: number;
    parentId: number | null;
    depth: number;
    warnings: readonly PublicationWarning[];
    busy: boolean;
    onMove: (parentId: number | null, from: number, to: number) => void;
    onPatch: (slotId: number, body: UpdateQuizSlotRequest) => void;
    onRemove: (slot: QuizSlot) => void;
    onAdd: (parentId: number, kind: QuizSlot['kind']) => void;
}

function SlotNode({
    slot,
    vocabularies,
    index,
    siblings,
    parentId,
    depth,
    warnings,
    busy,
    onMove,
    onPatch,
    onRemove,
    onAdd,
}: NodeProps) {
    const own = warnings.filter((warning) => warning.slot_id === slot.id);
    const [pool, setPool] = useState<PoolQuery | null>(null);
    const currentPool = pool ?? slot.pool?.query ?? blankLeaf();

    return (
        <li>
            <div
                className="flex flex-wrap items-center gap-2.5 border-b border-border/60 px-3.5 py-2.5 text-[12.5px]"
                style={{ paddingLeft: `${14 + depth * 20}px` }}
            >
                <span aria-hidden="true" className="w-4 shrink-0 font-mono text-[11px] font-bold text-fg-2">
                    {index + 1}
                </span>
                <KindChip kind={slot.kind} />
                <span className="min-w-0 flex-1">
                    <span className="line-clamp-1">{labelOf(slot)}</span>
                    <span className="block text-[11px] text-fg-2">
                        {[
                            slot.kind === 'question'
                                ? slot.question_version_id === null || slot.question_version_id === undefined
                                    ? strings.slots.latestVersion
                                    : strings.slots.pinnedVersion(slot.question?.version ?? 0)
                                : null,
                            slot.kind === 'pool' ? (slot.pool?.strategy ?? '') : null,
                            `${strings.slots.points} ${pointsText(slot.points ?? 1)}`,
                        ]
                            .filter((part): part is string => part !== null && part !== '')
                            .join(' · ')}
                    </span>
                </span>
                <OrderControls
                    size="sm"
                    upLabel={strings.slots.moveUp(index + 1)}
                    downLabel={strings.slots.moveDown(index + 1)}
                    canMoveUp={index > 0}
                    canMoveDown={index < siblings - 1}
                    onMoveUp={() => onMove(parentId, index, index - 1)}
                    onMoveDown={() => onMove(parentId, index, index + 1)}
                />
                <Button variant="ghost" size="sm" disabled={busy} onClick={() => onRemove(slot)}>
                    <span className="sr-only">{strings.slots.removeNode(labelOf(slot))}</span>
                    <span aria-hidden="true">{strings.common.remove}</span>
                </Button>
            </div>

            {own.length > 0 && (
                <div
                    className="grid gap-1.5 border-b border-border/60 px-3.5 py-2"
                    style={{ paddingLeft: `${14 + depth * 20}px` }}
                >
                    <p className="font-display text-[10.5px] font-semibold uppercase tracking-wide text-fg-2">
                        {strings.slots.warningAtItem}
                    </p>
                    {own.map((warning, position) => (
                        <WarningLine key={`${warning.check}-${position}`} warning={warning} />
                    ))}
                </div>
            )}

            <details className="border-b border-border/60">
                <summary
                    className="cursor-pointer px-3.5 py-1.5 text-[11.5px] text-fg-2"
                    style={{ paddingLeft: `${14 + depth * 20}px` }}
                >
                    {strings.common.edit}
                </summary>
                <div
                    className="grid gap-3 px-3.5 pb-3.5 pt-1"
                    style={{ paddingLeft: `${14 + depth * 20}px` }}
                >
                    {slot.kind === 'section' && (
                        <Field label={strings.bank.folderName}>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    defaultValue={slot.title ?? ''}
                                    disabled={busy}
                                    onBlur={(event) => onPatch(slot.id, { title: event.target.value })}
                                />
                            )}
                        </Field>
                    )}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label={strings.slots.points}>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    inputMode="decimal"
                                    defaultValue={String(slot.points ?? 1)}
                                    disabled={busy}
                                    onBlur={(event) =>
                                        onPatch(slot.id, { points: Number(event.target.value) })
                                    }
                                />
                            )}
                        </Field>
                        <Field label={strings.slots.weight}>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    inputMode="decimal"
                                    defaultValue={String(slot.weight ?? 1)}
                                    disabled={busy}
                                    onBlur={(event) =>
                                        onPatch(slot.id, { weight: Number(event.target.value) })
                                    }
                                />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-2.5 sm:grid-cols-2">
                        <CheckboxField
                            label={strings.slots.required}
                            hint={strings.slots.requiredHint}
                            checked={slot.required === true}
                            disabled={busy}
                            onChange={(event) => onPatch(slot.id, { required: event.target.checked })}
                        />
                        <CheckboxField
                            label={strings.slots.fixed}
                            hint={strings.slots.fixedHint}
                            checked={slot.fixed === true}
                            disabled={busy}
                            onChange={(event) => onPatch(slot.id, { fixed: event.target.checked })}
                        />
                    </div>

                    {slot.kind === 'section' && (
                        <>
                            <div className="grid gap-2.5 sm:grid-cols-2">
                                <CheckboxField
                                    label={strings.slots.visible}
                                    checked={slot.visible !== false}
                                    disabled={busy}
                                    onChange={(event) => onPatch(slot.id, { visible: event.target.checked })}
                                />
                                <CheckboxField
                                    label={strings.slots.keepTogether}
                                    hint={strings.slots.keepTogetherHint}
                                    checked={slot.keep_together !== false}
                                    disabled={busy}
                                    onChange={(event) =>
                                        onPatch(slot.id, { keep_together: event.target.checked })
                                    }
                                />
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label={strings.slots.select} hint={strings.slots.selectHint}>
                                    {({ id, describedBy }) => (
                                        <TextInput
                                            id={id}
                                            inputMode="numeric"
                                            aria-describedby={describedBy}
                                            defaultValue={
                                                slot.selection?.select === undefined
                                                    ? ''
                                                    : String(slot.selection.select)
                                            }
                                            disabled={busy}
                                            onBlur={(event) =>
                                                onPatch(slot.id, {
                                                    selection:
                                                        event.target.value.trim() === ''
                                                            ? null
                                                            : {
                                                                  ...slot.selection,
                                                                  select: Number(event.target.value),
                                                              },
                                                })
                                            }
                                        />
                                    )}
                                </Field>
                                <div className="grid gap-2.5">
                                    <CheckboxField
                                        label={strings.slots.withReplacement}
                                        checked={slot.selection?.with_replacement === true}
                                        disabled={busy}
                                        onChange={(event) =>
                                            onPatch(slot.id, {
                                                selection: {
                                                    ...slot.selection,
                                                    with_replacement: event.target.checked,
                                                },
                                            })
                                        }
                                    />
                                    <CheckboxField
                                        label={strings.slots.shuffle}
                                        checked={slot.ordering?.shuffle === true}
                                        disabled={busy}
                                        onChange={(event) =>
                                            onPatch(slot.id, { ordering: { shuffle: event.target.checked } })
                                        }
                                    />
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={busy}
                                    onClick={() => onAdd(slot.id, 'question')}
                                >
                                    <Icon icon={Plus} size={13} />
                                    {strings.slots.addQuestion}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={busy}
                                    onClick={() => onAdd(slot.id, 'pool')}
                                >
                                    <Icon icon={Plus} size={13} />
                                    {strings.slots.addPool}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled={busy}
                                    onClick={() => onAdd(slot.id, 'section')}
                                >
                                    <Icon icon={Plus} size={13} />
                                    {strings.slots.addSection}
                                </Button>
                            </div>
                        </>
                    )}

                    {slot.kind === 'question' && (
                        <Field label={strings.bank.pin}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    disabled={busy}
                                    value={
                                        slot.question_version_id === null ||
                                        slot.question_version_id === undefined
                                            ? ''
                                            : String(slot.question_version_id)
                                    }
                                    onChange={(event) =>
                                        onPatch(slot.id, {
                                            question_version_id:
                                                event.target.value === '' ? null : Number(event.target.value),
                                        })
                                    }
                                >
                                    <option value="">{strings.bank.pinLatest}</option>
                                    {slot.question !== null && slot.question !== undefined && (
                                        <option value={String(slot.question.id)}>
                                            {strings.bank.pinned(slot.question.version)}
                                        </option>
                                    )}
                                </Select>
                            )}
                        </Field>
                    )}

                    {slot.kind === 'pool' && (
                        <>
                            <Field label={strings.slots.poolCount} hint={strings.slots.poolCountHint}>
                                {({ id, describedBy }) => (
                                    <TextInput
                                        id={id}
                                        type="number"
                                        min={1}
                                        step={1}
                                        required
                                        aria-describedby={describedBy}
                                        defaultValue={slot.pool?.count ?? 1}
                                        disabled={busy}
                                        onBlur={(event) => {
                                            if (event.target.reportValidity())
                                                onPatch(slot.id, {
                                                    pool: poolWith(slot, currentPool, {
                                                        count: Number(event.target.value),
                                                    }),
                                                });
                                        }}
                                    />
                                )}
                            </Field>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {/* Both are provider keys, so both are chosen
                                    from what this site registers rather than
                                    typed: a typo used to produce a uniform
                                    draw with no error at save, no finding at
                                    publish and evidence only in the per-item
                                    selection audit afterwards. */}
                                <Field label={strings.slots.strategy} hint={strings.slots.strategyHint}>
                                    {({ id, describedBy }) => (
                                        <ProviderSelect
                                            id={id}
                                            describedBy={describedBy}
                                            value={slot.pool?.strategy ?? 'uniform'}
                                            options={vocabularies.selection_strategies}
                                            disabled={busy}
                                            onChange={(next) =>
                                                onPatch(slot.id, {
                                                    pool: poolWith(slot, currentPool, {
                                                        strategy: next === '' ? 'uniform' : next,
                                                    }),
                                                })
                                            }
                                        />
                                    )}
                                </Field>
                                <Field label={strings.slots.exposure}>
                                    {({ id }) => (
                                        <ProviderSelect
                                            id={id}
                                            value={slot.pool?.exposure_control ?? ''}
                                            options={vocabularies.exposure_controls}
                                            allowNone={strings.slots.exposureNone}
                                            disabled={busy}
                                            onChange={(next) =>
                                                onPatch(slot.id, {
                                                    pool: poolWith(slot, currentPool, {
                                                        exposure_control: next === '' ? null : next,
                                                    }),
                                                })
                                            }
                                        />
                                    )}
                                </Field>
                            </div>
                            <CheckboxField
                                label={strings.slots.allowManual}
                                hint={strings.slots.allowManualHint}
                                checked={slot.pool?.allow_manual_types === true}
                                disabled={busy}
                                onChange={(event) =>
                                    onPatch(slot.id, {
                                        pool: poolWith(slot, currentPool, {
                                            allow_manual_types: event.target.checked,
                                        }),
                                    })
                                }
                            />
                            <div className="grid gap-2">
                                <p className="font-display text-xs font-semibold text-fg">
                                    {strings.slots.query}
                                </p>
                                <PoolQueryEditor
                                    query={currentPool}
                                    disabled={busy}
                                    kinds={vocabularies.pool_predicates}
                                    onChange={(next) => setPool(next)}
                                />
                                <div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={busy || pool === null}
                                        onClick={() => {
                                            onPatch(slot.id, { pool: poolWith(slot, currentPool, {}) });
                                            setPool(null);
                                        }}
                                    >
                                        {strings.common.save}
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}

                    <Field label={strings.slots.policyKey} hint={strings.slots.policyKeyHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                defaultValue={slot.policy_key ?? ''}
                                disabled={busy}
                                onBlur={(event) =>
                                    onPatch(slot.id, {
                                        policy_key:
                                            event.target.value.trim() === ''
                                                ? null
                                                : event.target.value.trim(),
                                    })
                                }
                            />
                        )}
                    </Field>
                </div>
            </details>

            {(slot.children ?? []).length > 0 && (
                <ul>
                    {(slot.children ?? []).map((child, position) => (
                        <SlotNode
                            key={child.id}
                            slot={child}
                            vocabularies={vocabularies}
                            index={position}
                            siblings={(slot.children ?? []).length}
                            parentId={slot.id}
                            depth={depth + 1}
                            warnings={warnings}
                            busy={busy}
                            onMove={onMove}
                            onPatch={onPatch}
                            onRemove={onRemove}
                            onAdd={onAdd}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

export function SlotsEditor({ courseId, quizId }: { courseId: number; quizId: number }) {
    const slotsQuery = useQuizSlots(quizId);
    const addSlot = useAddQuizSlot();
    const updateSlot = useUpdateQuizSlot();
    const removeSlot = useRemoveQuizSlot();
    const replaceSlots = useReplaceQuizSlots();
    const save = useSaveTracker();
    const announce = useAnnounce();
    const [picking, setPicking] = useState<{ parentId: number | null } | null>(null);
    const [addingPool, setAddingPool] = useState<{ parentId: number | null } | null>(null);

    const tree = slotsQuery.data;
    const slots = useMemo(() => tree?.slots ?? [], [tree]);
    const warnings = useMemo(() => tree?.warnings ?? [], [tree]);
    // The three provider-keyed fields are authored from what the site
    // registers, so a newly registered strategy or predicate appears in the
    // editor with no client change.
    const vocabularies = useMemo<SlotVocabularies>(
        () => ({
            selection_strategies: tree?.vocabularies?.selection_strategies ?? [],
            exposure_controls: tree?.vocabularies?.exposure_controls ?? [],
            pool_predicates: tree?.vocabularies?.pool_predicates ?? [],
        }),
        [tree],
    );
    const treeWarnings = warnings.filter(
        (warning) => warning.slot_id === null || warning.slot_id === undefined,
    );
    const busy = save.state === 'saving';

    const onMove = (parentId: number | null, from: number, to: number) => {
        const next = reorderTree(slots, parentId, from, to);
        announce(strings.slots.moveUp(to + 1));
        void save.run((idempotencyKey) =>
            replaceSlots.mutateAsync({
                quizId,
                slots: next.map(toInput) as AddQuizSlotRequest[],
                idempotencyKey,
            }),
        );
    };

    const onPatch = (slotId: number, body: UpdateQuizSlotRequest) => {
        void save.run((idempotencyKey) => updateSlot.mutateAsync({ quizId, slotId, body, idempotencyKey }));
    };

    const onRemove = (slot: QuizSlot) => {
        void save.run((idempotencyKey) =>
            removeSlot.mutateAsync({ quizId, slotId: slot.id, idempotencyKey }),
        );
    };

    const onAdd = (parentId: number | null, kind: QuizSlot['kind']) => {
        if (kind === 'question') {
            setPicking({ parentId });
            return;
        }
        if (kind === 'pool') {
            setAddingPool({ parentId });
            return;
        }
        const body: AddQuizSlotRequest = {
            kind: 'section',
            title: '',
            parent_id: parentId,
            keep_together: true,
            visible: true,
        };
        void save.run((idempotencyKey) => addSlot.mutateAsync({ quizId, body, idempotencyKey }));
    };

    if (slotsQuery.isPending) {
        return <ListSkeleton label={strings.common.loading} rows={3} />;
    }
    if (slotsQuery.isError) {
        return deniedFor(slotsQuery.error) ? (
            <PermissionDenied />
        ) : (
            <ErrorState
                error={slotsQuery.error}
                title={strings.slots.errorTitle}
                onRetry={() => void slotsQuery.refetch()}
            />
        );
    }

    return (
        <section
            aria-label={strings.slots.title}
            className="vy-quiz-selection-tree rounded-lg border border-border bg-surface"
        >
            <div className="vy-quiz-selection-header flex flex-wrap items-center gap-2.5 border-b border-border px-4 py-3">
                <div className="min-w-0 flex-1">
                    <h4 className="font-display text-[13px] font-bold text-fg">{strings.slots.title}</h4>
                    <p className="text-[11.5px] text-fg-2">{strings.slots.description}</p>
                </div>
                <Button variant="outline" size="sm" disabled={busy} onClick={() => onAdd(null, 'section')}>
                    <Icon icon={Plus} size={13} />
                    {strings.slots.addSection}
                </Button>
                <Button variant="outline" size="sm" disabled={busy} onClick={() => onAdd(null, 'question')}>
                    <Icon icon={Plus} size={13} />
                    {strings.slots.addQuestion}
                </Button>
                <Button variant="outline" size="sm" disabled={busy} onClick={() => onAdd(null, 'pool')}>
                    <Icon icon={Plus} size={13} />
                    {strings.slots.addPool}
                </Button>
                <SaveIndicator state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
            </div>

            {save.state === 'failed' && (
                <div className="px-4 pt-3">
                    <SaveError error={save.error} onRetry={save.retry} />
                </div>
            )}

            {treeWarnings.length > 0 && (
                <div className="grid gap-1.5 border-b border-border px-4 py-3">
                    <p className="font-display text-[11px] font-semibold uppercase tracking-wide text-fg-2">
                        {strings.slots.warningsTitle}
                    </p>
                    <p className="text-[11.5px] text-fg-2">{strings.slots.warningsBody}</p>
                    {treeWarnings.map((warning, position) => (
                        <WarningLine key={`${warning.check}-${position}`} warning={warning} />
                    ))}
                </div>
            )}

            {slots.length === 0 ? (
                <EmptyState title={strings.slots.emptyTitle} description={strings.slots.emptyBody} />
            ) : (
                <ul>
                    {slots.map((slot, index) => (
                        <SlotNode
                            key={slot.id}
                            slot={slot}
                            vocabularies={vocabularies}
                            index={index}
                            siblings={slots.length}
                            parentId={null}
                            depth={0}
                            warnings={warnings}
                            busy={busy}
                            onMove={onMove}
                            onPatch={onPatch}
                            onRemove={onRemove}
                            onAdd={onAdd}
                        />
                    ))}
                </ul>
            )}

            {addingPool !== null && (
                <PoolDraftDialog
                    quizId={quizId}
                    parentId={addingPool.parentId}
                    vocabularies={vocabularies}
                    onClose={() => setAddingPool(null)}
                />
            )}
            {picking !== null && (
                <BankPickerDialog
                    courseId={courseId}
                    onClose={() => setPicking(null)}
                    onPick={(entry) => {
                        const parentId = picking.parentId;
                        setPicking(null);
                        void save.run((idempotencyKey) =>
                            addSlot.mutateAsync({
                                quizId,
                                body: {
                                    kind: 'question',
                                    parent_id: parentId,
                                    bank_entry_id: entry.id,
                                    /* Null is "resolve the latest version at
                                       attempt start", which then never moves
                                       again for that attempt. */
                                    question_version_id: null,
                                    points: entry.latest_version?.points ?? 1,
                                },
                                idempotencyKey,
                            }),
                        );
                    }}
                />
            )}
        </section>
    );
}

function PoolDraftDialog({
    quizId,
    parentId,
    vocabularies,
    onClose,
}: {
    quizId: number;
    parentId: number | null;
    vocabularies: SlotVocabularies;
    onClose: () => void;
}) {
    const add = useAddQuizSlot();
    const save = useSaveTracker();
    const [count, setCount] = useState('1');
    const [strategy, setStrategy] = useState(
        vocabularies.selection_strategies.find((option) => option.key === 'uniform')?.key ??
            vocabularies.selection_strategies[0]?.key ??
            'uniform',
    );
    const [query, setQuery] = useState<PoolQuery>({ kind: 'status', operator: 'eq', value: 'ready' });
    const busy = save.state === 'saving';
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <DialogContent className="vy-quiz-question-dialog max-w-2xl">
                <DialogTitle>{strings.slots.addPool}</DialogTitle>
                <DialogDescription>{strings.slots.poolCreateIntro}</DialogDescription>
                <form
                    className="grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (!Number.isInteger(Number(count)) || Number(count) < 1) return;
                        void save.run(async (idempotencyKey) => {
                            const created = await add.mutateAsync({
                                quizId,
                                body: {
                                    kind: 'pool',
                                    parent_id: parentId,
                                    pool: { count: Number(count), strategy, query },
                                },
                                idempotencyKey,
                            });
                            onClose();
                            return created;
                        });
                    }}
                >
                    <div className="max-h-[55dvh] overflow-y-auto grid gap-5 pr-1">
                        <Field label={strings.slots.poolCount} hint={strings.slots.poolCountHint}>
                            {({ id, describedBy }) => (
                                <TextInput
                                    id={id}
                                    aria-describedby={describedBy}
                                    type="number"
                                    min={1}
                                    step={1}
                                    required
                                    autoFocus
                                    value={count}
                                    disabled={busy}
                                    onChange={(event) => setCount(event.target.value)}
                                />
                            )}
                        </Field>
                        <Field label={strings.slots.strategy}>
                            {({ id }) => (
                                <ProviderSelect
                                    id={id}
                                    value={strategy}
                                    options={vocabularies.selection_strategies}
                                    disabled={busy}
                                    onChange={setStrategy}
                                />
                            )}
                        </Field>
                        <section aria-label={strings.slots.poolConditions}>
                            <h3 className="font-display text-sm font-semibold mb-3">
                                {strings.slots.poolConditions}
                            </h3>
                            <PoolQueryEditor
                                query={query}
                                onChange={setQuery}
                                kinds={vocabularies.pool_predicates}
                                disabled={busy}
                            />
                        </section>
                    </div>
                    {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={busy} onClick={onClose}>
                            {strings.common.cancel}
                        </Button>
                        <Button type="submit" disabled={busy}>
                            {strings.slots.addPool}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
