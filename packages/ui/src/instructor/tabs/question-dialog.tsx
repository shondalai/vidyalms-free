import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import type { AuthoringQuestion } from '@vidyalms/api-sdk';
import type { QuestionDefinition } from '@/question-types';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    SaveIndicator,
} from '@/shared/components';
import { SaveError } from '../components/states';
import { useAddQuestion, useRemoveQuestion, useUpdateQuestion } from '../api';
import { instructorStrings as strings } from '../strings';
import {
    blankQuestionValues,
    questionDefinition,
    questionFormSchema,
    questionFormValues,
    type QuestionFormValues,
} from '../lib/question-definitions';
import { useSaveTracker } from '../lib/save-state';
import { Field, FormSection, TextArea, TextInput } from '../components/field';
import { QuestionTypeChip } from '../components/chips';
import { AnswerBlock } from './answer-blocks';

/*
 * Artboard 2b, the question editor: one pattern for every registered type.
 * Prompt, then the type-specific answer block, then marking (points and the
 * feedback shown after submission). The type is chosen before this dialog
 * opens and is fixed for the life of a question, exactly as the contract
 * states, so the editor says so instead of offering a control that would
 * answer 422.
 */

export function QuestionDialog({
    courseId,
    quizId,
    question,
    type,
    seed = {},
    scored = true,
    typeLabel,
    position,
    onClose,
}: {
    courseId: number;
    quizId: number;
    question: AuthoringQuestion | null;
    type: string;
    /**
     * What a new question of this entry starts from: the preset's skeleton
     * and the marking rule it names. It is handed to the save as the stored
     * definition, so every key no form field covers survives, which is the
     * same rule that keeps `policy` and `shuffle` on an edit.
     */
    seed?: QuestionDefinition;
    /**
     * Whether a new question counts towards the attempt totals. A preset
     * whose marking rule scores nothing (the opinion scale) is authored
     * unscored, and the server refuses the other pairing, so the picker says
     * so and the add request carries it. Read only for a new question: an
     * edit revises the definition and points and leaves the column alone.
     */
    scored?: boolean;
    typeLabel: string;
    position: number;
    onClose: () => void;
}) {
    const addQuestion = useAddQuestion();
    const updateQuestion = useUpdateQuestion();
    const removeQuestion = useRemoveQuestion();
    const save = useSaveTracker();
    const [confirmRemove, setConfirmRemove] = useState(false);

    const schema = useMemo(() => questionFormSchema(type), [type]);
    const form = useForm<QuestionFormValues>({
        resolver: zodResolver(schema),
        // A new question starts from its type's own blank form values, never
        // from the seed read back through them: a skeleton names structure
        // (the layout, the mode, the marking rule) and carries no prompt, no
        // choices and no key, so reading it as form values would replace the
        // blank rows a type opens with by the empty ones an empty definition
        // produces. The seed reaches the type's own block below, and reaches
        // the save as the stored definition.
        defaultValues:
            question === null
                ? blankQuestionValues(type)
                : { ...questionFormValues(type, question.definition), points: String(question.points) },
    });

    const onSubmit = form.handleSubmit((values) => {
        const definition = questionDefinition(type, values, question?.definition ?? seed);
        const points = Number(values.points);
        void save
            .run((idempotencyKey) =>
                question === null
                    ? addQuestion.mutateAsync({
                          courseId,
                          quizId,
                          body: scored
                              ? { type, definition, points }
                              : { type, definition, points, scored: false },
                          idempotencyKey,
                      })
                    : updateQuestion.mutateAsync({
                          courseId,
                          quizId,
                          questionId: question.id,
                          body: { definition, points },
                          idempotencyKey,
                      }),
            )
            .then((ok) => {
                if (ok) {
                    onClose();
                }
            });
    });

    const onRemove = () => {
        if (question === null) {
            return;
        }
        void save
            .run((idempotencyKey) =>
                removeQuestion.mutateAsync({ courseId, quizId, questionId: question.id, idempotencyKey }),
            )
            .then((ok) => {
                if (ok) {
                    onClose();
                }
            });
    };

    if (confirmRemove && question !== null) {
        return (
            <Dialog open onOpenChange={(open) => !open && setConfirmRemove(false)}>
                <DialogContent className="max-w-md">
                    <DialogTitle>{strings.question.removeTitle}</DialogTitle>
                    <DialogDescription>{strings.question.removeBody}</DialogDescription>
                    {save.state === 'failed' && <SaveError className="mt-3" error={save.error} onRetry={save.retry} />}
                    <DialogFooter>
                        <Button variant="outline" size="sm" onClick={() => setConfirmRemove(false)}>
                            {strings.question.keep}
                        </Button>
                        <Button variant="destructive" size="sm" onClick={onRemove} disabled={save.state === 'saving'}>
                            {strings.question.removeConfirm}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="vy-quiz-question-dialog max-w-2xl">
                <DialogTitle>
                    {question === null ? strings.question.addTitle : strings.question.editTitle(position)}
                </DialogTitle>
                <DialogDescription>
                    <QuestionTypeChip type={type} className="mr-1.5 align-middle" />
                    {typeLabel}
                    {question !== null && <span className="ml-1.5">{strings.question.typeFixed}</span>}
                </DialogDescription>
                <form onSubmit={onSubmit} className="mt-3.5 grid max-h-[520px] gap-4 overflow-y-auto pr-1">
                    <Field label={strings.question.prompt} error={form.formState.errors.prompt?.message}>
                        {({ id, describedBy, invalid }) => (
                            <TextArea
                                id={id}
                                rows={2}
                                autoFocus
                                aria-describedby={describedBy}
                                invalid={invalid}
                                {...form.register('prompt')}
                            />
                        )}
                    </Field>

                    <AnswerBlock type={type} form={form} definition={question?.definition ?? seed} />

                    <FormSection title={strings.question.marking} note={strings.question.correctOnly}>
                        <div className="grid gap-3.5 sm:grid-cols-[120px_1fr]">
                            <Field
                                label={strings.question.pointsLabel}
                                error={form.formState.errors.points?.message}
                            >
                                {({ id, invalid }) => (
                                    <TextInput
                                        id={id}
                                        inputMode="decimal"
                                        invalid={invalid}
                                        {...form.register('points')}
                                    />
                                )}
                            </Field>
                            <Field label={strings.question.feedback} hint={strings.question.feedbackOptional}>
                                {({ id, describedBy }) => (
                                    <TextInput id={id} aria-describedby={describedBy} {...form.register('feedback')} />
                                )}
                            </Field>
                        </div>
                    </FormSection>

                    {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}

                    <DialogFooter className="items-center">
                        {question !== null && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="mr-auto text-danger"
                                onClick={() => setConfirmRemove(true)}
                            >
                                {strings.question.remove}
                            </Button>
                        )}
                        <SaveIndicator
                            className={question === null ? 'mr-auto' : undefined}
                            state={save.state}
                            savedAt={save.savedAt}
                        />
                        <Button type="button" variant="outline" size="sm" onClick={onClose}>
                            {strings.form.cancel}
                        </Button>
                        <Button type="submit" size="sm" disabled={save.state === 'saving'}>
                            {question === null ? strings.question.add : strings.question.save}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
