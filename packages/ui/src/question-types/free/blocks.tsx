import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form';
import { Plus, X } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Button, Icon } from '@/shared/components';
import { questionAuthoringStrings as strings } from '@/question-types/authoring-strings';
import {
    arrayErrorMessage,
    CheckboxField,
    Field,
    FormSection,
    TextArea,
    TextInput,
} from '@/instructor/components/field';
import { OrderControls } from '@/instructor/components/order-controls';
import { focusRing } from '../primitives/styles';
import { linesOf, nextKey, type QuestionFormValues } from './form-model';

/*
 * The authoring blocks of the six free types, one per definition shape.
 *
 * They live beside the modules that register them rather than behind a
 * switch in the workbench, which is the point of the client registry: the
 * editor around a block is one pattern (prompt, this block, marking) and
 * adding a type is a registration.
 *
 * Correctness lives here and only here. It reaches the browser from
 * GET /manage/quizzes/{id}, the one management route that carries it, and
 * never travels to a learner before their attempt is submitted.
 */

export interface BlockProps {
    form: UseFormReturn<QuestionFormValues>;
}

const removeButton =
    'flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded border border-border bg-surface text-fg-2 hover:text-fg disabled:cursor-default disabled:opacity-40';

export function ChoiceBlock({ form, multiple }: BlockProps & { multiple: boolean }) {
    const options = useFieldArray({ control: form.control, name: 'options' });
    const watched = useWatch({ control: form.control, name: 'options' }) ?? [];
    const errors = form.formState.errors;

    /* Single choice keeps exactly one mark: choosing one clears the rest. */
    const mark = (index: number, checked: boolean) => {
        if (multiple) {
            form.setValue(`options.${index}.correct`, checked, { shouldDirty: true });
            return;
        }
        options.fields.forEach((_field, position) => {
            form.setValue(`options.${position}.correct`, position === index, { shouldDirty: true });
        });
    };

    return (
        <FormSection
            title={strings.question.options}
            note={multiple ? strings.question.markCorrectMany : strings.question.markCorrectOne}
        >
            <ul className="grid gap-2">
                {options.fields.map((field, index) => {
                    const correct = watched[index]?.correct === true;
                    return (
                        <li key={field.id} className="flex items-start gap-2.5">
                            <input
                                type={multiple ? 'checkbox' : 'radio'}
                                name={multiple ? `option-${index}` : 'correct-option'}
                                checked={correct}
                                onChange={(event) => mark(index, event.target.checked)}
                                aria-label={strings.question.optionCorrect(index + 1)}
                                className={cn(
                                    'mt-[11px] h-4 w-4 shrink-0 cursor-pointer accent-accent',
                                    correct && 'accent-ok',
                                )}
                            />
                            <Field
                                className="min-w-0 flex-1"
                                label={strings.question.optionLabel(index + 1)}
                                hideLabel
                                error={errors.options?.[index]?.label?.message}
                            >
                                {({ id, invalid }) => (
                                    <TextInput
                                        id={id}
                                        aria-label={strings.question.optionLabel(index + 1)}
                                        invalid={invalid}
                                        className={cn(correct && 'border-ok bg-ok-soft font-medium')}
                                        {...form.register(`options.${index}.label` as const)}
                                    />
                                )}
                            </Field>
                            <button
                                type="button"
                                aria-label={strings.question.removeOption(index + 1)}
                                disabled={options.fields.length <= 2}
                                onClick={() => options.remove(index)}
                                className={cn(removeButton, 'mt-[3px]')}
                            >
                                <Icon icon={X} size={14} />
                            </button>
                        </li>
                    );
                })}
            </ul>
            {arrayErrorMessage(errors.options) !== undefined && (
                <p className="text-[11.5px] font-medium text-danger">{arrayErrorMessage(errors.options)}</p>
            )}
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        options.append({ key: nextKey(form.getValues('options'), 'opt'), label: '', correct: false })
                    }
                >
                    <Icon icon={Plus} size={13} />
                    {strings.question.addOption}
                </Button>
            </div>
        </FormSection>
    );
}

export function TrueFalseBlock({ form }: BlockProps) {
    const value = useWatch({ control: form.control, name: 'correctBool' }) ?? 'true';
    return (
        <FormSection title={strings.question.correctAnswer}>
            <div role="radiogroup" aria-label={strings.question.correctAnswer} className="flex gap-2">
                {(['true', 'false'] as const).map((option) => {
                    const selected = value === option;
                    return (
                        <label
                            key={option}
                            className={cn(
                                'flex min-h-[44px] flex-1 cursor-pointer items-center justify-center gap-2 rounded border font-display text-[14px]',
                                focusRing,
                                selected
                                    ? 'border-2 border-ok bg-ok-soft font-bold text-ok-strong'
                                    : 'border-border bg-surface font-semibold text-fg',
                            )}
                        >
                            <input type="radio" value={option} className="sr-only" {...form.register('correctBool')} />
                            <span aria-hidden="true">{selected ? '✓' : ''}</span>
                            {option === 'true' ? strings.question.trueLabel : strings.question.falseLabel}
                        </label>
                    );
                })}
            </div>
        </FormSection>
    );
}

export function ShortAnswerBlock({ form }: BlockProps) {
    const accepted = useWatch({ control: form.control, name: 'accepted' }) ?? '';
    const manual = linesOf(accepted).length === 0;
    return (
        <FormSection title={strings.question.accepted}>
            <Field label={strings.question.accepted} hideLabel hint={strings.question.acceptedHint}>
                {({ id, describedBy }) => (
                    <TextArea
                        id={id}
                        rows={4}
                        aria-label={strings.question.accepted}
                        aria-describedby={describedBy}
                        {...form.register('accepted')}
                    />
                )}
            </Field>
            {manual && (
                <p className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-3 py-2 text-[12px] text-fg-2">
                    {strings.question.acceptedEmptyNote}
                </p>
            )}
            <CheckboxField label={strings.question.caseSensitive} {...form.register('caseSensitive')} />
        </FormSection>
    );
}

export function FillBlankBlock({ form }: BlockProps) {
    const blanks = useFieldArray({ control: form.control, name: 'blanks' });
    const errors = form.formState.errors;
    return (
        <FormSection title={strings.question.blanks} note={strings.question.blanksHint}>
            <ul className="grid gap-2.5">
                {blanks.fields.map((field, index) => (
                    <li key={field.id} className="flex items-start gap-2.5 rounded border border-border p-2.5">
                        <Field
                            className="w-[130px] shrink-0"
                            label={strings.question.blankKey(index + 1)}
                            hideLabel
                            error={errors.blanks?.[index]?.key?.message}
                        >
                            {({ id, invalid }) => (
                                <TextInput
                                    id={id}
                                    aria-label={strings.question.blankKey(index + 1)}
                                    className="font-mono text-xs"
                                    invalid={invalid}
                                    {...form.register(`blanks.${index}.key` as const)}
                                />
                            )}
                        </Field>
                        <Field
                            className="min-w-0 flex-1"
                            label={strings.question.blankAccepted(index + 1)}
                            hideLabel
                            error={errors.blanks?.[index]?.accepted?.message}
                        >
                            {({ id, invalid }) => (
                                <TextArea
                                    id={id}
                                    rows={2}
                                    aria-label={strings.question.blankAccepted(index + 1)}
                                    invalid={invalid}
                                    {...form.register(`blanks.${index}.accepted` as const)}
                                />
                            )}
                        </Field>
                        <button
                            type="button"
                            aria-label={strings.question.removeBlank(index + 1)}
                            disabled={blanks.fields.length <= 1}
                            onClick={() => blanks.remove(index)}
                            className={removeButton}
                        >
                            <Icon icon={X} size={14} />
                        </button>
                    </li>
                ))}
            </ul>
            {arrayErrorMessage(errors.blanks) !== undefined && (
                <p className="text-[11.5px] font-medium text-danger">{arrayErrorMessage(errors.blanks)}</p>
            )}
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => blanks.append({ key: nextKey(form.getValues('blanks'), 'blank'), accepted: '' })}
                >
                    <Icon icon={Plus} size={13} />
                    {strings.question.addBlank}
                </Button>
                <CheckboxField label={strings.question.caseSensitive} {...form.register('caseSensitive')} />
            </div>
        </FormSection>
    );
}

export function OrderingBlock({ form }: BlockProps) {
    const items = useFieldArray({ control: form.control, name: 'items' });
    const errors = form.formState.errors;
    return (
        <FormSection title={strings.question.items} note={strings.question.itemsHint}>
            <ol className="grid gap-2">
                {items.fields.map((field, index) => (
                    <li key={field.id} className="flex items-start gap-2.5">
                        <span
                            aria-hidden="true"
                            className="mt-3 w-4 shrink-0 font-mono text-[11px] font-bold text-fg-2"
                        >
                            {index + 1}
                        </span>
                        <Field
                            className="min-w-0 flex-1"
                            label={strings.question.itemLabel(index + 1)}
                            hideLabel
                            error={errors.items?.[index]?.label?.message}
                        >
                            {({ id, invalid }) => (
                                <TextInput
                                    id={id}
                                    aria-label={strings.question.itemLabel(index + 1)}
                                    invalid={invalid}
                                    {...form.register(`items.${index}.label` as const)}
                                />
                            )}
                        </Field>
                        <OrderControls
                            className="mt-[3px]"
                            size="sm"
                            upLabel={strings.question.moveItemUp(index + 1)}
                            downLabel={strings.question.moveItemDown(index + 1)}
                            canMoveUp={index > 0}
                            canMoveDown={index < items.fields.length - 1}
                            onMoveUp={() => items.move(index, index - 1)}
                            onMoveDown={() => items.move(index, index + 1)}
                        />
                        <button
                            type="button"
                            aria-label={strings.question.removeItem(index + 1)}
                            disabled={items.fields.length <= 2}
                            onClick={() => items.remove(index)}
                            className={cn(removeButton, 'mt-[3px]')}
                        >
                            <Icon icon={X} size={14} />
                        </button>
                    </li>
                ))}
            </ol>
            {arrayErrorMessage(errors.items) !== undefined && (
                <p className="text-[11.5px] font-medium text-danger">{arrayErrorMessage(errors.items)}</p>
            )}
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => items.append({ key: nextKey(form.getValues('items'), 'item'), label: '' })}
                >
                    <Icon icon={Plus} size={13} />
                    {strings.question.addItem}
                </Button>
            </div>
        </FormSection>
    );
}
