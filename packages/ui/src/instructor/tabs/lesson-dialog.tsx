import { lazy, Suspense } from 'react';
const ArticleEditor = lazy(() =>
    import('../components/article-editor').then((module) => ({ default: module.ArticleEditor })),
);
import { LessonDelivery } from './lesson-delivery';
import { LessonContentPreview } from './lesson-content-preview';
import { studioStrings as studio } from '../studio-strings';
import { practicalStrings as practical } from '@/shared/practical-strings';
import { quizStudioStrings as quizStudio } from '../quiz-studio-strings';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { Tabs } from '../components/tabs';
import { useFieldArray, useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Plus, X } from 'lucide-react';
import type { AuthoringLesson } from '@vidyalms/api-sdk';
import {
    Button,
    Pagination,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    FilePicker,
    Icon,
    SaveIndicator,
} from '@/shared/components';
import { policyFor, RESOURCE_POLICY } from '@/shared/upload';
import { useInstructorConfig } from '../config';
import {
    useAddLesson,
    useConfigureAssignment,
    useCourseQuizzes,
    useLessonTypes,
    useUpdateLesson,
} from '../api';
import { instructorStrings as strings } from '../strings';
import {
    assignmentRequest,
    blankLessonValues,
    durationSeconds,
    lessonContent,
    lessonFormSchema,
    lessonFormValues,
    VIDEO_PROVIDERS,
    type LessonFormValues,
} from '../lib/lesson-content';
import { useSaveTracker } from '../lib/save-state';
import {
    arrayErrorMessage,
    CheckboxField,
    Field,
    FormSection,
    Select,
    TextArea,
    TextInput,
} from '../components/field';
import { LessonTypeChip } from '../components/chips';
import { PanelState, SaveError } from '../components/states';
import { SubjectRestrictions } from './availability/subject-restrictions';

/*
 * The lesson edit form, one dialog per lesson type (artboard 1a, "lesson
 * edit form per type"). The metadata block is the same for every type; the
 * content block below it belongs to the type, and a type with no block here
 * (an add-on's, on a site without the add-on) renders the neutral
 * placeholder and keeps its stored content untouched.
 *
 * An assignment lesson is two writes by contract: the lesson carries the
 * instructions, the assignment row carries the submission rules. They run
 * in order under one save state, so a failure of either is one honest
 * "not saved" with a retry that replays the same idempotency key.
 */

export function LessonDialog({
    courseId,
    moduleId,
    lesson,
    type,
    onClose,
    inline = false,
    keepOpen = false,
    metadataOnly = false,
    onSaved,
    onOpenQuiz,
}: {
    courseId: number;
    moduleId: number;
    lesson: AuthoringLesson | null;
    type: string;
    onClose: () => void;
    inline?: boolean;
    keepOpen?: boolean;
    metadataOnly?: boolean;
    onSaved?: (lesson: AuthoringLesson) => void;
    onOpenQuiz?: (quizId: number | null) => void;
}) {
    const addLesson = useAddLesson();
    const updateLesson = useUpdateLesson();
    const configureAssignment = useConfigureAssignment();
    const lessonTypesQuery = useLessonTypes();
    const save = useSaveTracker();

    const schema = useMemo(() => lessonFormSchema(type), [type]);
    const form = useForm<LessonFormValues>({
        resolver: zodResolver(schema),
        defaultValues: lesson === null ? blankLessonValues(type) : lessonFormValues(lesson),
    });

    const [section, setSection] = useState<
        'lesson-content' | 'lesson-details' | 'lesson-access' | 'lesson-delivery' | 'lesson-preview'
    >(metadataOnly ? 'lesson-details' : 'lesson-content');
    const [confirmClose, setConfirmClose] = useState(false);
    const [accessDirty, setAccessDirty] = useState(false);
    useNavigationGuard(form.formState.isDirty);
    const requestClose = () => {
        if (form.formState.isDirty || accessDirty) setConfirmClose(true);
        else onClose();
    };
    const typeLabel = lessonTypesQuery.data?.find((entry) => entry.key === type)?.label ?? type;
    /* content_available is false when no provider is registered for the
       type: the content is stored and travels, but nothing here can present
       or validate it. */
    const editable = lesson === null || lesson.content_available;

    const onSubmit = form.handleSubmit(
        (values) => {
            const content = editable && !metadataOnly ? lessonContent(type, values, lesson?.content) : null;
            void save.run(async (idempotencyKey) => {
                const summary = values.summary.trim();
                if (lesson === null) {
                    const created = await addLesson.mutateAsync({
                        courseId,
                        moduleId,
                        body: {
                            title: values.title.trim(),
                            type,
                            ...(content === null ? {} : { content }),
                            summary: summary === '' ? null : summary,
                            duration_seconds: durationSeconds(values),
                            free_preview: values.freePreview,
                            state: values.state,
                        },
                        idempotencyKey,
                    });
                    if (type === 'assignment') {
                        created.assignment = await configureAssignment.mutateAsync({
                            courseId,
                            lessonId: created.id,
                            body: assignmentRequest(values),
                            idempotencyKey: `${idempotencyKey}-assignment`,
                        });
                    }
                    form.reset(values);
                    if (keepOpen) onSaved?.(created);
                    else if (!accessDirty) onClose();
                    return created;
                }
                const updated = await updateLesson.mutateAsync({
                    courseId,
                    lessonId: lesson.id,
                    body: {
                        title: values.title.trim(),
                        summary: summary === '' ? null : summary,
                        duration_seconds: durationSeconds(values),
                        free_preview: values.freePreview,
                        state: values.state,
                        ...(content === null ? {} : { content }),
                    },
                    idempotencyKey,
                });
                if (type === 'assignment') {
                    updated.assignment = await configureAssignment.mutateAsync({
                        courseId,
                        lessonId: lesson.id,
                        body: assignmentRequest(values),
                        idempotencyKey: `${idempotencyKey}-assignment`,
                    });
                }
                form.reset(values);
                if (keepOpen) onSaved?.(updated);
                else if (!accessDirty) onClose();
                return updated;
            });
        },
        (errors) => {
            setSection(
                errors.presentation
                    ? 'lesson-delivery'
                    : errors.durationMinutes || errors.state
                      ? 'lesson-details'
                      : 'lesson-content',
            );
        },
    );

    const errors = form.formState.errors;

    return (
        <LessonFrame inline={inline} onClose={requestClose}>
            {metadataOnly ? null : inline ? (
                <header className="vy-inline-lesson-heading">
                    <div>
                        <p className="vy-studio-eyebrow">{typeLabel}</p>
                        <h3>
                            {lesson === null
                                ? strings.lesson.addTitle(typeLabel)
                                : keepOpen
                                  ? lesson.title
                                  : strings.lesson.editTitle}
                        </h3>
                    </div>
                    {keepOpen && (
                        <Button
                            type="submit"
                            form="vy-current-lesson-form"
                            size="sm"
                            disabled={save.state === 'saving'}
                        >
                            {lesson === null ? strings.lesson.add : strings.lesson.save}
                        </Button>
                    )}
                </header>
            ) : (
                <>
                    <DialogTitle>
                        {lesson === null ? strings.lesson.addTitle(typeLabel) : strings.lesson.editTitle}
                    </DialogTitle>
                    <DialogDescription>
                        <LessonTypeChip type={type} className="mr-1.5 align-middle" />
                        {typeLabel}
                    </DialogDescription>
                </>
            )}
            <form
                id="vy-current-lesson-form"
                onSubmit={onSubmit}
                className="vy-lesson-form mt-3.5 grid gap-4"
            >
                <Field label={strings.lesson.fields.title} error={errors.title?.message}>
                    {({ id, describedBy, invalid }) => (
                        <TextInput
                            id={id}
                            aria-describedby={describedBy}
                            invalid={invalid}
                            autoFocus={!keepOpen || lesson === null}
                            {...form.register('title')}
                        />
                    )}
                </Field>
                {!metadataOnly && (
                    <Tabs
                        label={strings.lesson.customize}
                        current={section}
                        onSelect={setSection}
                        tabs={[
                            { id: 'lesson-content', label: strings.lesson.contentTab },
                            { id: 'lesson-details', label: strings.lesson.detailsTab },
                            ...(['video', 'reading', 'resource'].includes(type)
                                ? [{ id: 'lesson-delivery' as const, label: studio.delivery }]
                                : []),
                            { id: 'lesson-preview', label: studio.preview },
                            ...(lesson === null
                                ? []
                                : [{ id: 'lesson-access' as const, label: strings.lesson.accessTab }]),
                        ]}
                    />
                )}
                <div
                    id="vy-tabpanel-lesson-details"
                    role="tabpanel"
                    aria-labelledby={metadataOnly ? undefined : 'vy-tab-lesson-details'}
                    aria-label={metadataOnly ? quizStudio.tabs.details : undefined}
                    hidden={section !== 'lesson-details'}
                    className={section === 'lesson-details' ? 'vy-lesson-section grid gap-4' : 'hidden'}
                >
                    <Field label={strings.lesson.fields.summary}>
                        {({ id, describedBy }) => (
                            <TextArea
                                id={id}
                                rows={2}
                                aria-describedby={describedBy}
                                {...form.register('summary')}
                            />
                        )}
                    </Field>
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label={strings.lesson.fields.duration}
                            hint={strings.lesson.fields.durationHint}
                            error={errors.durationMinutes?.message}
                        >
                            {({ id, describedBy, invalid }) => (
                                <TextInput
                                    id={id}
                                    inputMode="numeric"
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    {...form.register('durationMinutes')}
                                />
                            )}
                        </Field>
                        <Field label={strings.lesson.fields.state}>
                            {({ id, describedBy }) => (
                                <Select id={id} aria-describedby={describedBy} {...form.register('state')}>
                                    <option value="published">{strings.module.fields.statePublished}</option>
                                    <option value="draft">{strings.module.fields.stateDraft}</option>
                                </Select>
                            )}
                        </Field>
                    </div>
                    <CheckboxField
                        label={strings.lesson.fields.freePreview}
                        hint={strings.lesson.fields.freePreviewHint}
                        {...form.register('freePreview')}
                    />
                </div>
                {!metadataOnly && (
                    <div
                        id="vy-tabpanel-lesson-content"
                        role="tabpanel"
                        aria-labelledby="vy-tab-lesson-content"
                        hidden={section !== 'lesson-content'}
                        className="vy-lesson-section"
                    >
                        {keepOpen && type === 'quiz' ? (
                            <div className="vy-quiz-create-intro">
                                <p>{quizStudio.createIntro}</p>
                                <details>
                                    <summary>{quizStudio.reuse}</summary>
                                    <QuizBlock courseId={courseId} form={form} lesson={lesson} />
                                </details>
                            </div>
                        ) : editable ? (
                            <ContentBlock courseId={courseId} type={type} form={form} lesson={lesson} />
                        ) : (
                            <PanelState
                                glyph="◇"
                                title={strings.lesson.unavailable.title(type)}
                                body={strings.lesson.unavailable.body}
                                className="rounded border border-border bg-bg"
                            />
                        )}
                    </div>
                )}
                <div
                    id="vy-tabpanel-lesson-delivery"
                    role="tabpanel"
                    aria-labelledby="vy-tab-lesson-delivery"
                    hidden={section !== 'lesson-delivery'}
                >
                    <LessonDelivery type={type} form={form} />
                </div>
                {section === 'lesson-preview' && (
                    <div
                        id="vy-tabpanel-lesson-preview"
                        role="tabpanel"
                        aria-labelledby="vy-tab-lesson-preview"
                    >
                        <LessonContentPreview type={type} control={form.control} />
                    </div>
                )}
                {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
                {/* The lesson edit form is where the design says a
                        restriction is authored. There is no row to hang one
                        on until the lesson exists, so it appears on the edit
                        pass and not on the add pass. */}
            </form>
            {lesson !== null && !metadataOnly && (
                <div
                    id="vy-tabpanel-lesson-access"
                    role="tabpanel"
                    aria-labelledby="vy-tab-lesson-access"
                    hidden={section !== 'lesson-access'}
                >
                    <SubjectRestrictions
                        subjectType="lesson"
                        subjectId={lesson.id}
                        courseId={courseId}
                        onDirtyChange={setAccessDirty}
                    />
                </div>
            )}
            <DialogFooter className="vy-lesson-footer items-center">
                <SaveIndicator className="mr-auto" state={save.state} savedAt={save.savedAt} />
                {!metadataOnly && (
                    <Button type="button" variant="outline" size="sm" onClick={requestClose}>
                        {strings.form.cancel}
                    </Button>
                )}
                {(!keepOpen || metadataOnly) && (
                    <Button
                        type="submit"
                        form="vy-current-lesson-form"
                        size="sm"
                        disabled={save.state === 'saving'}
                    >
                        {lesson === null ? strings.lesson.add : strings.lesson.save}
                    </Button>
                )}
            </DialogFooter>
            {type === 'quiz' && !keepOpen && onOpenQuiz !== undefined && (
                <div className="vy-quiz-editor-link">
                    <p>{studio.assessmentHint}</p>
                    <Button variant="outline" onClick={() => onOpenQuiz(lesson?.quiz?.id ?? null)}>
                        {studio.openAssessment}
                    </Button>
                </div>
            )}
            {confirmClose && (
                <div role="alert" className="mt-4 rounded-lg border border-border bg-bg p-4">
                    <p className="mb-3 text-sm text-fg">{strings.lesson.unsaved}</p>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => setConfirmClose(false)}>
                            {strings.lesson.keepEditing}
                        </Button>
                        <Button variant="destructive" onClick={onClose}>
                            {strings.lesson.discard}
                        </Button>
                    </div>
                </div>
            )}
        </LessonFrame>
    );
}

function LessonFrame({
    inline,
    onClose,
    children,
}: {
    inline: boolean;
    onClose: () => void;
    children: ReactNode;
}) {
    if (inline)
        return (
            <section className="vy-inline-lesson" aria-label={strings.lesson.editTitle}>
                {children}
            </section>
        );
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="vy-lesson-editor max-w-[840px]">{children}</DialogContent>
        </Dialog>
    );
}

function ContentBlock({
    courseId,
    type,
    form,
    lesson,
}: {
    courseId: number;
    type: string;
    form: UseFormReturn<LessonFormValues>;
    lesson: AuthoringLesson | null;
}) {
    switch (type) {
        case 'video':
            return <VideoBlock form={form} />;
        case 'reading':
            return <ReadingBlock form={form} />;
        case 'resource':
            return <ResourceBlock form={form} />;
        case 'assignment':
            return <AssignmentBlock form={form} />;
        case 'quiz':
            return <QuizBlock courseId={courseId} form={form} lesson={lesson} />;
        default:
            return null;
    }
}

function VideoBlock({ form }: { form: UseFormReturn<LessonFormValues> }) {
    const errors = form.formState.errors;
    return (
        <FormSection title={strings.lesson.fields.videoProvider}>
            <div className="grid gap-3.5 sm:grid-cols-[160px_1fr]">
                <Field label={strings.lesson.fields.videoProvider} hideLabel>
                    {({ id }) => (
                        <Select id={id} {...form.register('provider')}>
                            {VIDEO_PROVIDERS.map((provider) => (
                                <option key={provider} value={provider}>
                                    {strings.lesson.providers[provider]}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                <Field label={strings.lesson.fields.videoUrl} hideLabel error={errors.url?.message}>
                    {({ id, describedBy, invalid }) => (
                        <TextInput
                            id={id}
                            inputMode="url"
                            placeholder="https://"
                            aria-label={strings.lesson.fields.videoUrl}
                            aria-describedby={describedBy}
                            invalid={invalid}
                            {...form.register('url')}
                        />
                    )}
                </Field>
            </div>
            <Field
                label={strings.lesson.fields.captionUrl}
                hint={strings.lesson.fields.captionHint}
                error={errors.captionUrl?.message}
            >
                {({ id, describedBy, invalid }) => (
                    <TextInput
                        id={id}
                        inputMode="url"
                        aria-describedby={describedBy}
                        invalid={invalid}
                        {...form.register('captionUrl')}
                    />
                )}
            </Field>
        </FormSection>
    );
}

function ReadingBlock({ form }: { form: UseFormReturn<LessonFormValues> }) {
    return (
        <Field
            label={strings.lesson.fields.body}
            hint={strings.lesson.fields.bodyHint}
            error={form.formState.errors.body?.message}
        >
            {({ id, describedBy, invalid }) => (
                <Suspense fallback={<p className="text-xs text-fg-2">{strings.states.loading}</p>}>
                    <ArticleEditor
                        id={id}
                        label={strings.lesson.fields.body}
                        describedBy={describedBy}
                        invalid={invalid}
                        value={form.watch('body')}
                        onChange={(body) => form.setValue('body', body, { shouldDirty: true })}
                    />
                </Suspense>
            )}
        </Field>
    );
}

function ResourceBlock({ form }: { form: UseFormReturn<LessonFormValues> }) {
    const files = useFieldArray({ control: form.control, name: 'files' });
    const errors = form.formState.errors;
    const config = useInstructorConfig();
    const uploadUrl = config.urls.upload;

    /*
     * The upload answers the private storage path, and the path is what a
     * resource lesson stores: the API mints a signed URL per file when a
     * learner asks for it, and the raw path never leaves the server. So the
     * picker fills the three fields an author typed before, and nothing
     * about the stored payload changes.
     */
    const fill = (index: number, path: string, file: { name: string; size_bytes: number }) => {
        const options = { shouldDirty: true, shouldValidate: true } as const;
        form.setValue(`files.${index}.path` as const, path, options);
        form.setValue(`files.${index}.name` as const, file.name, options);
        form.setValue(`files.${index}.sizeBytes` as const, String(file.size_bytes), options);
    };

    return (
        <FormSection title={strings.lesson.fields.files} note={strings.lesson.fields.filesHint}>
            <ul className="grid gap-2.5">
                {files.fields.map((field, index) => (
                    <li
                        key={field.id}
                        className="grid gap-2 rounded border border-border p-2.5 sm:grid-cols-[1fr_1fr_110px_auto]"
                    >
                        <Field
                            label={strings.lesson.fields.filePath}
                            hideLabel
                            error={errors.files?.[index]?.path?.message}
                        >
                            {({ id, invalid }) => (
                                <TextInput
                                    id={id}
                                    aria-label={strings.lesson.fields.filePath}
                                    placeholder={strings.lesson.fields.filePath}
                                    invalid={invalid}
                                    {...form.register(`files.${index}.path` as const)}
                                />
                            )}
                        </Field>
                        <Field
                            label={strings.lesson.fields.fileName}
                            hideLabel
                            error={errors.files?.[index]?.name?.message}
                        >
                            {({ id, invalid }) => (
                                <TextInput
                                    id={id}
                                    aria-label={strings.lesson.fields.fileName}
                                    placeholder={strings.lesson.fields.fileName}
                                    invalid={invalid}
                                    {...form.register(`files.${index}.name` as const)}
                                />
                            )}
                        </Field>
                        <Field label={strings.lesson.fields.fileSize} hideLabel>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    inputMode="numeric"
                                    aria-label={strings.lesson.fields.fileSize}
                                    placeholder={strings.lesson.fields.fileSize}
                                    {...form.register(`files.${index}.sizeBytes` as const)}
                                />
                            )}
                        </Field>
                        <button
                            type="button"
                            aria-label={strings.lesson.fields.removeFile(String(index + 1))}
                            onClick={() => files.remove(index)}
                            className="flex h-[38px] w-8 cursor-pointer items-center justify-center self-start rounded border border-border bg-surface text-fg-2 hover:text-fg"
                        >
                            <Icon icon={X} size={14} />
                        </button>
                        {uploadUrl !== undefined && (
                            <FilePicker
                                className="sm:col-span-4"
                                endpoint={uploadUrl}
                                headers={config.api.headers}
                                label={strings.lesson.fields.uploadFile(index + 1)}
                                hint={strings.lesson.fields.uploadFileHint}
                                policy={policyFor(RESOURCE_POLICY, config.uploadPurposes)}
                                onUploaded={(path, uploaded) => fill(index, path, uploaded)}
                            />
                        )}
                    </li>
                ))}
            </ul>
            {arrayErrorMessage(errors.files) !== undefined && (
                <p className="text-[11.5px] font-medium text-danger">{arrayErrorMessage(errors.files)}</p>
            )}
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => files.append({ path: '', name: '', sizeBytes: '' })}
                >
                    <Icon icon={Plus} size={13} />
                    {strings.lesson.fields.addFile}
                </Button>
            </div>
        </FormSection>
    );
}

function AssignmentBlock({ form }: { form: UseFormReturn<LessonFormValues> }) {
    const errors = form.formState.errors;
    const criteria = useFieldArray({ control: form.control, name: 'rubric', keyName: 'fieldId' });
    return (
        <>
            <Field label={strings.lesson.fields.instructions} hint={strings.lesson.fields.instructionsHint}>
                {({ id, describedBy }) => (
                    <TextArea
                        id={id}
                        rows={5}
                        aria-describedby={describedBy}
                        {...form.register('instructions')}
                    />
                )}
            </Field>
            <FormSection title={strings.lesson.assignmentSection}>
                <div className="grid gap-3.5 sm:grid-cols-3">
                    <Field label={strings.lesson.fields.maxPoints} error={errors.maxPoints?.message}>
                        {({ id, invalid }) => (
                            <TextInput
                                id={id}
                                inputMode="numeric"
                                invalid={invalid}
                                {...form.register('maxPoints')}
                            />
                        )}
                    </Field>
                    <Field label={strings.lesson.fields.maxFiles} error={errors.maxFiles?.message}>
                        {({ id, invalid }) => (
                            <TextInput
                                id={id}
                                inputMode="numeric"
                                invalid={invalid}
                                {...form.register('maxFiles')}
                            />
                        )}
                    </Field>
                    <Field label={strings.lesson.fields.maxFileSize} error={errors.maxFileSizeKb?.message}>
                        {({ id, invalid }) => (
                            <TextInput
                                id={id}
                                inputMode="numeric"
                                invalid={invalid}
                                {...form.register('maxFileSizeKb')}
                            />
                        )}
                    </Field>
                </div>
                <Field
                    label={strings.lesson.fields.allowedTypes}
                    hint={strings.lesson.fields.allowedTypesHint}
                >
                    {({ id, describedBy }) => (
                        <TextInput
                            id={id}
                            aria-describedby={describedBy}
                            {...form.register('allowedTypes')}
                        />
                    )}
                </Field>
                <Field
                    label={strings.lesson.fields.dueAt}
                    hint={strings.lesson.fields.dueAtHint}
                    error={errors.dueAt?.message}
                >
                    {({ id, describedBy, invalid }) => (
                        <TextInput
                            id={id}
                            placeholder="2026-09-30 23:59:00"
                            aria-describedby={describedBy}
                            invalid={invalid}
                            {...form.register('dueAt')}
                        />
                    )}
                </Field>
                <CheckboxField
                    label={strings.lesson.fields.allowResubmission}
                    {...form.register('allowResubmission')}
                />
                <Field label={practical.pass} hint={practical.passHint} error={errors.passPercentage?.message}>
                    {({ id }) => <TextInput id={id} type="number" min={0} max={100} step="0.01" {...form.register('passPercentage')} />}
                </Field>
            </FormSection>
            <FormSection title={practical.rubric}>
                <p className="text-xs text-fg-2">{practical.rubricHint}</p>
                {criteria.fields.map((criterion, index) => <div key={criterion.fieldId} className="flex flex-wrap items-end gap-3 rounded border border-border p-3">
                    <Field label={practical.criterion} error={errors.rubric?.[index]?.label?.message}>{({ id }) => <TextInput id={id} {...form.register(`rubric.${index}.label`)} />}</Field>
                    <Field label={practical.maximum} error={errors.rubric?.[index]?.max_points?.message}>{({ id }) => <TextInput id={id} type="number" min={0.01} step="0.01" {...form.register(`rubric.${index}.max_points`, { valueAsNumber: true })} />}</Field>
                    <Button variant="ghost" onClick={() => criteria.remove(index)}>{practical.remove}</Button>
                </div>)}
                <Button variant="outline" onClick={() => criteria.append({ id: crypto.randomUUID(), label: '', max_points: 10 })}>{practical.addCriterion}</Button>
                <p className="text-xs">{practical.total}: {form.watch('rubric').reduce((sum, criterion) => sum + (Number(criterion.max_points) || 0), 0)} / {form.watch('maxPoints')}</p>
            </FormSection>
        </>
    );
}

function QuizBlock({
    courseId,
    form,
    lesson,
}: {
    courseId: number;
    form: UseFormReturn<LessonFormValues>;
    lesson: AuthoringLesson | null;
}) {
    const quizzesQuery = useCourseQuizzes(courseId);
    const quizzes = quizzesQuery.data ?? [];
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    /* A quiz may be attached to one lesson: offer the unlinked ones plus the
       one this lesson already holds. */
    const selectable = quizzes.filter((quiz) => quiz.lesson_id === null || quiz.lesson_id === lesson?.id);
    const matches = selectable.filter((quiz) => quiz.title.toLowerCase().includes(query.toLowerCase()));
    const selectedQuiz = selectable.find((quiz) => String(quiz.id) === form.watch('quizId'));
    const visible = matches.slice((page - 1) * 20, page * 20);
    if (selectedQuiz && !visible.some((quiz) => quiz.id === selectedQuiz.id)) visible.unshift(selectedQuiz);
    return (
        <FormSection title={strings.lesson.quizSection}>
            {selectable.length > 10 && (
                <Field label={studio.findQuiz}>
                    {({ id }) => (
                        <TextInput
                            id={id}
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setPage(1);
                            }}
                        />
                    )}
                </Field>
            )}
            <Field label={strings.lesson.fields.quizLink} hint={strings.lesson.quizCreated}>
                {({ id, describedBy }) => (
                    <Select id={id} aria-describedby={describedBy} {...form.register('quizId')}>
                        <option value="">{strings.lesson.fields.quizNew}</option>
                        {visible.map((quiz) => (
                            <option key={quiz.id} value={String(quiz.id)}>
                                {quiz.title}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <Pagination
                page={page}
                perPage={20}
                total={matches.length}
                onPageChange={setPage}
                label={studio.quizPages}
            />
        </FormSection>
    );
}
