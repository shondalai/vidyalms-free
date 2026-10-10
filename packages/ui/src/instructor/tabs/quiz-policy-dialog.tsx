import { useNavigationGuard } from '@/shared/navigation-guard';
import { studioStrings as studio } from '../studio-strings';
import { TextInput } from '../components/field';
import { useState } from 'react';
import type { QuizSummary } from '@vidyalms/api-sdk';
import { Button, DialogFooter, SaveIndicator } from '@/shared/components';
import { useSaveQuizPolicy, useQuizSettingsSchema } from '../assessment-api';
import { assessmentStrings as strings } from '../assessment-strings';
import { instructorStrings as workbenchStrings } from '../strings';
import { useSaveTracker } from '../lib/save-state';
import { deniedFor, validationErrors } from '../lib/problems';
import { ListSkeleton, PermissionDenied, SaveError } from '../components/states';
import { ErrorState, EmptyState } from '@/shared/components';
import {
    PolicyGroup,
    seedPolicyValues,
    validatePolicyValues,
    withValueAt,
    type PolicyValues,
} from '../components/settings-form';
import type { QuizPolicy } from '../lib/assessment-types';
import { AuthoringSurface } from '../components/authoring-surface';
import { quizStudioStrings as quizStudio } from '../quiz-studio-strings';

/*
 * Marking and timing for one quiz, drawn entirely from the settings schema.
 *
 * It is a second dialog beside the quiz settings form rather than a section
 * inside it, because the two answer different questions and come from
 * different places: the settings form is the quiz's own fields, which are
 * the same everywhere, and this is the policy set, which is exactly what
 * this site's registries can offer and therefore differs between two
 * installs of the same product.
 *
 * PUT /manage/quizzes/{id} replaces the whole quiz, so the write carries the
 * quiz's stored fields back unchanged beside the policy. Sending the policy
 * alone would reset the title.
 */

export function QuizPolicyDialog({
    courseId,
    quiz,
    onClose,
    inline = false,
}: {
    courseId: number;
    quiz: QuizSummary;
    onClose: () => void;
    inline?: boolean;
}) {
    const schemaQuery = useQuizSettingsSchema(quiz.id);
    const savePolicy = useSaveQuizPolicy();
    const save = useSaveTracker();
    const [values, setValues] = useState<PolicyValues | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const groups = schemaQuery.data?.groups ?? [];
    const [search, setSearch] = useState('');
    const [groupKey, setGroupKey] = useState(inline ? '' : 'all');
    const selectedGroup = groupKey === '' ? (groups[0]?.key ?? 'all') : groupKey;
    const [dirty, setDirty] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    useNavigationGuard(dirty);
    const requestClose = () => {
        if (dirty) setConfirmClose(true);
        else onClose();
    };
    const visibleGroups = groups.filter(
        (group) =>
            (selectedGroup === 'all' || group.key === selectedGroup) &&
            JSON.stringify(group).toLowerCase().includes(search.toLowerCase()),
    );
    if (schemaQuery.data !== undefined && values === null) {
        setValues(seedPolicyValues(groups));
    }

    const onChange = (path: string[], value: unknown) => {
        setDirty(true);
        setValues((current) => (current === null ? current : withValueAt(current, path, value)));
        setErrors((current) => {
            const key = path.join('.');
            if (current[key] === undefined) {
                return current;
            }
            const next = { ...current };
            delete next[key];
            return next;
        });
    };

    const onSubmit = () => {
        if (values === null) {
            return;
        }
        const found = validatePolicyValues(groups, values);
        setErrors(found);
        if (Object.keys(found).length > 0) {
            setSearch('');
            setGroupKey('all');
            return;
        }
        void save
            .run(async (idempotencyKey) => {
                try {
                    const updated = await savePolicy.mutateAsync({
                        courseId,
                        quizId: quiz.id,
                        settings: {
                            title: quiz.title,
                            lesson_id: quiz.lesson_id,
                            description: quiz.description,
                            pass_mark: quiz.pass_mark,
                            max_attempts: quiz.max_attempts,
                            time_limit_seconds: quiz.time_limit_seconds,
                            is_final: quiz.is_final,
                            show_results: quiz.show_results,
                        },
                        policy: values as QuizPolicy,
                        idempotencyKey,
                    });
                    setDirty(false);
                    return updated;
                } catch (cause) {
                    /* The server is the authority on validation and names
                       the field, so its message is read here rather than
                       from the tracker's state, which this closure captured
                       before the write ran. A field naming a provider the
                       site does not have is the one place on this surface
                       where a missing package is an error. */
                    setErrors((current) => ({ ...current, ...validationErrors(cause) }));
                    throw cause;
                }
            })
            .then((ok) => {
                if (ok && !inline) {
                    onClose();
                }
            });
    };

    return (
        <AuthoringSurface
            inline={inline}
            onClose={requestClose}
            className="vy-quiz-policy max-w-[960px]"
            title={strings.policy.title}
            description={inline ? quizStudio.inlinePolicy : strings.policy.description}
        >
            <div className="mt-4">
                <TextInput
                    aria-label={studio.policySearch}
                    placeholder={studio.policySearch}
                    value={search}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        if (inline) setGroupKey('all');
                    }}
                />
            </div>
            <nav aria-label={studio.policyGroups} className="vy-quiz-policy-groups mt-3 flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    aria-pressed={selectedGroup === 'all'}
                    onClick={() => setGroupKey('all')}
                >
                    {studio.allSettings}
                </Button>
                {groups.map((group) => (
                    <Button
                        key={group.key}
                        variant="outline"
                        size="sm"
                        aria-pressed={selectedGroup === group.key}
                        onClick={() => setGroupKey(group.key)}
                    >
                        {group.label}
                    </Button>
                ))}
            </nav>
            <div
                className={
                    inline
                        ? 'vy-quiz-policy-fields mt-4 grid gap-5'
                        : 'mt-3.5 grid max-h-[540px] gap-4 overflow-y-auto pr-1'
                }
            >
                {schemaQuery.isPending ? (
                    <ListSkeleton label={strings.common.loading} rows={4} />
                ) : schemaQuery.isError ? (
                    deniedFor(schemaQuery.error) ? (
                        <PermissionDenied />
                    ) : (
                        <ErrorState
                            error={schemaQuery.error}
                            title={strings.policy.errorTitle}
                            onRetry={() => void schemaQuery.refetch()}
                        />
                    )
                ) : groups.length === 0 ? (
                    <EmptyState title={strings.policy.emptyTitle} description={strings.policy.emptyBody} />
                ) : values === null ? (
                    <ListSkeleton label={strings.common.loading} rows={4} />
                ) : (
                    visibleGroups.map((group) => (
                        <PolicyGroup
                            key={group.key}
                            group={group}
                            values={values}
                            errors={errors}
                            onChange={onChange}
                        />
                    ))
                )}
                {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
            </div>
            <DialogFooter className="items-center">
                <SaveIndicator className="mr-auto" state={save.state} savedAt={save.savedAt} />
                {!inline && (
                    <Button type="button" variant="outline" size="sm" onClick={requestClose}>
                        {workbenchStrings.form.cancel}
                    </Button>
                )}
                <Button
                    type="button"
                    size="sm"
                    onClick={onSubmit}
                    disabled={values === null || save.state === 'saving'}
                >
                    {strings.common.save}
                </Button>
            </DialogFooter>
            {confirmClose && (
                <div role="alert" className="mt-4 rounded-lg border border-border p-4">
                    <p className="mb-3 text-sm">{workbenchStrings.lesson.unsaved}</p>
                    <Button variant="outline" onClick={() => setConfirmClose(false)}>
                        {workbenchStrings.lesson.keepEditing}
                    </Button>
                    <Button variant="destructive" onClick={onClose}>
                        {workbenchStrings.lesson.discard}
                    </Button>
                </div>
            )}
        </AuthoringSurface>
    );
}
