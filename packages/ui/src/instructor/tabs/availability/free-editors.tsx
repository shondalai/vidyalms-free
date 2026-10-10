import { translate as uiText } from '@/shared/i18n';
import { conditionEditorRegistry, type ConditionEditorProps, type ConditionTarget } from '@/condition-editors';
import { flowStrings } from '../../flow-strings';
import { CheckboxField, Field, Select, TextInput } from '../../components/field';

/*
 * The editors the free build ships, one per free condition.
 *
 * The generic schema-driven form is always available and is what a condition
 * with no registered editor gets, so these exist for a different reason: the
 * three surprises in this vocabulary are all things a JSON Schema cannot
 * say, and every one of them is a documented support cost somewhere else.
 *
 *   - `score`'s maximum is EXCLUSIVE while its minimum is inclusive, which
 *     is the single most reported surprise in the product this is taken
 *     from. The warning is printed inline beside the field.
 *   - `completion`'s pass states only mean anything against a target that
 *     carries a pass mark, so they are offered only for those and the picker
 *     says why. That is the friendly duplicate of a save-time refusal, never
 *     the check itself.
 *   - `completion`'s target may be "the previous item", which survives
 *     reordering and a course copy where a fixed id does not, and which no
 *     numeric id field would ever suggest.
 *
 * Registered through the same function a package uses, so the seam is
 * exercised by every mount rather than by nobody.
 */

const strings = flowStrings.availability;

function str(payload: Record<string, unknown>, key: string): string {
    const value = payload[key];
    return typeof value === 'string' ? value : '';
}

function num(payload: Record<string, unknown>, key: string): string {
    const value = payload[key];
    return typeof value === 'number' ? String(value) : '';
}

function targetOf(payload: Record<string, unknown>): { kind: string; id: number } {
    const target = payload.target;
    if (typeof target === 'object' && target !== null) {
        const record = target as Record<string, unknown>;
        return {
            kind: typeof record.kind === 'string' ? record.kind : 'lesson',
            id: typeof record.id === 'number' ? record.id : -1,
        };
    }
    return { kind: 'lesson', id: -1 };
}

function targetValue(target: { kind: string; id: number }): string {
    return `${target.kind}:${target.id}`;
}

function parseTargetValue(raw: string): { kind: string; id: number } {
    const [kind, id] = raw.split(':');
    return { kind: kind ?? 'lesson', id: Number(id ?? -1) };
}

/** The chosen target's own row, for the pass-mark rule. */
function findTarget(targets: readonly ConditionTarget[], target: { kind: string; id: number }): ConditionTarget | null {
    return targets.find((entry) => entry.kind === target.kind && entry.id === target.id) ?? null;
}

function DateEditor({ payload, onChange, controlId, errors }: ConditionEditorProps) {
    const from = str(payload, 'from');
    const until = str(payload, 'until');
    const tz = str(payload, 'tz');
    return (
        <div className="grid gap-2.5 sm:grid-cols-2">
            <Field label={strings.date.from} error={errors?.from}>
                {({ id, describedBy, invalid }) => (
                    <TextInput
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                        value={from}
                        onChange={(event) =>
                            onChange({ ...payload, from: event.target.value.trim() === '' ? null : event.target.value })
                        }
                    />
                )}
            </Field>
            <Field label={strings.date.until} hint={strings.date.untilNote} error={errors?.until}>
                {({ id, describedBy, invalid }) => (
                    <TextInput
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                        value={until}
                        onChange={(event) =>
                            onChange({
                                ...payload,
                                until: event.target.value.trim() === '' ? null : event.target.value,
                            })
                        }
                    />
                )}
            </Field>
            <Field className="sm:col-span-2" label={strings.date.tz} hint={strings.date.tzHint}>
                {({ id, describedBy }) => (
                    <TextInput
                        id={id}
                        aria-describedby={describedBy}
                        placeholder={uiText("vidyalms.ui.d851b36dde762145", "Europe/London")}
                        value={tz}
                        data-testid={controlId('tz')}
                        onChange={(event) =>
                            onChange({ ...payload, tz: event.target.value.trim() === '' ? null : event.target.value })
                        }
                    />
                )}
            </Field>
        </div>
    );
}

function CompletionEditor({ payload, onChange, targets, errors }: ConditionEditorProps) {
    const target = targetOf(payload);
    const state = str(payload, 'state') === '' ? 'complete' : str(payload, 'state');
    const chosen = findTarget(targets, target);
    /* The pass states are offered only where they can ever be satisfied. A
       leaf naming one against a target with no pass mark evaluates blocked
       forever and sits in the denominator, so the save refuses it too. */
    const passAllowed = target.id === -1 || chosen?.hasPassMark === true;
    return (
        <div className="grid gap-2.5 sm:grid-cols-2">
            <Field label={strings.completion.target} hint={target.id === -1 ? strings.completion.previousHint : undefined} error={errors?.target}>
                {({ id, describedBy, invalid }) => (
                    <Select
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={targetValue(target)}
                        onChange={(event) => onChange({ ...payload, target: parseTargetValue(event.target.value) })}
                    >
                        <option value="lesson:-1">{strings.completion.previous}</option>
                        {targets.map((entry) => (
                            <option key={`${entry.kind}:${entry.id}`} value={`${entry.kind}:${entry.id}`}>
                                {entry.title}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <Field
                label={strings.completion.state}
                hint={passAllowed ? undefined : strings.completion.passOnly}
                error={errors?.state}
            >
                {({ id, describedBy, invalid }) => (
                    <Select
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={state}
                        onChange={(event) => onChange({ ...payload, state: event.target.value })}
                    >
                        <option value="complete">{strings.completion.states.complete}</option>
                        <option value="incomplete">{strings.completion.states.incomplete}</option>
                        {(passAllowed || state === 'complete_pass') && (
                            <option value="complete_pass">{strings.completion.states.complete_pass}</option>
                        )}
                        {(passAllowed || state === 'complete_fail') && (
                            <option value="complete_fail">{strings.completion.states.complete_fail}</option>
                        )}
                    </Select>
                )}
            </Field>
            {targets.length === 0 && <p className="text-[11.5px] text-fg-2 sm:col-span-2">{strings.completion.noTargets}</p>}
        </div>
    );
}

function ScoreEditor({ payload, onChange, targets, errors }: ConditionEditorProps) {
    const target = targetOf(payload);
    const scoreTargets = targets.filter((entry) => entry.kind !== 'module' && entry.kind !== 'assignment');
    return (
        <div className="grid gap-2.5 sm:grid-cols-3">
            <Field className="sm:col-span-3" label={strings.score.target} error={errors?.target}>
                {({ id, describedBy, invalid }) => (
                    <Select
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={targetValue(target)}
                        onChange={(event) => onChange({ ...payload, target: parseTargetValue(event.target.value) })}
                    >
                        {scoreTargets.map((entry) => (
                            <option key={`${entry.kind}:${entry.id}`} value={`${entry.kind}:${entry.id}`}>
                                {entry.title}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <Field label={strings.score.min} error={errors?.min}>
                {({ id, describedBy, invalid }) => (
                    <TextInput
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        inputMode="decimal"
                        value={num(payload, 'min')}
                        onChange={(event) =>
                            onChange({
                                ...payload,
                                min: event.target.value.trim() === '' ? null : Number(event.target.value),
                            })
                        }
                    />
                )}
            </Field>
            <Field className="sm:col-span-2" label={strings.score.max} hint={strings.score.exclusiveMax} error={errors?.max}>
                {({ id, describedBy, invalid }) => (
                    <TextInput
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        inputMode="decimal"
                        value={num(payload, 'max')}
                        onChange={(event) =>
                            onChange({
                                ...payload,
                                max: event.target.value.trim() === '' ? null : Number(event.target.value),
                            })
                        }
                    />
                )}
            </Field>
        </div>
    );
}

/** The seven capability constants, which are a closed core vocabulary. */
const CAPABILITIES = [
    'vidyalms.admin',
    'vidyalms.manage',
    'vidyalms.teach',
    'vidyalms.grade',
    'vidyalms.enrol',
    'vidyalms.reports',
    'vidyalms.manage_groups',
] as const;

function RoleEditor({ payload, onChange, errors }: ConditionEditorProps) {
    const capability = str(payload, 'capability') === '' ? 'vidyalms.teach' : str(payload, 'capability');
    return (
        <Field label={strings.role.capability} hint={strings.role.hint} error={errors?.capability}>
            {({ id, describedBy, invalid }) => (
                <Select
                    id={id}
                    aria-describedby={describedBy}
                    invalid={invalid}
                    value={capability}
                    onChange={(event) => onChange({ ...payload, capability: event.target.value })}
                >
                    {!(CAPABILITIES as readonly string[]).includes(capability) && (
                        <option value={capability}>{capability}</option>
                    )}
                    {CAPABILITIES.map((key) => (
                        <option key={key} value={key}>
                            {key}
                        </option>
                    ))}
                </Select>
            )}
        </Field>
    );
}

/** The entitlement sources the free build writes. */
const SOURCES = ['manual', 'open', 'purchase', 'subscription', 'coupon', 'seat', 'import'] as const;

function EnrolmentSourceEditor({ payload, onChange, errors }: ConditionEditorProps) {
    const raw = payload.sources;
    const chosen = Array.isArray(raw) ? raw.filter((entry): entry is string => typeof entry === 'string') : [];
    const toggle = (source: string, on: boolean) => {
        const next = on ? [...new Set([...chosen, source])] : chosen.filter((entry) => entry !== source);
        onChange({ ...payload, sources: next });
    };
    return (
        <fieldset className="grid gap-2">
            <legend className="font-display text-xs font-semibold text-fg">{strings.enrolmentSource.sources}</legend>
            <p className="text-[11.5px] text-fg-2">{strings.enrolmentSource.hint}</p>
            <div className="flex flex-wrap gap-x-4 gap-y-1.5">
                {SOURCES.map((source) => (
                    <CheckboxField
                        key={source}
                        label={source}
                        checked={chosen.includes(source)}
                        onChange={(event) => toggle(source, event.target.checked)}
                    />
                ))}
            </div>
            {errors?.sources !== undefined && (
                <p className="text-[11.5px] font-medium text-danger">{errors.sources}</p>
            )}
            {chosen.length === 0 && <p className="text-[11.5px] text-fg-2">{strings.enrolmentSource.empty}</p>}
        </fieldset>
    );
}

function summariseDate(payload: Record<string, unknown>): string | null {
    const from = str(payload, 'from');
    const until = str(payload, 'until');
    if (from !== '' && until !== '') {
        return `${from} to ${until}`;
    }
    if (from !== '') {
        return `from ${from}`;
    }
    return until === '' ? null : `until ${until}`;
}

let installed = false;

/**
 * Installs the free editors. Idempotent, because the workbench entry, the
 * admin entry and every test file all reach the same page-level registry and
 * a second registration of a taken key throws by design.
 */
export function registerFreeConditionEditors(): void {
    if (installed) {
        return;
    }
    const registry = conditionEditorRegistry();
    const modules: Array<[string, Parameters<typeof registry.register>[1]]> = [
        [
            'date',
            { version: 1, Editor: DateEditor, summarise: (payload) => summariseDate(payload) },
        ],
        [
            'completion',
            {
                version: 1,
                Editor: CompletionEditor,
                summarise: (payload) => {
                    const state = str(payload, 'state');
                    return strings.completion.states[state === '' ? 'complete' : state] ?? null;
                },
            },
        ],
        [
            'score',
            {
                version: 1,
                Editor: ScoreEditor,
                summarise: (payload) => {
                    const min = num(payload, 'min');
                    const max = num(payload, 'max');
                    if (min !== '' && max !== '') {
                        return `${min}% to under ${max}%`;
                    }
                    if (min !== '') {
                        return `${min}% or more`;
                    }
                    return max === '' ? null : `under ${max}%`;
                },
            },
        ],
        ['role', { version: 1, Editor: RoleEditor, summarise: (payload) => str(payload, 'capability') || null }],
        [
            'enrolment_source',
            {
                version: 1,
                Editor: EnrolmentSourceEditor,
                summarise: (payload) => {
                    const raw = payload.sources;
                    return Array.isArray(raw) && raw.length > 0 ? raw.join(', ') : null;
                },
            },
        ],
    ];
    for (const [key, module] of modules) {
        if (!registry.has(key)) {
            registry.register(key, module);
        }
    }
    installed = true;
}

/** Test-only: the module-scope guard would otherwise leak between suites. */
export function resetFreeConditionEditors(): void {
    installed = false;
}
