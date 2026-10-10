import { useNavigationGuard } from '@/shared/navigation-guard';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Eye, EyeOff, LockOpen, Plus, Trash2 } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Button, ErrorState, Icon, Skeleton } from '@/shared/components';
import { useConditionEditor, type ConditionTarget } from '@/condition-editors';
import {
    useAvailabilityCatalogue,
    useAvailabilityRule,
    useSetAvailabilityRule,
} from '@/shared/flow';
import { flowStrings } from '../../flow-strings';
import { Field, Select } from '../../components/field';
import {
    PolicyField,
    seedPolicyValues,
    validatePolicyValues,
    type PolicyValues,
} from '../../components/settings-form';
import {
    appendChild,
    childPath,
    childShown,
    emptyTree,
    isEmptyTree,
    isNode,
    leafFromPreset,
    normaliseNode,
    OPERATORS,
    removeChild,
    replaceChild,
    setChildShown,
    setOperator,
    usesShowc,
    type Operator,
    type TreeChild,
} from '../../lib/availability-tree';
import type {
    AvailabilityCatalogue,
    AvailabilityConditionInfo,
    AvailabilityLeaf,
    AvailabilityNode,
    AvailabilityPresetInfo,
    AvailabilitySubjectType,
} from '../../lib/flow-types';
import { LeafEditor, summariseLeaf } from './leaf-editor';
import { RulePreview } from './rule-preview';

/*
 * The Restrict access box, on a module, a lesson, a quiz, an assignment or a
 * course.
 *
 * One picker offers named presets for quick setup, followed by the grouped
 * condition catalogue. Presets compile into ordinary leaves; conditions
 * open their editor as soon as they are added. Both use the same tree.
 *
 * Add restriction set nests, and the operator lives on the set. Negation is
 * one of the four operators and never a second, inverted copy of a
 * condition: a catalogue that ships the negative of every family doubles and
 * then drifts out of sync with itself.
 *
 * The whole tree is one write. A null tree clears the rule, exactly as the
 * delete verb does, so an author who removes the last row does one thing.
 */

const strings = flowStrings.availability;

function conditionsByGroup(
    catalogue: AvailabilityCatalogue,
): Array<{ group: string; label: string; conditions: AvailabilityConditionInfo[] }> {
    const groups = new Map<string, AvailabilityConditionInfo[]>();
    for (const condition of catalogue.conditions) {
        if (condition.available === false) continue;
        const list = groups.get(condition.group) ?? [];
        list.push(condition);
        groups.set(condition.group, list);
    }
    return [...groups.entries()].map(([group, conditions]) => ({
        group,
        label: strings.conditionGroups[group] ?? strings.otherGroup,
        /* Declared order, never the registration position: a provider that
           decorated a free one keeps the free one's place, so a family does
           not split when a package is installed. */
        conditions: [...conditions].sort((a, b) => a.order - b.order),
    }));
}

function findCondition(catalogue: AvailabilityCatalogue | null, key: string): AvailabilityConditionInfo | null {
    return catalogue?.conditions.find((condition) => condition.key === key) ?? null;
}

/** The values a preset asks for, in the settings-field shape it declares. */
function PresetForm({
    preset,
    onCancel,
    onAdd,
}: {
    preset: AvailabilityPresetInfo;
    onCancel: () => void;
    onAdd: (values: Record<string, unknown>) => void;
}) {
    const groups = useMemo(() => [{ key: preset.key, label: preset.label, fields: preset.fields }], [preset]);
    const [values, setValues] = useState<PolicyValues>(() => seedPolicyValues(groups));
    const formRef = useRef<HTMLDivElement>(null);
    const errors = validatePolicyValues(groups, values);
    const invalid = Object.keys(errors).length > 0;
    useEffect(() => {
        formRef.current?.querySelector<HTMLElement>('input, select, textarea, button')?.focus();
    }, []);
    return (
        <div ref={formRef} className="grid gap-3 rounded border border-border bg-surface p-3">
            <div>
                <p className="font-display text-xs font-semibold text-fg">{preset.label}</p>
                {preset.description !== null && preset.description !== undefined && (
                    <p className="mt-0.5 text-[11.5px] text-fg-2">{preset.description}</p>
                )}
            </div>
            <div className="grid gap-2.5 sm:grid-cols-2">
                {preset.fields.map((field) => (
                    <PolicyField
                        key={field.key}
                        field={field}
                        path={[field.key]}
                        values={values}
                        errors={errors}
                        onChange={(path, value) =>
                            setValues((current) => ({ ...current, [path[path.length - 1] ?? '']: value }))
                        }
                    />
                ))}
            </div>
            <div className="flex flex-wrap gap-2">
                <Button size="sm" disabled={invalid} onClick={() => onAdd(values)}>
                    {strings.addRestriction}
                </Button>
                <Button size="sm" variant="ghost" onClick={onCancel}>
                    {flowStrings.common.cancel}
                </Button>
            </div>
        </div>
    );
}

/** One row of the tree: a leaf with its editor, or a nested set. */
function TreeChildRow({
    child,
    parent,
    parentPath,
    index,
    depth,
    catalogue,
    targets,
    locale,
    onChange,
    onRemove,
    onToggleShown,
    open,
    onToggleOpen,
}: {
    child: TreeChild;
    parent: AvailabilityNode;
    parentPath: string;
    index: number;
    depth: number;
    catalogue: AvailabilityCatalogue;
    targets: readonly ConditionTarget[];
    locale: string;
    onChange: (child: TreeChild) => void;
    onRemove: () => void;
    onToggleShown: (shown: boolean) => void;
    open: boolean;
    onToggleOpen: () => void;
}) {
    const rowId = useId();
    const editorRef = useRef<HTMLDivElement>(null);
    const path = childPath(parentPath, index);
    const shown = childShown(parent, index);
    const perChild = usesShowc(parent.op);
    useEffect(() => {
        if (open) {
            editorRef.current?.querySelector<HTMLElement>('input, select, textarea, button')?.focus();
        }
    }, [open]);

    if (isNode(child)) {
        return (
            <li className="rounded border border-border bg-bg p-2.5">
                <div className="mb-2 flex flex-wrap items-center gap-2">
                    <span className="font-display text-[11.5px] font-semibold text-fg">
                        {strings.setLabel(index + 1)}
                    </span>
                    <Button size="sm" variant="ghost" onClick={onRemove}>
                        <Icon icon={Trash2} size={13} />
                        {strings.remove}
                    </Button>
                </div>
                <AvailabilityTreeEditor
                    node={child}
                    path={path}
                    depth={depth + 1}
                    catalogue={catalogue}
                    targets={targets}
                    locale={locale}
                    onChange={onChange}
                />
            </li>
        );
    }

    const leaf = child;
    const condition = findCondition(catalogue, leaf.type);
    return (
        <li className="rounded border border-border bg-bg">
            <div className="flex flex-wrap items-center gap-2 px-2.5 py-2">
                <span className="min-w-0 flex-[1_1_16rem] text-[12.5px] text-fg">
                    <LeafSummary leaf={leaf} condition={condition} locale={locale} />
                </span>
                {perChild && (
                    <Button
                        size="sm"
                        variant="ghost"
                        aria-pressed={shown}
                        onClick={() => onToggleShown(!shown)}
                        title={strings.showToggle}
                    >
                        <Icon icon={shown ? Eye : EyeOff} size={13} />
                        {shown ? strings.shown : strings.hidden}
                    </Button>
                )}
                <Button size="sm" variant="ghost" aria-expanded={open} aria-controls={rowId} onClick={onToggleOpen}>
                    {open ? strings.done : strings.edit}
                </Button>
                <Button size="sm" variant="ghost" onClick={onRemove}>
                    <Icon icon={Trash2} size={13} />
                    <span className="sr-only">{strings.remove}</span>
                </Button>
            </div>
            {open && (
                <div ref={editorRef} id={rowId} className="border-t border-border px-2.5 py-2.5">
                    <LeafEditor
                        leaf={leaf}
                        condition={condition}
                        anchors={catalogue.anchors}
                        targets={targets}
                        locale={locale}
                        controlId={(local) => `${rowId}-${local}`}
                        onChange={onChange}
                    />
                </div>
            )}
        </li>
    );
}

/** The one-line reading of a leaf, from the registered editor when it has one. */
function LeafSummary({
    leaf,
    condition,
    locale,
}: {
    leaf: AvailabilityLeaf;
    condition: AvailabilityConditionInfo | null;
    locale: string;
}) {
    const module = useConditionEditor(leaf.type);
    return <>{summariseLeaf(leaf, condition, module?.summarise, locale)}</>;
}

export function AvailabilityTreeEditor({
    node,
    path,
    depth,
    catalogue,
    targets,
    locale,
    onChange,
}: {
    node: AvailabilityNode;
    path: string;
    depth: number;
    catalogue: AvailabilityCatalogue;
    targets: readonly ConditionTarget[];
    locale: string;
    onChange: (node: AvailabilityNode) => void;
}) {
    const [adding, setAdding] = useState<AvailabilityPresetInfo | null>(null);
    const [editingIndex, setEditingIndex] = useState<number | null>(null);
    const groups = useMemo(() => conditionsByGroup(catalogue), [catalogue]);
    const presets = catalogue.presets.filter((preset) => preset.available !== false);

    const change = (next: AvailabilityNode) => onChange(normaliseNode(next));

    return (
        <div className="grid min-w-0 gap-4">
            {node.c.length > 0 && <Field label={strings.operatorLabel} hideLabel={depth > 0}>
                {({ id }) => (
                    <Select
                        id={id}
                        className="max-w-[280px]"
                        value={node.op}
                        onChange={(event) => change(setOperator(node, '', event.target.value as Operator))}
                    >
                        {OPERATORS.map((op) => (
                            <option key={op} value={op}>
                                {strings.operators[op]}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>}
            {!usesShowc(node.op) && node.c.length > 0 && (
                <p className="text-[11.5px] text-fg-2">{strings.combinedShowHint}</p>
            )}
            {node.c.length === 0 ? (
                depth === 0 ? (
                    <div className="flex items-start gap-3 rounded border border-dashed border-border bg-bg p-4">
                        <Icon icon={LockOpen} size={18} className="mt-0.5 shrink-0 text-fg-2" />
                        <div>
                            <p className="font-display text-[13px] font-semibold text-fg">{strings.empty}</p>
                            <p className="mt-1 text-xs text-fg-2">{strings.emptyHint}</p>
                        </div>
                    </div>
                ) : <p className="text-xs text-fg-2">{strings.emptySet}</p>
            ) : (
                <ul className="grid gap-2">
                    {node.c.map((child, index) => (
                        <TreeChildRow
                            key={`${index}-${isNode(child) ? 'set' : child.type}`}
                            child={child}
                            parent={node}
                            parentPath={path}
                            index={index}
                            depth={depth}
                            catalogue={catalogue}
                            targets={targets}
                            locale={locale}
                            open={editingIndex === index}
                            onToggleOpen={() => setEditingIndex((current) => current === index ? null : index)}
                            onChange={(next) => change(replaceChild(node, '', index, next))}
                            onRemove={() => {
                                change(removeChild(node, '', index));
                                setEditingIndex((current) => current === null || current === index
                                    ? null : current > index ? current - 1 : current);
                            }}
                            onToggleShown={(shown) => change(setChildShown(node, '', index, shown))}
                        />
                    ))}
                </ul>
            )}
            {adding !== null ? (
                <PresetForm
                    preset={adding}
                    onCancel={() => setAdding(null)}
                    onAdd={(values) => {
                        change(appendChild(node, '', leafFromPreset(adding, values)));
                        setAdding(null);
                    }}
                />
            ) : (
                <div className="grid min-w-0 gap-3">
                    <Field className="w-full max-w-md" label={strings.addRestriction}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value=""
                                onChange={(event) => {
                                    const value = event.target.value;
                                    const preset = presets.find((entry) => `preset:${entry.key}` === value);
                                    if (preset !== undefined) {
                                        setAdding(preset);
                                        return;
                                    }
                                    const condition = catalogue.conditions.find((entry) =>
                                        entry.available !== false && `condition:${entry.key}` === value);
                                    if (condition !== undefined) {
                                        setEditingIndex(node.c.length);
                                        change(appendChild(node, '', { type: condition.key, payload: {} }));
                                    }
                                }}
                            >
                                <option value="">{strings.presetFallback}</option>
                                {presets.length > 0 && (
                                    <optgroup label={strings.quickSetup}>
                                        {presets.map((preset) => (
                                            <option key={preset.key} value={`preset:${preset.key}`}>
                                                {preset.label}
                                            </option>
                                        ))}
                                    </optgroup>
                                )}
                                {groups.map((group) => (
                                    <optgroup key={group.group} label={group.label}>
                                        {group.conditions.map((condition) => (
                                            <option key={condition.key} value={`condition:${condition.key}`}>
                                                {condition.label}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                            </Select>
                        )}
                    </Field>
                    {depth < 3 && (
                        <details className="rounded border border-border px-3 py-2.5">
                            <summary className="cursor-pointer font-display text-xs font-semibold text-fg-2">
                                {strings.advanced}
                            </summary>
                            <div className="mt-3 grid justify-items-start gap-2">
                                <p className="text-xs text-fg-2">{strings.setHint}</p>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => change(appendChild(node, '', emptyTree('or')))}
                                >
                                    <Icon icon={Plus} size={13} />
                                    {strings.addSet}
                                </Button>
                            </div>
                        </details>
                    )}
                </div>
            )}
        </div>
    );
}

export interface RestrictionBoxProps {
    subjectType: AvailabilitySubjectType;
    subjectId: number;
    courseId: number;
    /** What a condition may point at, already resolved to titles. */
    targets: readonly ConditionTarget[];
    locale: string;
    className?: string;
    onDirtyChange?: (dirty: boolean) => void;
}

export function RestrictionBox({
    subjectType,
    subjectId,
    courseId,
    targets,
    locale,
    className, onDirtyChange,
}: RestrictionBoxProps) {
    const catalogueQuery = useAvailabilityCatalogue('content_gating');
    const ruleQuery = useAvailabilityRule(subjectType, subjectId);
    const save = useSetAvailabilityRule();
    const [tree, setTree] = useState<AvailabilityNode | null>(null);
    const [visibility, setVisibility] = useState<'shown' | 'hidden'>('shown');
    const [dirty, setDirty] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    useNavigationGuard(dirty);
    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty, onDirtyChange]);

    const stored = ruleQuery.data ?? null;
    useEffect(() => {
        if (stored === null) {
            return;
        }
        setTree(stored.tree === null ? null : normaliseNode(stored.tree));
        setVisibility(stored.default_visibility);
        setDirty(false);
    }, [stored]);

    if (catalogueQuery.isPending || ruleQuery.isPending) {
        return (
            <section className={cn('grid gap-2', className)} aria-busy="true">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-20 w-full" />
            </section>
        );
    }

    if (catalogueQuery.isError || ruleQuery.isError) {
        return (
            <section className={className}>
                <ErrorState
                    error={catalogueQuery.error ?? ruleQuery.error}
                    title={flowStrings.common.loadError}
                    onRetry={() => {
                        void catalogueQuery.refetch();
                        void ruleQuery.refetch();
                    }}
                />
            </section>
        );
    }

    const catalogue = catalogueQuery.data;
    const working = tree ?? emptyTree();
    const warnings = stored?.warnings ?? [];

    const change = (next: AvailabilityNode) => {
        setTree(next);
        setDirty(true);
    };

    return (
        <section className={cn('grid min-w-0 gap-5 rounded border border-border bg-surface p-4 sm:p-5', className)}>
            <div className="flex flex-wrap items-start gap-2">
                <div className="min-w-0 flex-1">
                    <h3 className="font-display text-xs font-semibold text-fg">{strings.title}</h3>
                    <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.intro}</p>
                </div>
                <Button size="sm" variant="ghost" onClick={() => setPreviewOpen(true)}>
                    {strings.preview}
                </Button>
            </div>

            <AvailabilityTreeEditor
                node={working}
                path=""
                depth={0}
                catalogue={catalogue}
                targets={targets}
                locale={locale}
                onChange={change}
            />

            {!isEmptyTree(tree) && <Field className="border-t border-border pt-4" label={strings.defaultVisibility} hint={strings.showHint}>
                {({ id, describedBy }) => (
                    <Select
                        id={id}
                        aria-describedby={describedBy}
                        className="max-w-[280px]"
                        value={visibility}
                        onChange={(event) => {
                            setVisibility(event.target.value as 'shown' | 'hidden');
                            setDirty(true);
                        }}
                    >
                        <option value="shown">{strings.shown}</option>
                        <option value="hidden">{strings.hidden}</option>
                    </Select>
                )}
            </Field>}

            {warnings.length > 0 && (
                <div className="rounded border border-border border-l-[3px] border-l-warn bg-bg px-2.5 py-2">
                    <p className="font-display text-[11.5px] font-semibold text-warn-strong">{strings.warningsTitle}</p>
                    <ul className="mt-1 grid gap-0.5">
                        {warnings.map((warning) => (
                            <li key={`${warning.code}-${warning.path ?? ''}`} className="text-[11.5px] text-fg-2">
                                {warning.message}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {(dirty || !isEmptyTree(tree) || stored?.updated_at != null) && <div className="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                <Button
                    size="sm"
                    disabled={!dirty || save.isPending}
                    onClick={() =>
                        save.mutate({
                            subjectType,
                            subjectId,
                            body: {
                                /* An empty shell is not a restriction. Sending
                                   null clears the rule, which is what the
                                   author did. */
                                tree: isEmptyTree(tree) ? null : working,
                                default_visibility: visibility,
                            },
                            idempotencyKey: `availability-${subjectType}-${subjectId}`,
                        })
                    }
                >
                    {save.isPending ? strings.saving : strings.save}
                </Button>
                {!isEmptyTree(tree) && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            setTree(null);
                            setDirty(true);
                        }}
                    >
                        {strings.clear}
                    </Button>
                )}
                {stored?.updated_at !== null && stored?.updated_at !== undefined && (
                    <span className="text-[11px] text-fg-2">{strings.savedAt(stored.updated_at)}</span>
                )}
            </div>}

            {save.isError && <ErrorState error={save.error} title={flowStrings.common.loadError} />}

            {previewOpen && (
                <RulePreview
                    open={previewOpen}
                    onClose={() => setPreviewOpen(false)}
                    courseId={courseId}
                    subjectType={subjectType}
                    subjectId={subjectId}
                    draft={isEmptyTree(tree) ? null : working}
                    locale={locale}
                />
            )}
        </section>
    );
}
