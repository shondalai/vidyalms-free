import { useFieldArray, type UseFormReturn } from 'react-hook-form';
import { Button } from '@/shared/components';
import type { LessonFormValues } from '../lib/lesson-content';
import { studioStrings as s } from '../studio-strings';
import { CheckboxField, Field, FormSection, Select, TextArea, TextInput } from '../components/field';

export function LessonDelivery({ type, form }: { type: string; form: UseFormReturn<LessonFormValues> }) {
    const {
        register,
        formState: { errors },
    } = form;
    const captions = useFieldArray({ control: form.control, name: 'presentation.captions' });
    const problem = errors.presentation;
    if (type === 'video')
        return (
            <div className="grid gap-5">
                <FormSection title={s.delivery}>
                    <Field label={s.poster} error={problem?.poster_url?.message}>
                        {({ id, invalid }) => (
                            <TextInput id={id} invalid={invalid} {...register('presentation.poster_url')} />
                        )}
                    </Field>
                    <Field label={s.transcript} error={problem?.transcript?.message}>
                        {({ id, invalid }) => (
                            <TextArea
                                id={id}
                                invalid={invalid}
                                rows={7}
                                {...register('presentation.transcript')}
                            />
                        )}
                    </Field>
                    <p className="text-xs text-fg-2">{s.nativeHint}</p>
                    <CheckboxField label={s.resume} {...register('presentation.resume')} />
                    <CheckboxField
                        label={s.pictureInPicture}
                        {...register('presentation.picture_in_picture')}
                    />
                    <CheckboxField label={s.videoDownload} {...register('presentation.download')} />
                </FormSection>
                <FormSection title={s.captions}>
                    {captions.fields.map((track, index) => (
                        <div key={track.id} className="rounded-lg border border-border p-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    label={s.captionLabel}
                                    error={problem?.captions?.[index]?.label?.message}
                                >
                                    {({ id }) => (
                                        <TextInput
                                            id={id}
                                            {...register(`presentation.captions.${index}.label`)}
                                        />
                                    )}
                                </Field>
                                <Field
                                    label={s.captionLanguage}
                                    error={problem?.captions?.[index]?.language?.message}
                                >
                                    {({ id }) => (
                                        <TextInput
                                            id={id}
                                            {...register(`presentation.captions.${index}.language`)}
                                        />
                                    )}
                                </Field>
                                <Field
                                    label={s.captionSource}
                                    className="sm:col-span-2"
                                    error={problem?.captions?.[index]?.url?.message}
                                >
                                    {({ id }) => (
                                        <TextInput
                                            id={id}
                                            {...register(`presentation.captions.${index}.url`)}
                                        />
                                    )}
                                </Field>
                            </div>
                            <Button variant="ghost" size="sm" onClick={() => captions.remove(index)}>
                                {s.removeCaption}
                            </Button>
                        </div>
                    ))}
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={captions.fields.length >= 20}
                        onClick={() => captions.append({ label: '', language: '', url: '' })}
                    >
                        {s.addCaption}
                    </Button>
                </FormSection>
            </div>
        );
    if (type === 'reading')
        return (
            <FormSection title={s.delivery}>
                <Field label={s.readingWidth}>
                    {({ id }) => (
                        <Select id={id} {...register('presentation.reading_width')}>
                            <option value="comfortable">{s.comfortable}</option>
                            <option value="wide">{s.wide}</option>
                            <option value="full">{s.full}</option>
                        </Select>
                    )}
                </Field>
                <CheckboxField label={s.toc} {...register('presentation.show_contents')} />
                <CheckboxField label={s.print} {...register('presentation.allow_print')} />
            </FormSection>
        );
    if (type === 'resource')
        return (
            <FormSection title={s.delivery}>
                <Field label={s.intro} error={problem?.introduction?.message}>
                    {({ id, invalid }) => (
                        <TextArea
                            id={id}
                            invalid={invalid}
                            rows={5}
                            {...register('presentation.introduction')}
                        />
                    )}
                </Field>
                <CheckboxField label={s.showFileSize} {...register('presentation.show_file_size')} />
                <CheckboxField label={s.openNewTab} {...register('presentation.new_tab')} />
            </FormSection>
        );
    return null;
}
