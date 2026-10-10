import { translate as uiText } from '@/shared/i18n';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Accessibility, ChevronLeft, Eye, FileQuestion, Save } from 'lucide-react';
import {
    QuestionAuthoringBlock,
    QuestionHost,
    blankQuestionValues,
    questionDefinition,
    questionFormSchema,
    questionFormValues,
    useQuestionTypeCatalogue,
    type QuestionDefinition,
    type QuestionFormValues,
    readResponseShape,
} from '@/question-types';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    Icon,
    SaveIndicator,
} from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import {
    useCreateQuestionBankEntry,
    useCreateQuestionBankEntryVersion,
    useQuestionBank,
    useQuestionBankCategories,
    useQuestionBankEntry,
    useQuestionBankEntryVersions,
    useQuestionBankEntryUsage,
    useSetQuestionBankEntryObjectives,
    useSetQuestionBankEntryTags,
    useUpdateQuestionBankEntry,
} from '@/instructor/assessment-api';
import { findPickerEntry, pickerEntries, seedDefinition, seedScored } from '@/instructor/lib/question-picker';
import type { QuestionCategory } from '@/instructor/lib/assessment-types';
import { useSaveTracker } from '@/instructor/lib/save-state';
import { PageHeader } from '../components/page-header';
import { ScreenError } from '../components/states';
import { BankField, BankInput, BankSelect, BankTextArea, EditorSection } from './fields';
import { ObjectivePicker, type ObjectiveChoice } from './objective-picker';
import { SchemaParams } from './schema-params';
import { bankStrings as strings } from './strings';
import { QuestionTypeGallery } from './type-gallery';
import { Tabs } from '@/instructor/components/tabs';
import { useNavigationGuard } from '@/shared/navigation-guard';

function commaList(raw: string): string[] {
    return [
        ...new Set(
            raw
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean),
        ),
    ];
}

function policyOf(definition: QuestionDefinition, field: string): string {
    const value = definition[field];
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return '';
    }
    const key = (value as Record<string, unknown>).key;
    return typeof key === 'string' ? key : '';
}

function policyParamsOf(definition: QuestionDefinition, field: string): Record<string, unknown> {
    const value = definition[field];
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return {};
    }
    const params = (value as Record<string, unknown>).params;
    return typeof params === 'object' && params !== null && !Array.isArray(params)
        ? (params as Record<string, unknown>)
        : {};
}

function flattenCategoryTree(
    categories: readonly QuestionCategory[],
    depth = 0,
): { category: QuestionCategory; depth: number }[] {
    return categories.flatMap((category) => [
        { category, depth },
        ...flattenCategoryTree(category.children ?? [], depth + 1),
    ]);
}

function schemaDeclaresDefinitionVersion(schema: unknown): boolean {
    if (typeof schema !== 'object' || schema === null || Array.isArray(schema)) {
        return false;
    }
    const record = schema as Record<string, unknown>;
    const properties = record.properties;
    const declared =
        typeof properties === 'object' &&
        properties !== null &&
        !Array.isArray(properties) &&
        Object.prototype.hasOwnProperty.call(properties, 'schema_version');
    const required = Array.isArray(record.required) && record.required.includes('schema_version');
    return declared || required;
}

function definitionForWire(
    definition: QuestionDefinition,
    schema: unknown,
    definitionVersion: number,
): QuestionDefinition {
    const withoutEmbeddedVersion = { ...definition };
    delete withoutEmbeddedVersion.schema_version;
    return schemaDeclaresDefinitionVersion(schema)
        ? { ...withoutEmbeddedVersion, schema_version: definitionVersion }
        : withoutEmbeddedVersion;
}

function compactDate(value: string | undefined): string | null {
    if (value === undefined || value === '') {
        return null;
    }
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}

function VersionUsagePanel({
    versions,
    usage,
}: {
    versions: ReturnType<typeof useQuestionBankEntryVersions>;
    usage: ReturnType<typeof useQuestionBankEntryUsage>;
}) {
    return (
        <section
            aria-labelledby="vy-version-usage-title"
            className="grid gap-3 rounded-lg border border-border bg-surface p-4 text-xs text-fg-2"
        >
            <div>
                <h2 id="vy-version-usage-title" className="font-display text-sm font-semibold text-fg">
                    {uiText("vidyalms.ui.99d5eafbc55abe69", "Versions &amp; usage")}</h2>
                <p className="mt-1">
                    {uiText("vidyalms.ui.83853afbdf726cdf", "Review immutable versions and every current quiz placement before editing.")}</p>
            </div>
            <div className="grid gap-1.5">
                <h3 className="font-display font-semibold text-fg">{uiText("vidyalms.ui.a6df11e706c514f1", "Version history")}</h3>
                {versions.isPending && <p role="status">{uiText("vidyalms.ui.beba3226b015a5fd", "Loading version history…")}</p>}
                {versions.isError && (
                    <ScreenError error={versions.error} onRetry={() => void versions.refetch()} />
                )}
                {versions.isSuccess && versions.data.length === 0 && <p>{uiText("vidyalms.ui.1309520f0e80e076", "No versions have been saved.")}</p>}
                {versions.isSuccess && versions.data.length > 0 && (
                    <ul
                        aria-label={uiText("vidyalms.ui.75f47aa414c129eb", "Question version history")}
                        className="divide-y divide-border overflow-hidden rounded border border-border"
                    >
                        {versions.data.map((version) => {
                            const created = compactDate(version.created_at);
                            return (
                                <li
                                    key={version.id}
                                    className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 bg-bg px-3 py-2"
                                >
                                    <span className="font-semibold text-fg">
                                        {uiText("vidyalms.ui.92b2c95e99f02710", "Version ")}{version.version} · {version.status}
                                    </span>
                                    <span>
                                        {version.attempts_served ?? 0} {uiText("vidyalms.ui.15031036e01d9eda", " served")}{created === null ? '' : uiText("vidyalms.ui.04420b9dfe3f0a87", " · {p0}", { p0: created })}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
            <div className="grid gap-1.5">
                <h3 className="font-display font-semibold text-fg">{uiText("vidyalms.ui.5fbda7a8d90e650f", "Quiz placements")}</h3>
                {usage.isPending && <p role="status">{uiText("vidyalms.ui.0134e99d31350d7d", "Loading usage…")}</p>}
                {usage.isError && <ScreenError error={usage.error} onRetry={() => void usage.refetch()} />}
                {usage.isSuccess && usage.data.placements.length === 0 && (
                    <p>{uiText("vidyalms.ui.dd64042f9270b856", "This question is not placed in a quiz.")}</p>
                )}
                {usage.isSuccess && usage.data.placements.length > 0 && (
                    <ul
                        aria-label={uiText("vidyalms.ui.0fd8e7ef7042a456", "Question quiz placements")}
                        className="divide-y divide-border overflow-hidden rounded border border-border"
                    >
                        {usage.data.placements.map((placement) => (
                            <li key={placement.slot_id} className="grid gap-0.5 bg-bg px-3 py-2">
                                <span className="font-semibold text-fg">{placement.quiz_title}</span>
                                <span>
                                    {placement.kind === 'pool' ? uiText("vidyalms.ui.b32bc6d41cc39fb2", "Pool placement") : uiText("vidyalms.ui.6a9930ecbcb4bf67", "Direct question")} ·{' '}
                                    {placement.question_version_id === null
                                        ? uiText("vidyalms.ui.ec9692966dc8c557", "Latest version at attempt start")
                                        : uiText("vidyalms.ui.7897673023a39183", "Pinned version record {p0}", { p0: placement.question_version_id })}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

export function QuestionEditor({
    bankId,
    questionId,
    preset,
    locale,
    navigate,
}: {
    bankId: number;
    questionId: number | null;
    preset: string | null;
    locale: string;
    navigate: (hash: string) => void;
}) {
    const editing = questionId !== null;
    const bank = useQuestionBank(bankId);
    const entry = useQuestionBankEntry(bankId, questionId);
    const usage = useQuestionBankEntryUsage(bankId, questionId);
    const versions = useQuestionBankEntryVersions(bankId, questionId);
    const categories = useQuestionBankCategories(bankId);
    const catalogue = useQuestionTypeCatalogue();
    const create = useCreateQuestionBankEntry();
    const createVersion = useCreateQuestionBankEntryVersion();
    const updateEntry = useUpdateQuestionBankEntry();
    const setTags = useSetQuestionBankEntryTags();
    const setEntryObjectives = useSetQuestionBankEntryObjectives();
    const save = useSaveTracker();
    const reactivateSave = useSaveTracker();
    const announce = useAnnounce();
    const initialized = useRef(false);
    const handledSave = useRef<number | null>(null);
    const handledReactivate = useRef<number | null>(null);
    const [pickerKey, setPickerKey] = useState(preset ?? '');
    const [type, setType] = useState('');
    const [storedDefinition, setStoredDefinition] = useState<QuestionDefinition>({});
    const [definitionVersion, setDefinitionVersion] = useState<number | null>(null);
    const [policy, setPolicy] = useState('');
    const [policyParams, setPolicyParams] = useState<Record<string, unknown>>({});
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [status, setStatus] = useState<'draft' | 'ready' | 'archived'>('draft');
    const [difficulty, setDifficulty] = useState('');
    const [estimatedTime, setEstimatedTime] = useState('');
    const [tags, setTagText] = useState('');
    const [objectives, setObjectiveChoices] = useState<ObjectiveChoice[]>([]);
    const [scored, setScored] = useState(true);
    const [previewResponse, setPreviewResponse] = useState<unknown>(null);
    const [previewState, setPreviewState] = useState<string | null>(null);
    const [section, setSection] = useState<keyof typeof strings.studio.tabs>('content');
    const [detailsDirty, setDetailsDirty] = useState(false);
    const [choosingFormat, setChoosingFormat] = useState(false);
    const [pending, setPending] = useState<{ action: () => void; format: boolean } | null>(null);

    const schema = useMemo(() => questionFormSchema(type), [type]);
    const form = useForm<QuestionFormValues>({
        resolver: zodResolver(schema),
        defaultValues: { ...blankQuestionValues(type), points: '1' },
    });
    const watched = useWatch({ control: form.control }) as QuestionFormValues;
    const dirty = save.state !== 'saved' && (form.formState.isDirty || detailsDirty);
    useNavigationGuard(dirty);
    const request = (action: () => void, format = false) => {
        if (dirty) setPending({ action, format });
        else action();
    };
    const back = () => request(() => navigate(`/banks/${bankId}/questions`));
    const picker = useMemo(
        () => pickerEntries(catalogue.data?.types ?? [], catalogue.data?.presets ?? []),
        [catalogue.data],
    );

    const choose = useCallback(
        (key: string) => {
            const choice = findPickerEntry(picker, key);
            setPickerKey(key);
            setChoosingFormat(false);
            setSection('content');
            if (choice === null) {
                setType('');
                setStoredDefinition({});
                setDefinitionVersion(null);
                return;
            }
            const seed = seedDefinition(choice);
            setType(choice.type);
            setStoredDefinition(seed);
            setScored(seedScored(choice));
            const typeInfo = catalogue.data?.types.find((candidate) => candidate.key === choice.type);
            setDefinitionVersion(typeInfo?.authoring.definition_version ?? null);
            const policyField = typeInfo?.policy_field ?? 'policy';
            const selectedPolicy = policyOf(seed, policyField) || choice.policy || '';
            const policyInfo = typeInfo?.authoring.scoring_policies.find(
                (candidate) => candidate.key === selectedPolicy,
            );
            setPolicy(selectedPolicy);
            setPolicyParams(
                Object.keys(policyParamsOf(seed, policyField)).length > 0
                    ? policyParamsOf(seed, policyField)
                    : (policyInfo?.default_params ?? {}),
            );
            form.reset({ ...blankQuestionValues(choice.type), points: '1' });
            setPreviewResponse(null);
            setPreviewState(null);
        },
        [catalogue.data, form, picker],
    );

    useEffect(() => {
        if (initialized.current || catalogue.data === undefined) {
            return;
        }
        if (editing) {
            const current = entry.data?.latest_version;
            if (entry.data === undefined || current === null || current === undefined) {
                return;
            }
            initialized.current = true;
            const definition = (current.definition ?? {}) as QuestionDefinition;
            const typeInfo = catalogue.data.types.find((candidate) => candidate.key === current.type);
            setPickerKey(current.type);
            setType(current.type);
            setStoredDefinition(definition);
            setDefinitionVersion(
                (current as typeof current & { definition_version?: number }).definition_version ??
                    typeInfo?.authoring.definition_version ??
                    null,
            );
            const policyField = typeInfo?.policy_field ?? 'policy';
            const selectedPolicy = policyOf(definition, policyField);
            const policyInfo = typeInfo?.authoring.scoring_policies.find(
                (candidate) => candidate.key === selectedPolicy,
            );
            setPolicy(selectedPolicy);
            setPolicyParams(
                Object.keys(policyParamsOf(definition, policyField)).length > 0
                    ? policyParamsOf(definition, policyField)
                    : (policyInfo?.default_params ?? {}),
            );
            setCategoryId(entry.data.category_id);
            setStatus(entry.data.status);
            setDifficulty(entry.data.difficulty_hint ?? '');
            setEstimatedTime(
                entry.data.estimated_time_seconds === null || entry.data.estimated_time_seconds === undefined
                    ? ''
                    : String(entry.data.estimated_time_seconds),
            );
            setTagText((entry.data.tags ?? []).join(', '));
            setObjectiveChoices(
                (entry.data.objectives ?? []).map((objective) => ({
                    id: objective.id,
                    label: objective.label,
                })),
            );
            form.reset({
                ...questionFormValues(current.type, definition),
                points: String(current.points ?? 1),
            });
            return;
        }
        const requested = findPickerEntry(picker, pickerKey);
        if (requested !== null) {
            initialized.current = true;
            choose(requested.key);
        }
    }, [catalogue.data, choose, editing, entry.data, form, picker, pickerKey]);

    const typeInfo = catalogue.data?.types.find((candidate) => candidate.key === type);
    const definition = useMemo(() => {
        if (type === '') {
            return storedDefinition;
        }
        try {
            const next = questionDefinition(type, watched, storedDefinition);
            if (typeInfo !== undefined) {
                if (policy !== '') {
                    next[typeInfo.policy_field] = {
                        key: policy,
                        params: policyParams,
                    };
                } else {
                    delete next[typeInfo.policy_field];
                }
            }
            return next;
        } catch {
            return storedDefinition;
        }
    }, [policy, policyParams, storedDefinition, type, typeInfo, watched]);

    const submit = form.handleSubmit(
        (values) => {
            if (type === '' || typeInfo === undefined || choosingFormat || pending !== null) {
                return;
            }
            const projected = questionDefinition(type, values, definition);
            /* Definition version normally travels beside the definition. A few
           installed providers deliberately declare it in their closed JSON
           Schema, so include it only when that schema owns the property. */
            const finalDefinition = definitionForWire(
                projected,
                typeInfo.authoring.definition_schema,
                definitionVersion ?? typeInfo.authoring.definition_version,
            );
            const points = Number(values.points);
            if (!Number.isFinite(points) || points <= 0) {
                setSection('grading');
                form.setError('points', { message: strings.editor.pointsInvalid });
                return;
            }
            const metadata = {
                category_id: categoryId,
                status,
                difficulty_hint: difficulty.trim() || null,
                estimated_time_seconds: estimatedTime === '' ? null : Number(estimatedTime),
            };
            void save.run(async (idempotencyKey) => {
                if (!editing) {
                    return create.mutateAsync({
                        bankId,
                        body: {
                            ...metadata,
                            status: status === 'archived' ? 'draft' : status,
                            type,
                            definition: finalDefinition,
                            points,
                            scored,
                            tags: commaList(tags),
                            objective_ids: objectives.map((objective) => objective.id),
                        },
                        idempotencyKey: `${idempotencyKey}-entry`,
                    });
                }
                await createVersion.mutateAsync({
                    bankId,
                    entryId: questionId,
                    /* The version's publication state is independent of the
                   mutable entry metadata. Omitting it makes the endpoint
                   default to ready, which could expose a draft edit. */
                    body: {
                        definition: finalDefinition,
                        points,
                        scored,
                        status: status === 'archived' ? 'draft' : status,
                    },
                    idempotencyKey: `${idempotencyKey}-version`,
                });
                await Promise.all([
                    updateEntry.mutateAsync({
                        bankId,
                        entryId: questionId,
                        body: metadata,
                        idempotencyKey: `${idempotencyKey}-entry`,
                    }),
                    setTags.mutateAsync({
                        bankId,
                        entryId: questionId,
                        tags: commaList(tags),
                        idempotencyKey: `${idempotencyKey}-tags`,
                    }),
                    setEntryObjectives.mutateAsync({
                        bankId,
                        entryId: questionId,
                        objectiveIds: objectives.map((objective) => objective.id),
                        idempotencyKey: `${idempotencyKey}-objectives`,
                    }),
                ]);
            });
        },
        (errors) => {
            setSection(errors.points ? 'grading' : 'content');
        },
    );

    /* A retry is performed inside the save tracker. Observe its successful
       transition here so initial saves and retries finish identically. */
    useEffect(() => {
        if (save.state !== 'saved' || save.savedAt === null || handledSave.current === save.savedAt) {
            return;
        }
        handledSave.current = save.savedAt;
        announce(editing ? 'New question version saved.' : 'Question created.');
        navigate(`/banks/${bankId}/questions`);
    }, [announce, bankId, editing, navigate, save.savedAt, save.state]);

    useEffect(() => {
        if (
            reactivateSave.state !== 'saved' ||
            reactivateSave.savedAt === null ||
            handledReactivate.current === reactivateSave.savedAt
        ) {
            return;
        }
        handledReactivate.current = reactivateSave.savedAt;
        announce('Question reactivated as a draft.');
        navigate(`/banks/${bankId}/questions`);
    }, [announce, bankId, navigate, reactivateSave.savedAt, reactivateSave.state]);

    if (bank.isError) {
        return <ScreenError error={bank.error} onRetry={() => void bank.refetch()} />;
    }
    if (catalogue.isError) {
        return <ScreenError error={catalogue.error} onRetry={() => void catalogue.refetch()} />;
    }
    if (editing && entry.isError) {
        return <ScreenError error={entry.error} onRetry={() => void entry.refetch()} />;
    }
    if (bank.isPending || catalogue.isPending || (editing && entry.isPending)) {
        return (
            <p role="status" className="p-4 text-sm text-fg-2">
                {uiText("vidyalms.ui.995c8aebfaef4122", "Loading question editor…")}</p>
        );
    }

    if (editing && entry.data?.status === 'archived') {
        return (
            <div className="grid w-full gap-5">
                <PageHeader
                    icon={FileQuestion}
                    eyebrow={bank.data?.name ?? strings.eyebrow}
                    title={uiText("vidyalms.ui.d9cb851cce7fe536", "Archived question")}
                    description={uiText("vidyalms.ui.07af3b60aa60a225", "Archived questions are read-only. Reactivate this question as a draft before changing its content or metadata.")}
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => navigate(`/banks/${bankId}/questions`)}
                        >
                            <Icon icon={ChevronLeft} size={15} />
                            {strings.editor.back}
                        </Button>
                    }
                />
                <section
                    aria-labelledby="vy-archived-question-title"
                    className="grid gap-3 rounded-lg border border-border bg-surface p-4"
                >
                    <div>
                        <h2
                            id="vy-archived-question-title"
                            className="font-display text-sm font-semibold text-fg"
                        >
                            {uiText("vidyalms.ui.9ef3b91e14359351", "Reactivate to edit")}</h2>
                        <p className="mt-1 text-sm text-fg-2">
                            {uiText("vidyalms.ui.06e5a98175e0a641", "This keeps the archived version unchanged and returns the question to the draft workflow.")}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            size="sm"
                            disabled={reactivateSave.state === 'saving'}
                            onClick={() =>
                                void reactivateSave.run((idempotencyKey) =>
                                    updateEntry.mutateAsync({
                                        bankId,
                                        entryId: questionId,
                                        body: { status: 'draft' },
                                        idempotencyKey,
                                    }),
                                )
                            }
                        >
                            {uiText("vidyalms.ui.f1075245deebc5d3", "Reactivate as draft")}</Button>
                        <SaveIndicator
                            state={reactivateSave.state}
                            savedAt={reactivateSave.savedAt}
                            onRetry={reactivateSave.retry}
                        />
                    </div>
                </section>
                <VersionUsagePanel versions={versions} usage={usage} />
            </div>
        );
    }

    return (
        <form noValidate onSubmit={submit} className="vy-bank-workspace vy-bank-question-studio">
            <PageHeader
                icon={FileQuestion}
                eyebrow={bank.data?.name ?? strings.eyebrow}
                title={editing ? strings.editor.editTitle : strings.editor.newTitle}
                description={editing ? strings.editor.editDescription : strings.editor.newDescription}
                actions={
                    <Button type="button" variant="outline" size="sm" onClick={back}>
                        <Icon icon={ChevronLeft} size={15} />
                        {strings.editor.back}
                    </Button>
                }
            />
            {type === '' ? (
                <QuestionTypeGallery catalogue={catalogue.data} onChoose={(choice) => choose(choice.key)} />
            ) : (
                <>
                    <div className="vy-bank-editor-context">
                        <span>
                            <Icon icon={FileQuestion} size={17} />
                            <strong>
                                {findPickerEntry(picker, pickerKey)?.label ?? typeInfo?.label ?? type}
                            </strong>
                            {!editing && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setChoosingFormat(true)}
                                >
                                    {strings.studio.changeType}
                                </Button>
                            )}
                        </span>
                        <span>
                            <Badge variant={status === 'ready' ? 'ok' : 'neutral'}>
                                {strings.questions[status]}
                            </Badge>
                            {editing && (
                                <small>
                                    {strings.questions.version(entry.data?.latest_version?.version ?? 1)}
                                </small>
                            )}
                        </span>
                    </div>
                    <Tabs
                        className="vy-bank-editor-tabs"
                        label={strings.studio.navigation}
                        current={`bank-editor-${section}`}
                        tabs={(
                            Object.entries(strings.studio.tabs) as [
                                keyof typeof strings.studio.tabs,
                                string,
                            ][]
                        )
                            .filter(([key]) => editing || key !== 'history')
                            .map(([key, label]) => ({ id: `bank-editor-${key}`, label }))}
                        onSelect={(id) => setSection(id.replace('bank-editor-', '') as typeof section)}
                    />
                    <div className="vy-bank-authoring-layout">
                        <div className="vy-bank-authoring-main" onChangeCapture={() => setDetailsDirty(true)}>
                            <div
                                role="tabpanel"
                                id="vy-tabpanel-bank-editor-content"
                                aria-labelledby="vy-tab-bank-editor-content"
                                hidden={section !== 'content'}
                                className="vy-bank-editor-panel"
                            >
                                <EditorSection title={strings.editor.content}>
                                    <BankField
                                        label={strings.editor.prompt}
                                        error={form.formState.errors.prompt?.message}
                                    >
                                        {({ id, describedBy, invalid }) => (
                                            <BankTextArea
                                                id={id}
                                                rows={4}
                                                invalid={invalid}
                                                aria-describedby={describedBy}
                                                {...form.register('prompt')}
                                            />
                                        )}
                                    </BankField>
                                </EditorSection>
                                <EditorSection title={strings.editor.interaction}>
                                    {type === '' ? (
                                        <p className="text-sm text-fg-2">{strings.editor.chooseType}</p>
                                    ) : (
                                        <QuestionAuthoringBlock
                                            type={type}
                                            form={form}
                                            definition={storedDefinition}
                                            definitionVersion={
                                                definitionVersion ?? typeInfo?.authoring.definition_version
                                            }
                                            locale={locale}
                                        />
                                    )}
                                </EditorSection>
                            </div>
                            <div
                                role="tabpanel"
                                id="vy-tabpanel-bank-editor-grading"
                                aria-labelledby="vy-tab-bank-editor-grading"
                                hidden={section !== 'grading'}
                                className="vy-bank-editor-panel"
                            >
                                <EditorSection title={strings.editor.scoring}>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <BankField
                                            label={strings.editor.points}
                                            error={form.formState.errors.points?.message}
                                        >
                                            {({ id, invalid }) => (
                                                <BankInput
                                                    id={id}
                                                    type="number"
                                                    min="0.01"
                                                    step="0.01"
                                                    invalid={invalid}
                                                    {...form.register('points')}
                                                />
                                            )}
                                        </BankField>
                                        {typeInfo !== undefined &&
                                            typeInfo.authoring.scoring_policies.length > 0 && (
                                                <BankField label={uiText("vidyalms.ui.c3a09177d6235535", "Scoring policy")}>
                                                    {({ id }) => (
                                                        <BankSelect
                                                            id={id}
                                                            value={policy}
                                                            onChange={(event) => {
                                                                const next = event.target.value;
                                                                setPolicy(next);
                                                                setPolicyParams(
                                                                    typeInfo.authoring.scoring_policies.find(
                                                                        (candidate) => candidate.key === next,
                                                                    )?.default_params ?? {},
                                                                );
                                                            }}
                                                        >
                                                            <option value="">{uiText("vidyalms.ui.352a25678fb9c8a3", "Provider default")}</option>
                                                            {typeInfo.authoring.scoring_policies.map(
                                                                (candidate) => (
                                                                    <option
                                                                        key={candidate.key}
                                                                        value={candidate.key}
                                                                    >
                                                                        {candidate.label}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </BankSelect>
                                                    )}
                                                </BankField>
                                            )}
                                    </div>
                                    {typeInfo !== undefined &&
                                        policy !== '' &&
                                        (() => {
                                            const selectedPolicy = typeInfo.authoring.scoring_policies.find(
                                                (candidate) => candidate.key === policy,
                                            );
                                            return selectedPolicy === undefined ? null : (
                                                <SchemaParams
                                                    schema={selectedPolicy.params_schema}
                                                    value={policyParams}
                                                    onChange={(params) => {
                                                        setPolicyParams(params);
                                                        setDetailsDirty(true);
                                                    }}
                                                />
                                            );
                                        })()}
                                    <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm text-fg">
                                        <input
                                            type="checkbox"
                                            checked={scored}
                                            onChange={(event) => setScored(event.target.checked)}
                                            className="h-4 w-4 accent-accent"
                                        />
                                        {strings.editor.scored}
                                    </label>
                                </EditorSection>
                                <EditorSection title={strings.editor.feedbackSection}>
                                    <BankField label={strings.editor.feedback}>
                                        {({ id }) => (
                                            <BankTextArea id={id} rows={3} {...form.register('feedback')} />
                                        )}
                                    </BankField>
                                </EditorSection>
                            </div>
                            <div
                                role="tabpanel"
                                id="vy-tabpanel-bank-editor-metadata"
                                aria-labelledby="vy-tab-bank-editor-metadata"
                                hidden={section !== 'metadata'}
                                className="vy-bank-editor-panel"
                            >
                                <EditorSection title={strings.editor.metadata}>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <BankField label={strings.editor.category}>
                                            {({ id }) => (
                                                <BankSelect
                                                    id={id}
                                                    value={categoryId ?? ''}
                                                    onChange={(event) =>
                                                        setCategoryId(
                                                            event.target.value === ''
                                                                ? null
                                                                : Number(event.target.value),
                                                        )
                                                    }
                                                >
                                                    <option value="">{strings.editor.noCategory}</option>
                                                    {flattenCategoryTree(categories.data ?? []).map(
                                                        ({ category, depth }) => (
                                                            <option
                                                                key={category.id}
                                                                value={category.id}
                                                            >{uiText("vidyalms.ui.380e3dbd6730acee", "{p0}{p1}", { p0: '· '.repeat(depth), p1: category.name })}</option>
                                                        ),
                                                    )}
                                                </BankSelect>
                                            )}
                                        </BankField>
                                        <BankField label={uiText("vidyalms.ui.920e413c7d411b61", "Status")}>
                                            {({ id }) => (
                                                <BankSelect
                                                    id={id}
                                                    value={status}
                                                    onChange={(event) =>
                                                        setStatus(event.target.value as typeof status)
                                                    }
                                                >
                                                    <option value="draft">{strings.questions.draft}</option>
                                                    <option value="ready">{strings.questions.ready}</option>
                                                    {editing && (
                                                        <option value="archived">
                                                            {strings.questions.archived}
                                                        </option>
                                                    )}
                                                </BankSelect>
                                            )}
                                        </BankField>
                                        <BankField label={strings.editor.difficulty}>
                                            {({ id }) => (
                                                <BankSelect
                                                    id={id}
                                                    value={difficulty}
                                                    onChange={(event) => setDifficulty(event.target.value)}
                                                >
                                                    <option value="">{uiText("vidyalms.ui.4895f73177ab5d67", "Not set")}</option>
                                                    <option value="easy">{uiText("vidyalms.ui.d6915875decb6e43", "Easy")}</option>
                                                    <option value="medium">{uiText("vidyalms.ui.8e588cd187741f1c", "Medium")}</option>
                                                    <option value="hard">{uiText("vidyalms.ui.9e73f8fa9cb76c44", "Hard")}</option>
                                                </BankSelect>
                                            )}
                                        </BankField>
                                        <BankField label={strings.editor.estimatedTime}>
                                            {({ id }) => (
                                                <BankInput
                                                    id={id}
                                                    type="number"
                                                    min={1}
                                                    value={estimatedTime}
                                                    onChange={(event) => setEstimatedTime(event.target.value)}
                                                />
                                            )}
                                        </BankField>
                                        <BankField label={strings.editor.tags} hint={strings.editor.tagsHint}>
                                            {({ id, describedBy }) => (
                                                <BankInput
                                                    id={id}
                                                    value={tags}
                                                    aria-describedby={describedBy}
                                                    onChange={(event) => setTagText(event.target.value)}
                                                />
                                            )}
                                        </BankField>
                                        <BankField
                                            label={strings.editor.objectives}
                                            hint={strings.editor.objectivesHint}
                                        >
                                            {() => (
                                                <ObjectivePicker
                                                    multiple
                                                    emptyLabel={uiText("vidyalms.ui.579356e001c678d3", "Choose objectives")}
                                                    value={objectives}
                                                    onChange={(choices) => {
                                                        setObjectiveChoices(choices);
                                                        setDetailsDirty(true);
                                                    }}
                                                />
                                            )}
                                        </BankField>
                                    </div>
                                </EditorSection>
                                <EditorSection title={strings.editor.accessibility}>
                                    <div className="flex items-start gap-3 text-sm text-fg-2">
                                        <Icon
                                            icon={Accessibility}
                                            size={19}
                                            className="mt-0.5 shrink-0 text-accent"
                                        />
                                        <div>
                                            <p>{strings.editor.accessibilityBody}</p>
                                            {typeInfo !== undefined && (
                                                <p className="mt-2 text-xs">
                                                    {uiText("vidyalms.ui.76d01bd48626da7c", "Modes:")}{' '}
                                                    {typeInfo.authoring.accessibility.interaction_modes.join(
                                                        ', ',
                                                    )}{' '}
                                                    ·{' '}
                                                    {typeInfo.authoring.accessibility.verified
                                                        ? uiText("vidyalms.ui.cb295c3f152a043a", "Conformance verified")
                                                        : uiText("vidyalms.ui.a856e05ee816b060", "Verification pending")}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </EditorSection>
                            </div>
                        </div>
                        <div
                            role="tabpanel"
                            id="vy-tabpanel-bank-editor-preview"
                            aria-labelledby="vy-tab-bank-editor-preview"
                            hidden={section !== 'preview'}
                            className="vy-bank-editor-panel"
                        >
                            <EditorSection title={strings.editor.preview}>
                                <p className="text-xs text-fg-2">{strings.editor.previewHint}</p>
                                {type === '' ? (
                                    <p className="rounded bg-bg p-4 text-sm text-fg-2">
                                        {strings.editor.chooseType}
                                    </p>
                                ) : (
                                    <div className="rounded-lg border border-border bg-bg p-4">
                                        {section === 'preview' && (
                                            <QuestionHost
                                                typeId={type}
                                                questionId={questionId ?? 'new'}
                                                definition={definition}
                                                definitionVersion={
                                                    definitionVersion ??
                                                    typeInfo?.authoring.definition_version
                                                }
                                                response={previewResponse}
                                                onResponseChange={setPreviewResponse}
                                                state={previewState}
                                                onStateChange={setPreviewState}
                                                locale={locale}
                                                responseShape={readResponseShape(typeInfo?.response_shape)}
                                            />
                                        )}
                                    </div>
                                )}
                            </EditorSection>
                        </div>
                        {editing && (
                            <div
                                role="tabpanel"
                                id="vy-tabpanel-bank-editor-history"
                                aria-labelledby="vy-tab-bank-editor-history"
                                hidden={section !== 'history'}
                                className="vy-bank-editor-panel"
                            >
                                <VersionUsagePanel versions={versions} usage={usage} />
                            </div>
                        )}
                    </div>
                    {save.state === 'failed' && (
                        <p
                            role="alert"
                            className="rounded border border-danger bg-danger-soft p-3 text-sm text-danger"
                        >
                            {strings.editor.failed}
                        </p>
                    )}
                    <div className="vy-bank-editor-savebar">
                        <div className="vy-bank-editor-save-note">
                            <strong>{dirty ? strings.studio.unsaved : bank.data?.name}</strong>
                            <span>{editing ? strings.studio.versionHint : strings.studio.newHint}</span>
                        </div>
                        <SaveIndicator
                            className="mr-auto"
                            state={save.state}
                            savedAt={save.savedAt}
                            onRetry={save.retry}
                        />
                        <Button type="button" variant="outline" size="sm" onClick={back}>
                            {strings.editor.cancel}
                        </Button>
                        <Button type="submit" size="sm" disabled={save.state === 'saving' || type === ''}>
                            <Icon icon={editing ? Save : Eye} size={15} />
                            {editing ? strings.editor.saveVersion : strings.editor.create}
                        </Button>
                    </div>
                </>
            )}
            <Dialog open={choosingFormat} onOpenChange={setChoosingFormat}>
                <DialogContent className="vy-bank-format-dialog max-w-[960px]">
                    <DialogTitle>{strings.studio.changeType}</DialogTitle>
                    <DialogDescription>{strings.studio.changeHint}</DialogDescription>
                    <QuestionTypeGallery
                        catalogue={catalogue.data}
                        onChoose={(choice) => request(() => choose(choice.key), true)}
                    />
                </DialogContent>
            </Dialog>
            <Dialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) setPending(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>
                        {pending?.format ? strings.studio.changeTitle : strings.studio.leaveTitle}
                    </DialogTitle>
                    <DialogDescription>
                        {pending?.format ? strings.studio.changeHint : strings.studio.leaveHint}
                    </DialogDescription>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setPending(null)}>
                            {strings.studio.keepEditing}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                pending?.action();
                                setPending(null);
                            }}
                        >
                            {strings.studio.discard}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </form>
    );
}
