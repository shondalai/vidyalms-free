import { useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import type { UserAccount } from '@vidyalms/api-sdk';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
} from '@/shared/components';
import { useGrantAccess } from '../api';
import { endOfDayUtc, todayIsoDate } from '../lib';
import { adminStrings } from '../strings';
import { RadioGroup, TextField } from './field';
import { UserPicker } from './user-picker';
import { EnrolmentCoursePicker, type CourseSelection } from '../learners/course-picker';

/*
 * "+ Grant access" of artboard 2b. One person, one course, one optional
 * expiry: the contract creates the manual entitlement and the enrolment
 * follows automatically. A start date is not asked for here, because
 * provisioning for later is a different intent from granting access now.
 */

const strings = adminStrings.grant;

const schema = z
    .object({
        user_id: z.number().min(1, strings.learnerRequired),
        course_id: z.string().min(1, strings.courseRequired),
        expiry_mode: z.enum(['never', 'date']),
        expires_on: z.string(),
    })
    .superRefine((values, ctx) => {
        if (values.expiry_mode === 'date' && values.expires_on.trim() === '') {
            ctx.addIssue({ code: 'custom', path: ['expires_on'], message: strings.expiryDateRequired });
        }
    });

type FormValues = z.input<typeof schema>;

export interface GrantAccessDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Reports what happened so the screen can state it in its own words. */
    onGranted: (message: string) => void;
    learner?: UserAccount;
}

export function GrantAccessDialog({ open, onOpenChange, onGranted, learner }: GrantAccessDialogProps) {
    const [user, setUser] = useState<UserAccount | null>(learner ?? null);
    const [course, setCourse] = useState<CourseSelection | null>(null);
    const replay = useRef<{ body: string; key: string } | null>(null);
    const grant = useGrantAccess();
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { user_id: learner?.id ?? 0, course_id: '', expiry_mode: 'never', expires_on: '' },
    });
    const expiryMode = form.watch('expiry_mode');

    const close = (next: boolean) => {
        if (!next) {
            grant.reset();
            form.reset();
            setUser(learner ?? null);
            setCourse(null);
            replay.current = null;
        }
        onOpenChange(next);
    };

    const submit = form.handleSubmit(async (values) => {
        const courseId = Number(values.course_id);
        const body = {
            user_id: values.user_id,
            expires_at: values.expiry_mode === 'date' ? endOfDayUtc(values.expires_on) : null,
        };
        const fingerprint = JSON.stringify({ courseId, body });
        if (replay.current?.body !== fingerprint)
            replay.current = { body: fingerprint, key: crypto.randomUUID() };

        // react-hook-form rethrows whatever the submit handler throws, and the
        // form is submitted with void, so a refused write would leave the page
        // an unhandled rejection. The banner renders it from the mutation.
        let result;

        try {
            result = await grant.mutateAsync({
                courseId,
                body,
                idempotencyKey: replay.current.key,
            });
        } catch {
            return;
        }

        const learnerName = user?.name ?? '';
        onGranted(
            result.enrolment === null
                ? strings.provisioned(learnerName)
                : strings.done(learnerName, course?.title ?? ''),
        );
        close(false);
    });

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <DialogTitle>{strings.title}</DialogTitle>
                <DialogDescription>{strings.description}</DialogDescription>
                <form
                    className="mt-3.5 grid gap-3"
                    onSubmit={(event) => {
                        void submit(event);
                    }}
                >
                    {learner ? (
                        <div className="vy-learner-selected-course">
                            <span>
                                <strong>{learner.name}</strong>
                                <small>{learner.email}</small>
                            </span>
                        </div>
                    ) : (
                        <UserPicker
                            label={strings.learnerLabel}
                            placeholder={strings.learnerPlaceholder}
                            hint={strings.learnerHint}
                            error={form.formState.errors.user_id?.message}
                            value={user}
                            onChange={(next) => {
                                setUser(next);
                                form.setValue('user_id', next?.id ?? 0, {
                                    shouldValidate: form.formState.isSubmitted,
                                });
                            }}
                        />
                    )}
                    <EnrolmentCoursePicker
                        value={course}
                        onChange={(next) => {
                            setCourse(next);
                            form.setValue('course_id', next ? String(next.id) : '', {
                                shouldValidate: form.formState.isSubmitted,
                            });
                        }}
                        error={form.formState.errors.course_id?.message}
                    />
                    <RadioGroup
                        legend={strings.expiryLabel}
                        name="grant-expiry"
                        value={expiryMode}
                        options={[
                            { value: 'never', label: strings.expiryNever },
                            { value: 'date', label: strings.expiryOn },
                        ]}
                        onChange={(next) => form.setValue('expiry_mode', next === 'date' ? 'date' : 'never')}
                    />
                    {expiryMode === 'date' && (
                        <TextField
                            type="date"
                            min={todayIsoDate()}
                            label={strings.expiryDateLabel}
                            error={form.formState.errors.expires_on?.message}
                            {...form.register('expires_on')}
                        />
                    )}
                    {grant.isError && <ErrorState error={grant.error} />}
                    <DialogFooter>
                        <Button variant="outline" size="sm" onClick={() => close(false)}>
                            {strings.cancel}
                        </Button>
                        <Button type="submit" size="sm" disabled={grant.isPending}>
                            {grant.isPending ? strings.submitting : strings.submit}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
