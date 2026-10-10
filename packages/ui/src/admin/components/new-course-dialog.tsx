import { useRef } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import type { ManagedCourseDetail } from '@vidyalms/api-sdk';
import {
    Button,
    CourseCategoryPicker,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    FilePicker,
} from '@/shared/components';
import { IMAGE_POLICY, policyFor } from '@/shared/upload';
import { useCreateCourse } from '../api';
import { newIdempotencyKey } from '@/shared/save-state';
import { useAdminConfig } from '../config';
import { adminStrings } from '../strings';
import { SelectField, TextField } from './field';

/*
 * "+ New course" of artboard 2a. The contract creates a draft and makes the
 * caller its lead instructor; everything else about a course is edited in
 * the workbench, so this dialog asks for a title, native category, completion
 * rule and catalogue image, and stops there.
 *
 * The image is typed or picked. The picker appears only where the host gave
 * an upload endpoint, so an older host keeps the typed address it always
 * had.
 */

const strings = adminStrings.courses.dialog;

const schema = z
    .object({
        title: z.string().trim().min(1, strings.titleRequired),
        categoryId: z.number().int().positive().nullable(),
        image: z.string(),
        completion_rule: z.enum(['all_lessons', 'percentage', 'pass_final']),
        threshold: z.string(),
    })
    .superRefine((values, ctx) => {
        if (values.completion_rule !== 'percentage') {
            return;
        }
        const parsed = Number(values.threshold);
        if (values.threshold.trim() === '' || !Number.isFinite(parsed) || parsed < 1 || parsed > 100) {
            ctx.addIssue({ code: 'custom', path: ['threshold'], message: strings.thresholdRange });
        }
    });

type FormValues = z.input<typeof schema>;

export interface NewCourseDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (course: ManagedCourseDetail) => void;
}

export function NewCourseDialog({ open, onOpenChange, onCreated }: NewCourseDialogProps) {
    const create = useCreateCourse();
    const config = useAdminConfig();
    /*
     * Creating a course is not replay-safe on its own, and the SDK mints a
     * fresh Idempotency-Key per attempt, so a second submit of the same form
     * used to make a second course. The tracker holds one key for one logical
     * write and hands the same one back to a retry.
     */
    const keyRef = useRef<string | null>(null);
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            title: '',
            categoryId: null,
            image: '',
            completion_rule: 'all_lessons',
            threshold: '80',
        },
    });
    const rule = form.watch('completion_rule');
    const categoryId = form.watch('categoryId');

    const submit = form.handleSubmit(async (values) => {
        // react-hook-form rethrows whatever the submit handler throws, and the
        // form is submitted with void, so a refused write would leave the page
        // an unhandled rejection. The banner renders it from the mutation.
        let course;
        const image = values.image.trim();
        keyRef.current ??= newIdempotencyKey();

        try {
            course = await create.mutateAsync({
                idempotencyKey: keyRef.current,
                body: {
                    title: values.title.trim(),
                    category_id: values.categoryId,
                    image: image === '' ? null : image,
                    completion_rule: values.completion_rule,
                    completion_threshold: values.completion_rule === 'percentage' ? Number(values.threshold) : null,
                },
            });
        } catch {
            // The key is kept: the next submit of this same form is the same
            // logical write, and replaying it is the whole point.
            return;
        }

        keyRef.current = null;
        form.reset();
        onCreated(course);
        onOpenChange(false);
    });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    // Closing abandons the write, so the next one is a new
                    // logical write and takes a new key.
                    keyRef.current = null;
                    create.reset();
                    form.reset();
                }
                onOpenChange(next);
            }}
        >
            <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto">
                <DialogTitle>{strings.title}</DialogTitle>
                <DialogDescription>{strings.description}</DialogDescription>
                <form
                    className="mt-3.5 grid gap-3"
                    onSubmit={(event) => {
                        void submit(event);
                    }}
                >
                    <TextField
                        label={strings.titleLabel}
                        autoFocus
                        error={form.formState.errors.title?.message}
                        {...form.register('title')}
                    />
                    <CourseCategoryPicker
                        label={strings.categoryLabel}
                        hint={strings.categoryHint}
                        value={categoryId}
                        onChange={(value) => form.setValue('categoryId', value, { shouldDirty: true })}
                        disabled={create.isPending}
                    />
                    <SelectField label={strings.ruleLabel} {...form.register('completion_rule')}>
                        <option value="all_lessons">{strings.ruleOptions.all_lessons}</option>
                        <option value="percentage">{strings.ruleOptions.percentage}</option>
                        <option value="pass_final">{strings.ruleOptions.pass_final}</option>
                    </SelectField>
                    {rule === 'percentage' && (
                        <TextField
                            type="number"
                            min={1}
                            max={100}
                            label={strings.thresholdLabel}
                            error={form.formState.errors.threshold?.message}
                            {...form.register('threshold')}
                        />
                    )}
                    <TextField
                        label={strings.imageLabel}
                        hint={strings.imageHint}
                        inputMode="url"
                        {...form.register('image')}
                    />
                    {config.urls.upload !== undefined && (
                        <FilePicker
                            endpoint={config.urls.upload}
                            headers={config.api.headers}
                            label={strings.imageUpload}
                            hint={strings.imageUploadHint}
                            policy={policyFor(IMAGE_POLICY, config.uploadPurposes)}
                            requires="url"
                            onUploaded={(url) => form.setValue('image', url, { shouldDirty: true })}
                        />
                    )}
                    {create.isError && <ErrorState error={create.error} />}
                    <DialogFooter>
                        <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
                            {strings.cancel}
                        </Button>
                        <Button type="submit" size="sm" disabled={create.isPending}>
                            {create.isPending ? strings.submitting : strings.submit}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
