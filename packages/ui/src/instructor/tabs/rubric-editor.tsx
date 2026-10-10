import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Plus } from 'lucide-react';
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
import { useCreateRubric, useCreateRubricVersion, useRubricVersions, useRubrics } from '../assessment-api';
import { assessmentStrings as strings } from '../assessment-strings';
import { instructorStrings as workbenchStrings } from '../strings';
import { deniedFor } from '../lib/problems';
import { useSaveTracker } from '../lib/save-state';
import { dateTimeText, pointsText } from '../lib/format';
import { ListSkeleton, PermissionDenied, SaveError } from '../components/states';
import { CheckboxField, Field, Select, TextInput } from '../components/field';
import type { Rubric, RubricCriterionInput, RubricVersion } from '../lib/assessment-types';

/*
 * Rubrics, and the one rule that shapes the whole screen: a rubric is
 * versioned, and editing criteria or levels writes a NEW version rather than
 * changing the one grades were given under.
 *
 * A human grade stores the selected level id per criterion, not only the
 * points, because the level is what makes analytic feedback, rater agreement
 * analysis and a safe revision possible at all. Mutating a level's points in
 * place would silently rewrite what every past grade meant, so the editor
 * does not offer it: the criteria form always saves as a version.
 */

interface DraftLevel {
    points: string;
    descriptor: string;
}

interface DraftCriterion {
    description: string;
    weight: string;
    levels: DraftLevel[];
}

function blankLevel(): DraftLevel {
    return { points: '0', descriptor: '' };
}

function blankCriterion(): DraftCriterion {
    return { description: '', weight: '1', levels: [blankLevel(), blankLevel()] };
}

function draftFrom(version: RubricVersion | null): DraftCriterion[] {
    if (version === null) {
        return [blankCriterion()];
    }
    return version.criteria.map((criterion) => ({
        description: criterion.description,
        weight: String(criterion.weight ?? 1),
        levels: criterion.levels.map((level) => ({
            points: String(level.points),
            descriptor: level.descriptor,
        })),
    }));
}

function toRequest(draft: readonly DraftCriterion[]): RubricCriterionInput[] {
    return draft.map((criterion) => ({
        description: criterion.description.trim(),
        weight: Number(criterion.weight) || 1,
        levels: criterion.levels.map((level) => ({
            points: Number(level.points) || 0,
            descriptor: level.descriptor.trim(),
        })),
    }));
}

/** The sum of the highest level of every criterion, weighted, which is the rubric's maximum. */
export function draftMaxPoints(draft: readonly DraftCriterion[]): number {
    const total = draft.reduce((sum, criterion) => {
        const best = criterion.levels.reduce(
            (highest, level) => Math.max(highest, Number(level.points) || 0),
            0,
        );
        return sum + best * (Number(criterion.weight) || 1);
    }, 0);
    return Math.round(total * 100) / 100;
}

function CriteriaForm({
    draft,
    onChange,
    disabled,
}: {
    draft: DraftCriterion[];
    onChange: (draft: DraftCriterion[]) => void;
    disabled: boolean;
}) {
    const setCriterion = (index: number, next: DraftCriterion | null) => {
        const copy = [...draft];
        if (next === null) {
            copy.splice(index, 1);
        } else {
            copy[index] = next;
        }
        onChange(copy);
    };

    return (
        <div className="grid gap-3">
            {draft.map((criterion, index) => (
                <fieldset key={index} className="grid gap-2.5 rounded border border-border p-3">
                    <legend className="px-1 font-display text-[11.5px] font-semibold text-fg">
                        {uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: strings.rubrics.criterion, p1: index + 1 })}
                    </legend>
                    <div className="grid gap-2.5 sm:grid-cols-[1fr_100px]">
                        <Field label={strings.rubrics.criterionDescription}>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    disabled={disabled}
                                    value={criterion.description}
                                    onChange={(event) =>
                                        setCriterion(index, { ...criterion, description: event.target.value })
                                    }
                                />
                            )}
                        </Field>
                        <Field label={strings.rubrics.criterionWeight}>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    inputMode="decimal"
                                    disabled={disabled}
                                    value={criterion.weight}
                                    onChange={(event) =>
                                        setCriterion(index, { ...criterion, weight: event.target.value })
                                    }
                                />
                            )}
                        </Field>
                    </div>
                    <p className="font-display text-[11px] font-semibold text-fg-2">{strings.rubrics.levels}</p>
                    {criterion.levels.map((level, levelIndex) => (
                        <div key={levelIndex} className="flex flex-wrap items-end gap-2">
                            <Field label={strings.rubrics.levelPoints} className="w-[90px]">
                                {({ id }) => (
                                    <TextInput
                                        id={id}
                                        inputMode="decimal"
                                        disabled={disabled}
                                        value={level.points}
                                        onChange={(event) => {
                                            const levels = [...criterion.levels];
                                            levels[levelIndex] = { ...level, points: event.target.value };
                                            setCriterion(index, { ...criterion, levels });
                                        }}
                                    />
                                )}
                            </Field>
                            <Field label={strings.rubrics.levelDescriptor} className="min-w-[160px] flex-1">
                                {({ id }) => (
                                    <TextInput
                                        id={id}
                                        disabled={disabled}
                                        value={level.descriptor}
                                        onChange={(event) => {
                                            const levels = [...criterion.levels];
                                            levels[levelIndex] = { ...level, descriptor: event.target.value };
                                            setCriterion(index, { ...criterion, levels });
                                        }}
                                    />
                                )}
                            </Field>
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={disabled || criterion.levels.length <= 1}
                                onClick={() => {
                                    const levels = criterion.levels.filter((_, position) => position !== levelIndex);
                                    setCriterion(index, { ...criterion, levels });
                                }}
                            >
                                <span className="sr-only">{strings.rubrics.removeLevel(levelIndex + 1)}</span>
                                <span aria-hidden="true">{strings.common.remove}</span>
                            </Button>
                        </div>
                    ))}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={disabled}
                            onClick={() =>
                                setCriterion(index, { ...criterion, levels: [...criterion.levels, blankLevel()] })
                            }
                        >
                            <Icon icon={Plus} size={13} />
                            {strings.rubrics.addLevel}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={disabled || draft.length <= 1}
                            onClick={() => setCriterion(index, null)}
                        >
                            <span className="sr-only">{strings.rubrics.removeCriterion(index + 1)}</span>
                            <span aria-hidden="true">{strings.common.remove}</span>
                        </Button>
                    </div>
                </fieldset>
            ))}
            <div>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    onClick={() => onChange([...draft, blankCriterion()])}
                >
                    <Icon icon={Plus} size={13} />
                    {strings.rubrics.addCriterion}
                </Button>
            </div>
        </div>
    );
}

export function RubricDialog({
    courseId,
    rubric,
    onClose,
}: {
    courseId: number;
    /** Null creates one in the course scope; an existing rubric saves a new version. */
    rubric: Rubric | null;
    onClose: () => void;
}) {
    const createRubric = useCreateRubric();
    const createVersion = useCreateRubricVersion();
    const versionsQuery = useRubricVersions(rubric?.id ?? null);
    const save = useSaveTracker();
    const [name, setName] = useState(rubric?.name ?? '');
    const [kind, setKind] = useState<Rubric['kind']>(rubric?.kind ?? 'analytic');
    const [minScoreIsZero, setMinScoreIsZero] = useState(rubric?.min_score_is_zero !== false);
    const [draft, setDraft] = useState<DraftCriterion[]>(() => draftFrom(rubric?.latest_version ?? null));
    const [error, setError] = useState<string | null>(null);

    const busy = save.state === 'saving';

    const onSubmit = () => {
        if (draft.length === 0) {
            setError(strings.rubrics.needsCriterion);
            return;
        }
        if (draft.some((criterion) => criterion.levels.length === 0)) {
            setError(strings.rubrics.needsLevel);
            return;
        }
        setError(null);
        void save
            .run(async (idempotencyKey) => {
                if (rubric === null) {
                    return createRubric.mutateAsync({
                        body: {
                            owner_scope: 'course',
                            owner_id: courseId,
                            name: name.trim(),
                            kind,
                            min_score_is_zero: minScoreIsZero,
                            criteria: toRequest(draft),
                        },
                        idempotencyKey,
                    });
                }
                return createVersion.mutateAsync({
                    rubricId: rubric.id,
                    body: { criteria: toRequest(draft) },
                    idempotencyKey,
                });
            })
            .then((ok) => {
                if (ok) {
                    onClose();
                }
            });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogTitle>{rubric === null ? strings.rubrics.createTitle : strings.rubrics.editTitle}</DialogTitle>
                <DialogDescription>{strings.rubrics.description}</DialogDescription>
                <div className="mt-3.5 grid max-h-[520px] gap-3.5 overflow-y-auto pr-1">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label={strings.rubrics.name}>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    autoFocus
                                    disabled={busy || rubric !== null}
                                    value={name}
                                    onChange={(event) => setName(event.target.value)}
                                />
                            )}
                        </Field>
                        <Field label={strings.rubrics.kind} hint={strings.rubrics.kindHint}>
                            {({ id, describedBy }) => (
                                <Select
                                    id={id}
                                    aria-describedby={describedBy}
                                    disabled={busy || rubric !== null}
                                    value={kind}
                                    onChange={(event) => setKind(event.target.value as Rubric['kind'])}
                                >
                                    <option value="analytic">{strings.rubrics.kindAnalytic}</option>
                                    <option value="holistic">{strings.rubrics.kindHolistic}</option>
                                </Select>
                            )}
                        </Field>
                    </div>
                    <CheckboxField
                        label={strings.rubrics.minScoreIsZero}
                        checked={minScoreIsZero}
                        disabled={busy || rubric !== null}
                        onChange={(event) => setMinScoreIsZero(event.target.checked)}
                    />

                    <div>
                        <p className="mb-1 font-display text-xs font-semibold text-fg">{strings.rubrics.criteria}</p>
                        <p className="mb-2 text-[11.5px] text-fg-2">{strings.rubrics.versionNote}</p>
                        <CriteriaForm draft={draft} onChange={setDraft} disabled={busy} />
                        <p className="mt-2 text-[11.5px] text-fg-2">
                            {strings.rubrics.maxPoints(draftMaxPoints(draft))}
                        </p>
                    </div>

                    {rubric !== null && (
                        <div>
                            <p className="mb-1 font-display text-xs font-semibold text-fg">
                                {strings.rubrics.versionsTitle}
                            </p>
                            {versionsQuery.isPending ? (
                                <ListSkeleton label={strings.common.loading} rows={2} />
                            ) : (
                                <ul className="grid gap-1 text-[11.5px] text-fg-2">
                                    {(versionsQuery.data ?? []).map((version) => (
                                        <li key={version.id}>
                                            {strings.rubrics.versionRow(
                                                version.version,
                                                dateTimeText(version.created_at ?? ''),
                                            )}
                                            {version.max_points !== undefined && (
                                                <span> · {strings.rubrics.maxPoints(version.max_points)}</span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}

                    {error !== null && (
                        <p role="alert" className="text-[11.5px] font-medium text-danger">
                            {error}
                        </p>
                    )}
                    {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
                </div>
                <DialogFooter className="items-center">
                    <SaveIndicator className="mr-auto" state={save.state} savedAt={save.savedAt} />
                    <Button type="button" variant="outline" size="sm" onClick={onClose}>
                        {workbenchStrings.form.cancel}
                    </Button>
                    <Button type="button" size="sm" disabled={busy || name.trim() === ''} onClick={onSubmit}>
                        {strings.common.save}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function RubricsPanel({ courseId }: { courseId: number }) {
    const rubricsQuery = useRubrics({ course_id: courseId, per_page: 50 });
    const [editing, setEditing] = useState<{ rubric: Rubric | null } | null>(null);

    const rubrics = rubricsQuery.data?.items ?? [];

    return (
        <section aria-label={strings.rubrics.title} className="rounded-lg border border-border bg-surface">
            <div className="flex flex-wrap items-center gap-2.5 border-b border-border px-4 py-3">
                <div className="min-w-0 flex-1">
                    <h4 className="font-display text-[13px] font-bold text-fg">{strings.rubrics.title}</h4>
                    <p className="text-[11.5px] text-fg-2">{strings.rubrics.description}</p>
                </div>
                <Button variant="outline" size="sm" onClick={() => setEditing({ rubric: null })}>
                    <Icon icon={Plus} size={13} />
                    {strings.rubrics.create}
                </Button>
            </div>

            {rubricsQuery.isPending ? (
                <ListSkeleton label={strings.common.loading} rows={3} />
            ) : rubricsQuery.isError ? (
                <div className="p-4">
                    {deniedFor(rubricsQuery.error) ? (
                        <PermissionDenied />
                    ) : (
                        <ErrorState
                            error={rubricsQuery.error}
                            title={strings.rubrics.errorTitle}
                            onRetry={() => void rubricsQuery.refetch()}
                        />
                    )}
                </div>
            ) : rubrics.length === 0 ? (
                <EmptyState
                    title={strings.rubrics.emptyTitle}
                    description={strings.rubrics.emptyBody}
                    action={
                        <Button size="sm" onClick={() => setEditing({ rubric: null })}>
                            {strings.rubrics.create}
                        </Button>
                    }
                />
            ) : (
                <ul>
                    {rubrics.map((rubric) => (
                        <li
                            key={rubric.id}
                            className="flex flex-wrap items-center gap-2.5 border-b border-border/60 px-4 py-2.5 text-[12.5px] last:border-b-0"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="line-clamp-1">{rubric.name}</span>
                                <span className="block text-[11px] text-fg-2">
                                    {[
                                        rubric.kind === 'analytic'
                                            ? strings.rubrics.kindAnalytic
                                            : strings.rubrics.kindHolistic,
                                        strings.bank.versionsOf(rubric.versions_count ?? 0),
                                        rubric.latest_version?.max_points === undefined
                                            ? ''
                                            : strings.rubrics.maxPoints(
                                                  Number(pointsText(rubric.latest_version.max_points)),
                                              ),
                                    ]
                                        .filter((part) => part !== '')
                                        .join(' · ')}
                                </span>
                            </span>
                            <Button variant="outline" size="sm" onClick={() => setEditing({ rubric })}>
                                <span className="sr-only">{uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: strings.common.edit, p1: rubric.name })}</span>
                                <span aria-hidden="true">{strings.common.edit}</span>
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            {editing !== null && (
                <RubricDialog courseId={courseId} rubric={editing.rubric} onClose={() => setEditing(null)} />
            )}
        </section>
    );
}
