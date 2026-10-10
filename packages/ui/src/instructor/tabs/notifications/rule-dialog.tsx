import { translate as uiText } from '@/shared/i18n';
import { useMemo, useState } from 'react';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
} from '@/shared/components';
import { useAvailabilityCatalogue, useCreateNotificationRule, useUpdateNotificationRule } from '@/shared/flow';
import type { ConditionTarget } from '@/condition-editors';
import { flowStrings } from '../../flow-strings';
import { CheckboxField, Field, Select, TextInput } from '../../components/field';
import { emptyTree, isEmptyTree, normaliseNode } from '../../lib/availability-tree';
import { AvailabilityTreeEditor } from '../availability/restriction-box';
import type {
    AvailabilityNode,
    NotificationCatalogue,
    NotificationRule,
    NotificationSchedule,
} from '../../lib/flow-types';
import { TemplateEditor } from './template-editor';

/*
 * One notification rule.
 *
 * Nothing on this form is typed from memory: the triggers, the audiences,
 * the channels, the categories, the schedule kinds and the anchors all come
 * from the catalogue route, which enumerates registries. A package that adds
 * a trigger appears here with no client change, which is the whole point of
 * the seam.
 *
 * The MESSAGE CLASS is read from the trigger and is not settable. What a
 * reasonable recipient would conclude from the subject line is a property of
 * the message, not of the moment; making it a field would let a site set a
 * marketing rule to transactional and thereby bypass consent, drop the
 * unsubscribe headers and defeat the marketing objection rule in one form
 * control.
 *
 * The conditions box is the SAME grammar as a content restriction, on the
 * notification surface. A leaf used outside its declared surface is refused
 * at save, so the picker asks the contract for the notification surface
 * rather than filtering the content list and hoping.
 */

const strings = flowStrings.notifications;

function scheduleOf(rule: NotificationRule | null): NotificationSchedule {
    return rule?.schedule ?? { kind: 'immediate' };
}

export function NotificationRuleDialog({
    catalogue,
    rule,
    courseId,
    locale,
    targets,
    onClose,
}: {
    catalogue: NotificationCatalogue;
    rule: NotificationRule | null;
    courseId: number | null;
    locale: string;
    targets: readonly ConditionTarget[];
    onClose: () => void;
}) {
    const create = useCreateNotificationRule();
    const update = useUpdateNotificationRule();
    const conditionCatalogue = useAvailabilityCatalogue('notification_rule');

    const [ruleKey, setRuleKey] = useState(rule?.rule_key ?? '');
    const [triggerKey, setTriggerKey] = useState(rule?.trigger_key ?? (catalogue.triggers[0]?.key ?? ''));
    const [audienceKey, setAudienceKey] = useState(rule?.audience_key ?? '');
    const [categoryKey, setCategoryKey] = useState(rule?.category_key ?? '');
    const [templateKey, setTemplateKey] = useState(rule?.template_key ?? '');
    const [channels, setChannels] = useState<string[]>(rule?.channels ?? ['email']);
    const [schedule, setSchedule] = useState<NotificationSchedule>(() => scheduleOf(rule));
    const [conditions, setConditions] = useState<AvailabilityNode | null>(rule?.conditions ?? null);
    const [suppresses, setSuppresses] = useState<string[]>(rule?.suppresses ?? []);
    const [enabled, setEnabled] = useState(rule?.state === 'enabled');

    const trigger = useMemo(
        () => catalogue.triggers.find((entry) => entry.key === triggerKey) ?? null,
        [catalogue.triggers, triggerKey],
    );

    /* Class and default audience follow the trigger. Choosing a trigger and
       then leaving the audience empty is the common case, so the default is
       filled in rather than left to be discovered on a 422. */
    const effectiveAudience = audienceKey === '' ? (trigger?.default_audience_key ?? '') : audienceKey;
    const effectiveCategory = categoryKey === '' ? (trigger?.category_key ?? '') : categoryKey;
    const messageClass = trigger?.class ?? 'transactional';

    const pending = create.isPending || update.isPending;
    const error = create.error ?? update.error;
    const params = (schedule.params ?? {}) as Record<string, unknown>;

    const setParam = (key: string, value: unknown) =>
        setSchedule((current) => ({ ...current, params: { ...(current.params ?? {}), [key]: value } }));

    const submit = () => {
        const body = {
            rule_key: ruleKey.trim(),
            trigger_key: triggerKey,
            audience_key: effectiveAudience,
            category_key: effectiveCategory,
            template_key: templateKey.trim(),
            channels,
            schedule,
            conditions: isEmptyTree(conditions) ? null : conditions,
            suppresses,
            course_id: courseId,
            state: enabled ? ('enabled' as const) : ('disabled' as const),
        };
        if (rule === null) {
            create.mutate({ body }, { onSuccess: onClose });
            return;
        }
        update.mutate({ ruleId: rule.id, body }, { onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-3xl">
                <DialogTitle>{rule === null ? strings.add : strings.title}</DialogTitle>
                <DialogDescription>{strings.intro}</DialogDescription>
                <div className="mt-3.5 grid max-h-[560px] gap-3.5 overflow-y-auto pr-1">
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label={strings.fields.ruleKey} hint={strings.fields.ruleKeyHint}>
                            {({ id, describedBy }) => (
                                <TextInput
                                    id={id}
                                    aria-describedby={describedBy}
                                    autoFocus
                                    value={ruleKey}
                                    onChange={(event) => setRuleKey(event.target.value)}
                                />
                            )}
                        </Field>
                        <Field label={strings.fields.trigger}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={triggerKey}
                                    onChange={(event) => setTriggerKey(event.target.value)}
                                >
                                    {catalogue.triggers.map((entry) => (
                                        <option key={entry.key} value={entry.key}>
                                            {entry.label}
                                            {entry.available === false ? uiText("vidyalms.ui.0337c2b7db52821f", " ({p0})", { p0: flowStrings.common.unavailable }) : ''}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <Field label={strings.fields.audience}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={effectiveAudience}
                                    onChange={(event) => setAudienceKey(event.target.value)}
                                >
                                    {catalogue.audiences.map((entry) => (
                                        <option key={entry.key} value={entry.key}>
                                            {entry.label}
                                            {entry.available === false ? uiText("vidyalms.ui.0337c2b7db52821f", " ({p0})", { p0: flowStrings.common.unavailable }) : ''}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <Field label={strings.fields.category} hint={strings.fields.categoryHint}>
                            {({ id, describedBy }) => (
                                <Select
                                    id={id}
                                    aria-describedby={describedBy}
                                    value={effectiveCategory}
                                    onChange={(event) => setCategoryKey(event.target.value)}
                                >
                                    {catalogue.categories.map((entry) => (
                                        <option key={entry.key} value={entry.key}>
                                            {entry.label}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <div className="grid gap-1">
                            <span className="font-display text-xs font-semibold text-fg">
                                {strings.fields.messageClass}
                            </span>
                            <span>
                                <Badge variant={messageClass === 'marketing' ? 'warn' : 'neutral'}>
                                    {strings.classes[messageClass] ?? messageClass}
                                </Badge>
                            </span>
                            <p className="text-[11.5px] text-fg-2">{strings.classHint[messageClass]}</p>
                            <p className="text-[11.5px] text-fg-2">{strings.fields.messageClassHint}</p>
                        </div>
                        <Field label={strings.fields.template}>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    value={templateKey}
                                    onChange={(event) => setTemplateKey(event.target.value)}
                                />
                            )}
                        </Field>
                    </div>

                    <fieldset className="grid gap-1.5">
                        <legend className="font-display text-xs font-semibold text-fg">
                            {strings.fields.channels}
                        </legend>
                        <div className="flex flex-wrap gap-x-4 gap-y-1.5">
                            {catalogue.channels.map((entry) => (
                                <CheckboxField
                                    key={entry.key}
                                    label={
                                        entry.available === false
                                            ? uiText("vidyalms.ui.ff46a851b217a7a1", "{p0} ({p1})", { p0: entry.label, p1: flowStrings.common.unavailable })
                                            : entry.label
                                    }
                                    checked={channels.includes(entry.key)}
                                    onChange={(event) =>
                                        setChannels((current) =>
                                            event.target.checked
                                                ? [...new Set([...current, entry.key])]
                                                : current.filter((key) => key !== entry.key),
                                        )
                                    }
                                />
                            ))}
                        </div>
                    </fieldset>

                    <div className="grid gap-2.5 sm:grid-cols-2">
                        <Field label={strings.fields.schedule}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={schedule.kind}
                                    onChange={(event) =>
                                        setSchedule({
                                            kind: event.target.value as NotificationSchedule['kind'],
                                            anchor_key: schedule.anchor_key,
                                            params: {},
                                        })
                                    }
                                >
                                    {catalogue.schedule_kinds.map((entry) => (
                                        <option key={entry.key} value={entry.key}>
                                            {strings.fields.scheduleKinds[entry.key] ?? entry.label}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        {(schedule.kind === 'relative' || schedule.kind === 'recurring_window') && (
                            <Field label={strings.fields.anchor}>
                                {({ id }) => (
                                    <Select
                                        id={id}
                                        value={schedule.anchor_key ?? ''}
                                        onChange={(event) =>
                                            setSchedule((current) => ({ ...current, anchor_key: event.target.value }))
                                        }
                                    >
                                        {catalogue.anchors.map((anchor) => (
                                            <option key={anchor.key} value={anchor.key}>
                                                {anchor.label}
                                                {anchor.available === false
                                                    ? uiText("vidyalms.ui.0337c2b7db52821f", " ({p0})", { p0: flowStrings.common.unavailable })
                                                    : ''}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        )}
                        {schedule.kind === 'absolute' && (
                            <Field label={strings.fields.at}>
                                {({ id }) => (
                                    <TextInput
                                        id={id}
                                        placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                                        value={typeof params.at === 'string' ? params.at : ''}
                                        onChange={(event) => setParam('at', event.target.value)}
                                    />
                                )}
                            </Field>
                        )}
                        {schedule.kind === 'relative' && (
                            <>
                                <Field label={strings.fields.offset}>
                                    {({ id }) => (
                                        <TextInput
                                            id={id}
                                            inputMode="numeric"
                                            value={
                                                typeof params.offset_seconds === 'number'
                                                    ? String(params.offset_seconds)
                                                    : ''
                                            }
                                            onChange={(event) =>
                                                setParam('offset_seconds', Number(event.target.value))
                                            }
                                        />
                                    )}
                                </Field>
                                <Field label={strings.fields.offsetUnit}>
                                    {({ id }) => (
                                        <Select
                                            id={id}
                                            value={typeof params.offset_unit === 'string' ? params.offset_unit : 'days'}
                                            onChange={(event) => setParam('offset_unit', event.target.value)}
                                        >
                                            <option value="days">
                                                {flowStrings.cohorts.schedule.units.days}
                                            </option>
                                            <option value="hours">
                                                {flowStrings.cohorts.schedule.units.hours}
                                            </option>
                                        </Select>
                                    )}
                                </Field>
                            </>
                        )}
                        {schedule.kind === 'recurring_window' && (
                            <>
                                <Field label={strings.fields.every}>
                                    {({ id }) => (
                                        <TextInput
                                            id={id}
                                            inputMode="numeric"
                                            value={typeof params.every === 'number' ? String(params.every) : ''}
                                            onChange={(event) => setParam('every', Number(event.target.value))}
                                        />
                                    )}
                                </Field>
                                <Field label={strings.fields.until}>
                                    {({ id }) => (
                                        <TextInput
                                            id={id}
                                            inputMode="numeric"
                                            value={typeof params.until === 'number' ? String(params.until) : ''}
                                            onChange={(event) => setParam('until', Number(event.target.value))}
                                        />
                                    )}
                                </Field>
                            </>
                        )}
                    </div>

                    <section className="grid gap-2 rounded border border-border bg-bg p-3">
                        <div>
                            <p className="font-display text-xs font-semibold text-fg">{strings.fields.conditions}</p>
                            <p className="text-[11.5px] text-fg-2">{strings.fields.conditionsHint}</p>
                        </div>
                        {conditionCatalogue.data !== undefined && (
                            <AvailabilityTreeEditor
                                node={conditions ?? emptyTree()}
                                path=""
                                depth={1}
                                catalogue={conditionCatalogue.data}
                                targets={targets}
                                locale={locale}
                                onChange={(next) => setConditions(normaliseNode(next))}
                            />
                        )}
                    </section>

                    <TemplateEditor
                        templateKey={templateKey.trim()}
                        channelKeys={channels}
                        locale={locale}
                    />

                    <Field label={strings.fields.suppresses} hint={strings.fields.suppressesHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                value={suppresses.join(', ')}
                                onChange={(event) =>
                                    setSuppresses(
                                        event.target.value
                                            .split(',')
                                            .map((entry) => entry.trim())
                                            .filter((entry) => entry !== ''),
                                    )
                                }
                            />
                        )}
                    </Field>

                    <CheckboxField
                        label={strings.states.enabled ?? uiText("vidyalms.ui.130011756125313c", "On")}
                        hint={strings.disabledNote}
                        checked={enabled}
                        onChange={(event) => setEnabled(event.target.checked)}
                    />

                    {error !== null && <ErrorState error={error} />}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {flowStrings.common.cancel}
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={
                            pending || ruleKey.trim() === '' || templateKey.trim() === '' || channels.length === 0
                        }
                    >
                        {strings.save}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
