import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import type { AuthoringModule } from '@vidyalms/api-sdk';
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
import { useAddModule, useUpdateModule } from '../api';
import { instructorStrings as strings } from '../strings';
import { useSaveTracker } from '../lib/save-state';
import { Field, Select, TextArea, TextInput } from '../components/field';
import { SubjectRestrictions } from './availability/subject-restrictions';

/*
 * Add and edit a module in one dialog: the same fields either way, and the
 * write differs only in route. Radix owns the focus trap, Escape and the
 * restore; the form is react-hook-form with a zod resolver, and the server
 * revalidates everything anyway.
 */

const schema = z.object({
    title: z.string().trim().min(1, strings.form.required),
    summary: z.string(),
    state: z.enum(['published', 'draft']),
});

type Values = z.infer<typeof schema>;

export function ModuleDialog({
    courseId,
    module,
    onClose,
}: {
    courseId: number;
    module: AuthoringModule | null;
    onClose: () => void;
}) {
    const addModule = useAddModule();
    const updateModule = useUpdateModule();
    const save = useSaveTracker();
    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            title: module?.title ?? '',
            summary: module?.summary ?? '',
            /* The two states an author sets here; an archived module is
               restored from the curriculum list, never from this form. */
            state: module?.state === 'draft' ? 'draft' : 'published',
        },
    });

    const onSubmit = handleSubmit((values) => {
        const summary = values.summary.trim();
        const body = {
            title: values.title.trim(),
            summary: summary === '' ? null : summary,
            state: values.state,
        };
        void save
            .run((idempotencyKey) =>
                module === null
                    ? addModule.mutateAsync({ courseId, body, idempotencyKey })
                    : updateModule.mutateAsync({ courseId, moduleId: module.id, body, idempotencyKey }),
            )
            .then((ok) => {
                if (ok) {
                    onClose();
                }
            });
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className={module === null ? 'max-w-lg' : 'max-w-2xl'}>
                <DialogTitle>{module === null ? strings.module.addTitle : strings.module.editTitle}</DialogTitle>
                <DialogDescription>{strings.module.description}</DialogDescription>
                <form onSubmit={onSubmit} className="mt-3.5 grid gap-3.5">
                    <Field label={strings.module.fields.title} error={errors.title?.message}>
                        {({ id, describedBy, invalid }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                autoFocus
                                {...register('title')}
                            />
                        )}
                    </Field>
                    <Field
                        label={strings.module.fields.summary}
                        hint={strings.module.fields.summaryHint}
                        error={errors.summary?.message}
                    >
                        {({ id, describedBy, invalid }) => (
                            <TextArea
                                id={id}
                                rows={2}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                {...register('summary')}
                            />
                        )}
                    </Field>
                    <Field label={strings.module.fields.state} error={errors.state?.message}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} invalid={invalid} {...register('state')}>
                                <option value="published">{strings.module.fields.statePublished}</option>
                                <option value="draft">{strings.module.fields.stateDraft}</option>
                            </Select>
                        )}
                    </Field>
                    {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
                    {/* Only on a module that exists: a restriction hangs on a
                        row, and there is no row to hang it on until the
                        module has been created. */}
                    {module !== null && (
                        <SubjectRestrictions subjectType="module" subjectId={module.id} courseId={courseId} />
                    )}
                    <DialogFooter className="items-center">
                        <SaveIndicator className="mr-auto" state={save.state} savedAt={save.savedAt} />
                        <Button type="button" variant="outline" size="sm" onClick={onClose}>
                            {strings.form.cancel}
                        </Button>
                        <Button type="submit" size="sm" disabled={save.state === 'saving'}>
                            {module === null ? strings.module.add : strings.module.save}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
