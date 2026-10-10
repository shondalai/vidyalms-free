import { useMemo, useState } from 'react';
import { cn } from '@/shared/cn';
import { assessmentStrings as strings } from '../assessment-strings';
import { pointsText } from '../lib/format';
import { Field, TextArea, TextInput } from './field';
import type { GradeCriterionInput, RubricVersion } from '../lib/assessment-types';

/*
 * Grading one answer against a rubric version.
 *
 * The grid stores the chosen LEVEL id per criterion beside the points, never
 * only the points. The level is what makes analytic feedback, rater
 * agreement analysis and a safe rubric revision possible later, and it
 * cannot be reconstructed from a number once two levels of one criterion
 * happen to be worth the same.
 *
 * The score is the sum of the chosen levels, so nothing here asks the rater
 * for a total: a points box beside a rubric invites a number that disagrees
 * with the grid, and the server answers 422 for exactly that. The per level
 * override exists for a rater who wants half of a level and is applied
 * inside the level's own band, which is still the level they chose.
 */

export interface RubricGridState {
    /** One entry per criterion of the version, in the order the version declares them. */
    criteria: GradeCriterionInput[];
    /** The sum the chosen levels declare, weighted, which is what the server will store. */
    awarded: number;
    max: number;
    /** False until every criterion has a level, which is when the grade may be given. */
    complete: boolean;
}

function levelPoints(version: RubricVersion, criterionId: number, levelId: number | null): number | null {
    const criterion = version.criteria.find((entry) => entry.id === criterionId);
    if (criterion === undefined || levelId === null) {
        return null;
    }
    return criterion.levels.find((level) => level.id === levelId)?.points ?? null;
}

function weightOf(version: RubricVersion, criterionId: number): number {
    return version.criteria.find((entry) => entry.id === criterionId)?.weight ?? 1;
}

/** The running total and whether the grid may be submitted, derived from the choices alone. */
export function readRubricGrid(
    version: RubricVersion,
    chosen: readonly GradeCriterionInput[],
): RubricGridState {
    const criteria = version.criteria.map((criterion) => {
        const entry = chosen.find((candidate) => candidate.criterion_id === criterion.id);
        return {
            criterion_id: criterion.id,
            level_id: entry?.level_id ?? null,
            points: entry?.points ?? null,
            comment: entry?.comment ?? null,
        } satisfies GradeCriterionInput;
    });
    const awarded = criteria.reduce((sum, entry) => {
        const override = entry.points;
        const declared = levelPoints(version, entry.criterion_id, entry.level_id);
        const points = override ?? declared ?? 0;
        return sum + points * weightOf(version, entry.criterion_id);
    }, 0);
    const max = version.criteria.reduce((sum, criterion) => {
        const best = criterion.levels.reduce((highest, level) => Math.max(highest, level.points), 0);
        return sum + best * (criterion.weight ?? 1);
    }, 0);
    return {
        criteria,
        awarded: Math.round(awarded * 100) / 100,
        max: Math.round(max * 100) / 100,
        complete: criteria.every((entry) => entry.level_id !== null),
    };
}

export function RubricGrid({
    version,
    value,
    onChange,
    disabled = false,
}: {
    version: RubricVersion;
    value: readonly GradeCriterionInput[];
    onChange: (criteria: GradeCriterionInput[]) => void;
    disabled?: boolean;
}) {
    const state = useMemo(() => readRubricGrid(version, value), [version, value]);
    const [openComment, setOpenComment] = useState<number | null>(null);
    /*
     * The override box keeps the text the rater is typing, not the number it
     * has parsed to so far. Reflecting the parsed number back makes a
     * decimal impossible to enter: "2." parses to 2, the box redraws as "2",
     * and the next keystroke gives 25 rather than 2.5.
     */
    const [overrideText, setOverrideText] = useState<Record<number, string>>({});

    const setEntry = (criterionId: number, patch: Partial<GradeCriterionInput>) => {
        onChange(
            state.criteria.map((entry) =>
                entry.criterion_id === criterionId ? { ...entry, ...patch } : entry,
            ),
        );
    };

    return (
        <div className="grid gap-3 rounded-lg border border-border bg-surface p-3.5">
            <div className="flex flex-wrap items-baseline gap-2">
                <h4 className="font-display text-xs font-semibold text-fg">{strings.grading.rubricHeading}</h4>
                <span className="text-[11px] text-fg-2">{strings.grading.rubricVersion(version.version)}</span>
                <span
                    role="status"
                    aria-label={strings.grading.runningTotalLabel}
                    className="ml-auto rounded-full border border-border px-2.5 py-1 font-display text-[12px] font-semibold tabular-nums text-fg"
                >
                    {strings.grading.runningTotal(
                        Number(pointsText(state.awarded)),
                        Number(pointsText(state.max)),
                    )}
                </span>
            </div>
            <p className="text-[11.5px] text-fg-2">{strings.grading.rubricSum}</p>

            {version.criteria.map((criterion) => {
                const entry = state.criteria.find((candidate) => candidate.criterion_id === criterion.id);
                const chosenLevel = entry?.level_id ?? null;
                return (
                    <fieldset key={criterion.id} className="grid gap-2 rounded border border-border p-2.5">
                        <legend className="px-1 font-display text-[11.5px] font-semibold text-fg">
                            {criterion.description}
                        </legend>
                        <div
                            role="radiogroup"
                            aria-label={strings.grading.criterionLevel(criterion.description)}
                            className="flex flex-wrap gap-2"
                        >
                            {criterion.levels.map((level) => {
                                const selected = chosenLevel === level.id;
                                return (
                                    <button
                                        key={level.id}
                                        type="button"
                                        role="radio"
                                        aria-checked={selected}
                                        disabled={disabled}
                                        onClick={() => {
                                            /* A new level is a new band, so an
                                               override inside the old one no
                                               longer means anything. */
                                            setOverrideText((current) => {
                                                const next = { ...current };
                                                delete next[criterion.id];
                                                return next;
                                            });
                                            setEntry(criterion.id, { level_id: level.id, points: null });
                                        }}
                                        className={cn(
                                            'min-h-11 min-w-[110px] flex-1 cursor-pointer rounded border px-2.5 py-1.5 text-left text-[12px]',
                                            selected
                                                ? 'border-2 border-accent bg-accent-soft font-semibold text-fg'
                                                : 'border-border bg-surface text-fg-2',
                                        )}
                                    >
                                        <span className="block font-display text-[11px] font-bold">
                                            {strings.grading.levelPoints(Number(pointsText(level.points)))}
                                        </span>
                                        <span className="block">{level.descriptor}</span>
                                        {/* Never colour alone: the chosen level says so in words too. */}
                                        {selected && <span className="sr-only">{strings.grading.pickLevel}</span>}
                                    </button>
                                );
                            })}
                        </div>
                        <div className="flex flex-wrap items-end gap-2">
                            <Field
                                label={strings.grading.overridePoints}
                                hint={strings.grading.overrideHint}
                                className="w-[110px]"
                            >
                                {({ id, describedBy }) => (
                                    <TextInput
                                        id={id}
                                        inputMode="decimal"
                                        aria-describedby={describedBy}
                                        disabled={disabled || chosenLevel === null}
                                        value={
                                            overrideText[criterion.id] ??
                                            (entry?.points === null || entry?.points === undefined
                                                ? ''
                                                : String(entry.points))
                                        }
                                        onChange={(event) => {
                                            const raw = event.target.value;
                                            setOverrideText((current) => ({ ...current, [criterion.id]: raw }));
                                            const parsed = Number(raw);
                                            setEntry(criterion.id, {
                                                points:
                                                    raw.trim() === '' || !Number.isFinite(parsed) ? null : parsed,
                                            });
                                        }}
                                    />
                                )}
                            </Field>
                            <button
                                type="button"
                                disabled={disabled}
                                onClick={() =>
                                    setOpenComment((current) => (current === criterion.id ? null : criterion.id))
                                }
                                className="cursor-pointer py-2 text-[11.5px] font-medium text-accent"
                            >
                                {strings.grading.criterionComment(criterion.description)}
                            </button>
                        </div>
                        {openComment === criterion.id && (
                            <Field label={strings.grading.criterionComment(criterion.description)} hideLabel>
                                {({ id }) => (
                                    <TextArea
                                        id={id}
                                        rows={2}
                                        disabled={disabled}
                                        value={entry?.comment ?? ''}
                                        onChange={(event) =>
                                            setEntry(criterion.id, {
                                                comment: event.target.value === '' ? null : event.target.value,
                                            })
                                        }
                                    />
                                )}
                            </Field>
                        )}
                    </fieldset>
                );
            })}

            {!state.complete && (
                <p className="text-[11.5px] font-medium text-warn-strong">{strings.grading.everyCriterion}</p>
            )}
        </div>
    );
}
