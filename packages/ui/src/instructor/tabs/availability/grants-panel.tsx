import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Trash2 } from 'lucide-react';
import type { UserAccount } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    Card,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    Icon,
    Skeleton,
} from '@/shared/components';
import { useAvailabilityGrants, useCreateAvailabilityGrant, useRevokeAvailabilityGrant } from '@/shared/flow';
import { flowStrings } from '../../flow-strings';
import { Field, Select, TextInput } from '../../components/field';
import { LearnerPicker } from '../../components/learner-picker';
import type { AvailabilityGrant, AvailabilitySubjectType } from '../../lib/flow-types';
import type { ConditionTarget } from '@/condition-editors';

/*
 * Individual exceptions.
 *
 * Access is evaluated on read from current facts, so a regrade downward
 * takes access back. That is correct and surprising, and the remedy is an
 * explicit auditable exception rather than latching a met condition, which
 * is the most instructive bug in the survey behind this design: latch and a
 * learner regraded from 72 down to 55 keeps access forever, with no record
 * of why.
 *
 * Two facts are stated on the screen and not left to be discovered. The
 * order of precedence is printed, in the same words support uses. And a
 * closure with no expiry EXCUSES the learner from the item, which is what an
 * administrator means by it and what the completion total then does; a
 * closure that should not excuse them is one with a date on it.
 */

const strings = flowStrings.grants;

function subjectLabel(grant: AvailabilityGrant, targets: readonly ConditionTarget[]): string {
    const match = targets.find((entry) => entry.kind === grant.subject_type && entry.id === grant.subject_id);
    return match?.title ?? `${grant.subject_type} ${grant.subject_id}`;
}

function AddGrantDialog({
    courseId,
    targets,
    onClose,
}: {
    courseId: number;
    targets: readonly ConditionTarget[];
    onClose: () => void;
}) {
    const create = useCreateAvailabilityGrant();
    const [learner, setLearner] = useState<UserAccount | null>(null);
    const [subject, setSubject] = useState(() => (targets[0] === undefined ? '' : `${targets[0].kind}:${targets[0].id}`));
    const [mode, setMode] = useState<'grant' | 'deny'>('grant');
    const [reason, setReason] = useState('');
    const [expires, setExpires] = useState('');
    const [needsLearner, setNeedsLearner] = useState(false);

    const submit = () => {
        if (learner === null || subject === '') {
            setNeedsLearner(learner === null);
            return;
        }
        const [kind, id] = subject.split(':');
        create.mutate(
            {
                courseId,
                body: {
                    scope_type: 'course',
                    scope_id: courseId,
                    subject_type: (kind ?? 'lesson') as AvailabilitySubjectType,
                    subject_id: Number(id ?? 0),
                    user_id: learner.id,
                    mode,
                    reason: reason.trim() === '' ? null : reason.trim(),
                    expires_at: expires.trim() === '' ? null : expires.trim(),
                },
            },
            { onSuccess: onClose },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogTitle>{strings.add}</DialogTitle>
                <DialogDescription>{strings.intro}</DialogDescription>
                <div className="mt-3.5 grid gap-3.5">
                    <LearnerPicker
                        label={strings.learner}
                        value={learner}
                        onChange={setLearner}
                        error={needsLearner ? flowStrings.preview.pickLearner : undefined}
                        autoFocus
                    />
                    <Field label={strings.subject}>
                        {({ id }) => (
                            <Select id={id} value={subject} onChange={(event) => setSubject(event.target.value)}>
                                {targets.map((entry) => (
                                    <option key={`${entry.kind}:${entry.id}`} value={`${entry.kind}:${entry.id}`}>
                                        {entry.title}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    <Field label={strings.mode}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={mode}
                                onChange={(event) => setMode(event.target.value as 'grant' | 'deny')}
                            >
                                <option value="grant">{strings.modes.grant}</option>
                                <option value="deny">{strings.modes.deny}</option>
                            </Select>
                        )}
                    </Field>
                    <Field label={strings.expires} hint={strings.expiresHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                                value={expires}
                                onChange={(event) => setExpires(event.target.value)}
                            />
                        )}
                    </Field>
                    {mode === 'deny' && expires.trim() === '' && (
                        <p
                            role="status"
                            className="rounded border border-border border-l-[3px] border-l-warn bg-bg px-2.5 py-2 text-[11.5px] text-fg-2"
                        >
                            {strings.denyForever}
                        </p>
                    )}
                    <Field label={strings.reason}>
                        {({ id }) => (
                            <TextInput id={id} value={reason} onChange={(event) => setReason(event.target.value)} />
                        )}
                    </Field>
                    {create.isError && <ErrorState error={create.error} />}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {flowStrings.common.cancel}
                    </Button>
                    <Button onClick={submit} disabled={create.isPending}>
                        {strings.save}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function GrantsPanel({
    courseId,
    targets,
}: {
    courseId: number;
    targets: readonly ConditionTarget[];
}) {
    const grants = useAvailabilityGrants(courseId);
    const revoke = useRevokeAvailabilityGrant();
    const [adding, setAdding] = useState(false);

    if (grants.isPending) {
        return <Skeleton className="h-24 w-full" />;
    }
    if (grants.isError) {
        return (
            <ErrorState
                error={grants.error}
                title={flowStrings.common.loadError}
                onRetry={() => void grants.refetch()}
            />
        );
    }

    const page = grants.data;
    const order = (page.scope_order ?? ['user', 'cohort', 'course', 'site']).join(', ');

    return (
        <Card>
            <div className="flex flex-wrap items-start gap-2 border-b border-border p-4">
                <div className="min-w-0 flex-1">
                    <h3 className="font-display text-sm font-bold text-fg">{strings.title}</h3>
                    <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.intro}</p>
                    <p className="mt-1 text-[11.5px] text-fg-2">{strings.scopeOrder(order)}</p>
                </div>
                <Button size="sm" onClick={() => setAdding(true)}>
                    {strings.add}
                </Button>
            </div>
            {page.items.length === 0 ? (
                <p className="p-4 text-[12.5px] text-fg-2">{strings.none}</p>
            ) : (
                <ul>
                    {page.items.map((grant) => (
                        <li
                            key={grant.id}
                            className="flex flex-wrap items-center gap-2 border-b border-border/60 px-4 py-2.5 text-[12.5px] last:border-b-0"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium text-fg">
                                    {grant.learner?.name ?? `#${grant.user_id}`}
                                </span>
                                <span className="block text-[11.5px] text-fg-2">
                                    {subjectLabel(grant, targets)}
                                    {grant.reason !== null && uiText("vidyalms.ui.04420b9dfe3f0a87", " · {p0}", { p0: grant.reason })}
                                </span>
                            </span>
                            <Badge variant={grant.mode === 'grant' ? 'ok' : 'warn'}>
                                {strings.modes[grant.mode]}
                            </Badge>
                            <Badge variant={grant.active ? 'neutral' : 'neutral'}>
                                {grant.active ? strings.active : strings.expired}
                            </Badge>
                            {grant.excludes_from_completion === true && (
                                <Badge variant="warn">{strings.excludes}</Badge>
                            )}
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={revoke.isPending}
                                onClick={() => revoke.mutate({ grantId: grant.id })}
                            >
                                <Icon icon={Trash2} size={13} />
                                {strings.revoke}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {adding && <AddGrantDialog courseId={courseId} targets={targets} onClose={() => setAdding(false)} />}
        </Card>
    );
}
