import { translate as uiText } from '@/shared/i18n';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import type { AuthoringCurriculum, QuizSummary } from '@vidyalms/api-sdk';
import { Button, DialogFooter, SaveIndicator } from '@/shared/components';
import { SaveError } from '../components/states';
import { useAuthoringCurriculum, useCourseQuizzes, useCreateQuiz, useUpdateQuiz } from '../api';
import { instructorStrings as strings } from '../strings';
import { useSaveTracker } from '../lib/save-state';
import { CheckboxField, Field, Select, TextArea, TextInput } from '../components/field';
import { AuthoringSurface } from '../components/authoring-surface';
import { quizStudioStrings as studio } from '../quiz-studio-strings';
import { useNavigationGuard } from '@/shared/navigation-guard';

/*
 * The quiz settings form (the chips of artboard 2a, editable): pass mark,
 * time limit, attempts, feedback, the attached lesson, and whether this is
 * the final quiz of the course. PUT replaces the settings wholesale by
 * contract, so the form sends every field, and a field left empty means the
 * documented default, not "keep what was stored".
 *
 * Nothing here is a Pro setting. Shuffle, question banks, cooldowns and
 * negative marking are not in the free contract, so they are not drawn.
 */

const schema = z.object({
    title: z.string().trim().min(1, strings.form.required),
    description: z.string(),
    lessonId: z.string(),
    opensAt: z.string(),
    passMark: z
        .string()
        .refine(
            (value) =>
                value.trim() !== '' &&
                Number.isInteger(Number(value)) &&
                Number(value) >= 0 &&
                Number(value) <= 100,
            strings.form.wholePercent,
        ),
    maxAttempts: z
        .string()
        .refine(
            (value) => value.trim() === '' || (Number.isInteger(Number(value)) && Number(value) >= 1),
            strings.form.positiveNumber,
        ),
    timeLimitMinutes: z
        .string()
        .refine(
            (value) => value.trim() === '' || (Number.isFinite(Number(value)) && Number(value) >= 1 / 60),
            strings.form.positiveNumber,
        ),
    showResults: z.enum(['after_submit', 'never']),
    isFinal: z.boolean(),
});

type Values = z.infer<typeof schema>;

function quizLessonOptions(curriculum: AuthoringCurriculum | undefined, quiz: QuizSummary | null) {
    const options: { id: number; label: string }[] = [];
    for (const module of curriculum?.modules ?? []) {
        for (const lesson of module.lessons) {
            if (lesson.type !== 'quiz') {
                continue;
            }
            /* A quiz lesson holds at most one quiz: offer the free ones plus
               the lesson this quiz already sits on. */
            const takenByAnother =
                typeof lesson.content.quiz_id === 'number' && lesson.content.quiz_id !== quiz?.id;
            if (takenByAnother) {
                continue;
            }
            options.push({ id: lesson.id, label: uiText("vidyalms.ui.ae04d0e6f63d1e51", "{p0} · {p1}", { p0: module.title, p1: lesson.title }) });
        }
    }
    return options;
}

export function QuizSettingsDialog({
    courseId,
    quiz,
    onClose,
    onCreated,
    inline = false,
}: {
    courseId: number;
    quiz: QuizSummary | null;
    onClose: () => void;
    onCreated?: (quizId: number) => void;
    inline?: boolean;
}) {
    const createQuiz = useCreateQuiz();
    const updateQuiz = useUpdateQuiz();
    const curriculumQuery = useAuthoringCurriculum(courseId);
    const quizzesQuery = useCourseQuizzes(courseId);
    const save = useSaveTracker();

    const {
        register,
        handleSubmit,
        reset,
        formState: { errors, isDirty },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            title: quiz?.title ?? '',
            description: quiz?.description ?? '',
            lessonId: quiz?.lesson_id === null || quiz === null ? '' : String(quiz.lesson_id),
            opensAt: (quiz?.policy?.opens_at ?? '').replace(' ', 'T').slice(0, 16),
            passMark: String(quiz?.pass_mark ?? 0),
            maxAttempts: quiz?.max_attempts == null ? '' : String(quiz.max_attempts),
            timeLimitMinutes: quiz?.time_limit_seconds == null ? '' : String(quiz.time_limit_seconds / 60),
            showResults: quiz?.show_results ?? 'after_submit',
            isFinal: quiz?.is_final ?? false,
        },
    });
    useNavigationGuard(isDirty);

    const lessonOptions = quizLessonOptions(curriculumQuery.data, quiz);
    /* An existing final quiz elsewhere is worth naming: only one quiz can be
       the course's final one. */
    const otherFinal =
        (quizzesQuery.data ?? []).find((entry) => entry.is_final && entry.id !== quiz?.id) ?? null;

    const onSubmit = handleSubmit((values) => {
        const description = values.description.trim();
        const body = {
            title: values.title.trim(),
            description: description === '' ? null : description,
            lesson_id:
                inline && quiz !== null
                    ? quiz.lesson_id
                    : values.lessonId === ''
                      ? null
                      : Number(values.lessonId),
            pass_mark: Number(values.passMark),
            max_attempts: values.maxAttempts.trim() === '' ? null : Number(values.maxAttempts),
            time_limit_seconds:
                values.timeLimitMinutes.trim() === ''
                    ? null
                    : Math.round(Number(values.timeLimitMinutes) * 60),
            show_results: values.showResults,
            is_final: values.isFinal,
            policy: {
                ...quiz?.policy,
                opens_at: values.opensAt === '' ? null : `${values.opensAt}:00Z`,
            },
        };
        void save
            .run(async (idempotencyKey) => {
                if (quiz === null) {
                    const created = await createQuiz.mutateAsync({ courseId, body, idempotencyKey });
                    onCreated?.(created.id);
                    return created;
                }
                const updated = await updateQuiz.mutateAsync({
                    courseId,
                    quizId: quiz.id,
                    body,
                    idempotencyKey,
                });
                reset(values);
                return updated;
            })
            .then((ok) => {
                if (ok && !inline) {
                    onClose();
                }
            });
    });

    return (
        <AuthoringSurface
            inline={inline}
            onClose={onClose}
            className="max-w-xl"
            title={quiz === null ? strings.quizSettings.addTitle : strings.quizSettings.editTitle}
            description={inline ? studio.inlineBasics : strings.quizSettings.description}
        >
            <form
                onSubmit={onSubmit}
                className={
                    inline
                        ? 'vy-quiz-basics-form grid gap-5'
                        : 'mt-3.5 grid max-h-[520px] gap-3.5 overflow-y-auto pr-1'
                }
            >
                <Field label={strings.quizSettings.fields.title} error={errors.title?.message}>
                    {({ id, describedBy, invalid }) => (
                        <TextInput
                            id={id}
                            autoFocus={!inline}
                            aria-describedby={describedBy}
                            invalid={invalid}
                            {...register('title')}
                        />
                    )}
                </Field>
                <Field label={strings.quizSettings.fields.description}>
                    {({ id, describedBy }) => (
                        <TextArea
                            id={id}
                            rows={2}
                            aria-describedby={describedBy}
                            {...register('description')}
                        />
                    )}
                </Field>
                {!inline && (
                    <Field
                        label={strings.quizSettings.fields.lesson}
                        hint={strings.quizSettings.fields.lessonHint}
                    >
                        {({ id, describedBy }) => (
                            <Select id={id} aria-describedby={describedBy} {...register('lessonId')}>
                                <option value="">{strings.quizSettings.fields.lessonNone}</option>
                                {lessonOptions.map((option) => (
                                    <option key={option.id} value={String(option.id)}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                )}
                <div className="grid gap-3.5 sm:grid-cols-3">
                    <Field
                        label={uiText('vidyalms.quiz.opens_at_utc', 'Opens at (UTC)')}
                        hint={uiText('vidyalms.quiz.unattached_opening_help', 'A quiz without a lesson stays closed until you set an opening date.')}
                    >
                        {({ id, describedBy }) => (
                            <TextInput id={id} type="datetime-local" aria-describedby={describedBy} {...register('opensAt')} />
                        )}
                    </Field>
                    <Field
                        label={strings.quizSettings.fields.passMark}
                        hint={strings.quizSettings.fields.passMarkHint}
                        error={errors.passMark?.message}
                    >
                        {({ id, describedBy, invalid }) => (
                            <TextInput
                                id={id}
                                inputMode="numeric"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                {...register('passMark')}
                            />
                        )}
                    </Field>
                    <Field
                        label={strings.quizSettings.fields.maxAttempts}
                        hint={strings.quizSettings.fields.maxAttemptsHint}
                        error={errors.maxAttempts?.message}
                    >
                        {({ id, describedBy, invalid }) => (
                            <TextInput
                                id={id}
                                inputMode="numeric"
                                placeholder={strings.quizSettings.fields.maxAttemptsPlaceholder}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                {...register('maxAttempts')}
                            />
                        )}
                    </Field>
                    <Field
                        label={strings.quizSettings.fields.timeLimit}
                        error={errors.timeLimitMinutes?.message}
                    >
                        {({ id, describedBy, invalid }) => (
                            <TextInput
                                id={id}
                                inputMode="numeric"
                                placeholder={strings.quizSettings.fields.timeLimitPlaceholder}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                {...register('timeLimitMinutes')}
                            />
                        )}
                    </Field>
                </div>
                <Field label={strings.quizSettings.fields.showResults}>
                    {({ id, describedBy }) => (
                        <Select id={id} aria-describedby={describedBy} {...register('showResults')}>
                            <option value="after_submit">
                                {strings.quizSettings.fields.showResultsAfter}
                            </option>
                            <option value="never">{strings.quizSettings.fields.showResultsNever}</option>
                        </Select>
                    )}
                </Field>
                <CheckboxField
                    label={strings.quizSettings.fields.isFinal}
                    hint={
                        otherFinal === null
                            ? strings.quizSettings.fields.isFinalHint
                            : uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: strings.quizSettings.fields.isFinalHint, p1: otherFinal.title })
                    }
                    {...register('isFinal')}
                />
                {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
                <DialogFooter className="items-center">
                    <SaveIndicator className="mr-auto" state={save.state} savedAt={save.savedAt} />
                    {!inline && (
                        <Button type="button" variant="outline" size="sm" onClick={onClose}>
                            {strings.form.cancel}
                        </Button>
                    )}
                    <Button type="submit" size="sm" disabled={save.state === 'saving'}>
                        {quiz === null ? strings.quizSettings.create : strings.quizSettings.save}
                    </Button>
                </DialogFooter>
            </form>
        </AuthoringSurface>
    );
}
