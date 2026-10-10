import { translate as uiText } from '@/shared/i18n';
import { Plus, Trash2 } from 'lucide-react';
import type { components } from '@vidyalms/api-sdk';
import { Button } from '@/shared/components';
import type { AutomationCatalogue } from './api';
import { ParamsForm } from './params-form';
import { providerLabel } from './workspace';
import { automationStrings as s } from './strings';

export type ConditionTree = components['schemas']['AvailabilityNode'];
type Child = ConditionTree['c'][number];
function updateChildren(node: ConditionTree, children: Child[], op = node.op): ConditionTree {
    const { show, showc, ...rest } = node;
    return {
        ...rest,
        op,
        c: children,
        ...(['and', 'not_or'].includes(op)
            ? showc
                ? {
                      showc: children.map(
                          (child, index) =>
                              showc[node.c.indexOf(child)] ??
                              (children.length === node.c.length ? showc[index] : true) ??
                              true,
                      ),
                  }
                : {}
            : show !== undefined
              ? { show }
              : {}),
    };
}
export function completeConditions(value: ConditionTree | null, root = true): boolean {
    if (value === null) return true;
    if (!value.c.length) return root && value.op === 'and';
    return value.c.every((child) => ('op' in child ? completeConditions(child, false) : Boolean(child.type)));
}
export function ConditionEditor({
    value,
    catalogue,
    onChange,
    prefix = 'condition',
    depth = 0,
}: {
    value: ConditionTree | null;
    catalogue: AutomationCatalogue;
    onChange: (tree: ConditionTree | null) => void;
    prefix?: string;
    depth?: number;
}) {
    const node = value ?? { op: 'and', c: [] };
    const change = (children: Child[]) => onChange(children.length ? updateChildren(node, children) : null);
    return (
        <div className="vy-auto-condition-group">
            <div className="vy-auto-toolbar">
                <label className="vy-auto-field">
                    <span>{s.workspace.match}</span>
                    <select
                        aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: s.workspace.match, p1: prefix })}
                        value={node.op}
                        onChange={(e) => onChange(updateChildren(node, node.c, e.target.value as ConditionTree['op']))}
                    >
                        {Object.entries(s.workspace.operators).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                </label>
                {depth < 8 && (
                    <Button size="sm" variant="outline" onClick={() => change([...node.c, { op: 'and', c: [] }])}>
                        <Plus size={14} />
                        {s.workspace.addGroup}
                    </Button>
                )}
            </div>
            {!node.c.length && <p className="vy-auto-muted">{s.workspace.noConditions}</p>}
            {node.c.map((child, index) => {
                const childPrefix = `${prefix}-${index}`;
                const replace = (next: Child | null) =>
                    change(
                        next === null
                            ? node.c.filter((_, i) => i !== index)
                            : node.c.map((entry, i) => (i === index ? next : entry)),
                    );
                if ('op' in child)
                    return (
                        <div className="vy-auto-condition-nested" key={index}>
                            <div className="vy-auto-toolbar">
                                <strong>
                                    {s.workspace.group} {index + 1}
                                </strong>
                                <Button size="sm" variant="ghost" onClick={() => replace(null)}>
                                    <Trash2 size={14} />
                                    {s.workspace.removeGroup}
                                </Button>
                            </div>
                            <ConditionEditor
                                value={child}
                                catalogue={catalogue}
                                onChange={replace}
                                prefix={childPrefix}
                                depth={depth + 1}
                            />
                        </div>
                    );
                const descriptor = catalogue.conditions.find((item) => item.key === child.type);
                return (
                    <div key={index} className="vy-auto-condition-leaf">
                        <div className="vy-auto-toolbar">
                            <strong>{providerLabel(descriptor, child.type)}</strong>
                            <Button size="sm" variant="ghost" onClick={() => replace(null)}>
                                {s.editor.conditionRemove}
                            </Button>
                        </div>
                        {descriptor ? (
                            <ParamsForm
                                idPrefix={childPrefix}
                                schema={descriptor.params_schema}
                                value={child.payload ?? {}}
                                emptyNote={s.common.none}
                                onChange={(payload) => replace({ ...child, payload })}
                            />
                        ) : (
                            <p className="vy-auto-muted">{s.workspace.preserved}</p>
                        )}
                    </div>
                );
            })}
            <label className="vy-auto-field">
                <span>{s.editor.conditionAdd}</span>
                <select
                    value=""
                    onChange={(e) => {
                        if (e.target.value) change([...node.c, { type: e.target.value, payload: {} }]);
                    }}
                >
                    <option value="">{s.editor.conditionPick}</option>
                    {catalogue.conditions.map((item) => (
                        <option key={item.key} value={item.key}>
                            {providerLabel(item)}
                        </option>
                    ))}
                </select>
            </label>
        </div>
    );
}
