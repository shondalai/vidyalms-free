import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Archive, LibraryBig, Save } from 'lucide-react';
import {
    Button,
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
    SaveIndicator,
} from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import {
    useArchiveQuestionBank,
    useCreateQuestionBank,
    useQuestionBank,
    useUpdateQuestionBank,
    type QuestionBankState,
} from '@/instructor/assessment-api';
import type { OwnerScope } from '@/instructor/lib/assessment-types';
import { useSaveTracker } from '@/instructor/lib/save-state';
import { PageHeader } from '../components/page-header';
import { ScreenError } from '../components/states';
import { BankField, BankInput, BankSelect, BankTextArea } from './fields';
import { OwnerPicker } from './owner-picker';
import { bankStrings as strings } from './strings';
import { BankNavigation } from './workspace';

export function QuestionBankForm({ bankId, navigate }: { bankId: number | null; navigate: (hash: string) => void }) {
    const editing = bankId !== null;
    const bank = useQuestionBank(bankId);
    const create = useCreateQuestionBank();
    const update = useUpdateQuestionBank();
    const archive = useArchiveQuestionBank();
    const save = useSaveTracker();
    const announce = useAnnounce();
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [ownerScope, setOwnerScope] = useState<OwnerScope>('site');
    const [ownerId, setOwnerId] = useState<number | null>(null);
    const [ownerLabel, setOwnerLabel] = useState('');
    const [state, setState] = useState<QuestionBankState>('active');
    const [nameError, setNameError] = useState<string | undefined>();
    const [archiveOpen, setArchiveOpen] = useState(false);
    const pendingAction = useRef<'form' | 'archive' | null>(null);
    const createdId = useRef<number | null>(null);

    useEffect(() => {
        if (bank.data === undefined) {
            return;
        }
        setName(bank.data.name);
        setDescription(bank.data.description ?? '');
        setOwnerScope(bank.data.owner_scope);
        setOwnerId(bank.data.owner_scope === 'site' ? null : bank.data.owner_id);
        setOwnerLabel(bank.data.owner_scope === 'site' ? strings.form.site : '');
        setState(bank.data.state);
    }, [bank.data]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const trimmed = name.trim();
        if (trimmed === '') {
            setNameError(strings.form.nameRequired);
            return;
        }
        setNameError(undefined);
        // The wire contract represents the site owner explicitly as zero.
        // Keeping that normalization here means persisted banks always round-trip
        // with the same non-null owner id returned by the API.
        const numericOwner = ownerScope === 'site' ? 0 : ownerId;
        if (!editing && ownerScope !== 'site' && (numericOwner === null || !Number.isSafeInteger(numericOwner) || numericOwner < 1)) {
            return;
        }

        pendingAction.current = 'form';
        void save.run(async (idempotencyKey) => {
            const saved = editing
                ? await update.mutateAsync({
                    bankId,
                    body: { name: trimmed, description: description.trim() || null, state },
                    idempotencyKey,
                })
                : await create.mutateAsync({
                    body: {
                        name: trimmed,
                        description: description.trim() || null,
                        owner_scope: ownerScope,
                        owner_id: numericOwner,
                    },
                    idempotencyKey,
                });
            if (!editing) {
                createdId.current = saved.id;
            }
            return saved;
        });
    };

    const archiveBank = () => {
        if (bankId === null) {
            return;
        }
        pendingAction.current = 'archive';
        void save.run((idempotencyKey) => archive.mutateAsync({ bankId, idempotencyKey }));
    };

    useEffect(() => {
        if (save.state !== 'saved' || pendingAction.current === null) {
            return;
        }
        const action = pendingAction.current;
        pendingAction.current = null;
        if (action === 'archive') {
            navigate('/');
            return;
        }
        announce(editing ? 'Question bank saved.' : 'Question bank created.');
        if (!editing && createdId.current !== null) {
            navigate(`/banks/${createdId.current}/questions`);
        }
    }, [announce, editing, navigate, save.state]);

    if (editing && bank.isPending) {
        return <p role="status" className="p-4 text-sm text-fg-2">{strings.state.loading}</p>;
    }
    if (editing && bank.isError) {
        return <ScreenError error={bank.error} onRetry={() => void bank.refetch()} />;
    }

    return (
        <div className="vy-bank-workspace">
            <PageHeader
                className="vy-bank-header"
                eyebrow={strings.eyebrow}
                title={editing ? strings.form.editTitle : strings.form.createTitle}
                description={editing ? strings.form.editDescription : strings.form.createDescription}
                actions={
                    <Button variant="outline" size="sm" onClick={() => navigate(editing ? `/banks/${bankId}/questions` : '/') }>
                        {strings.form.cancel}
                    </Button>
                }
            />
            {editing && <BankNavigation bankId={bankId} active="settings" navigate={navigate} />}
            <div className="vy-bank-form-layout">
            <form onSubmit={submit} className="vy-bank-form">
                <div><h2>{strings.workspace.formTitle}</h2><p>{strings.workspace.formHint}</p></div>
                <BankField label={strings.form.name} error={nameError}>
                    {({ id, describedBy, invalid }) => (
                        <BankInput id={id} autoFocus value={name} aria-describedby={describedBy} invalid={invalid} onChange={(event) => setName(event.target.value)} />
                    )}
                </BankField>
                <BankField label={strings.form.description} hint={strings.form.descriptionHint}>
                    {({ id, describedBy }) => (
                        <BankTextArea id={id} value={description} aria-describedby={describedBy} onChange={(event) => setDescription(event.target.value)} />
                    )}
                </BankField>
                <div className="grid gap-4 sm:grid-cols-2">
                    <BankField label={strings.form.ownerScope}>
                        {({ id }) => (
                            <BankSelect
                                id={id}
                                value={ownerScope}
                                disabled={editing}
                                onChange={(event) => {
                                    setOwnerScope(event.target.value as OwnerScope);
                                    setOwnerId(null);
                                    setOwnerLabel('');
                                }}
                            >
                                <option value="site">{strings.form.site}</option>
                                <option value="course">{strings.form.course}</option>
                                <option value="category">{strings.form.category}</option>
                                {editing && ownerScope === 'quiz' && <option value="quiz">{strings.form.quiz}</option>}
                            </BankSelect>
                        )}
                    </BankField>
                    {(ownerScope === 'course' || ownerScope === 'category') && (
                        <BankField label={ownerScope === 'course' ? strings.form.course : strings.form.category}>
                            {() => (
                                <OwnerPicker
                                    kind={ownerScope === 'course' ? 'course' : 'category'}
                                    value={ownerId}
                                    label={ownerLabel}
                                    disabled={editing}
                                    onChange={(id, label) => {
                                        setOwnerId(id);
                                        setOwnerLabel(label);
                                    }}
                                />
                            )}
                        </BankField>
                    )}
                    {ownerScope === 'quiz' && (
                        <div className="grid gap-1.5">
                            <span className="font-display text-xs font-semibold text-fg">{strings.form.quiz}</span>
                            <p className="rounded border border-border bg-bg px-3 py-2.5 text-[13px] text-fg">{ownerLabel}</p>
                        </div>
                    )}
                    {editing && (
                        <BankField label={strings.form.state}>
                            {({ id }) => (
                                <BankSelect id={id} value={state} onChange={(event) => setState(event.target.value as QuestionBankState)}>
                                    <option value="active">{strings.list.active}</option>
                                    <option value="archived">{strings.list.archived}</option>
                                    {state === 'trashed' && <option value="trashed">{strings.list.trashed}</option>}
                                </BankSelect>
                            )}
                        </BankField>
                    )}
                </div>
                {save.state === 'failed' && <p role="alert" className="text-sm text-danger">{strings.form.failed}</p>}
                <div className="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                    {editing && state !== 'archived' && (
                        <Dialog open={archiveOpen} onOpenChange={(open) => { if (save.state !== 'saving') { setArchiveOpen(open); } }}>
                            <DialogTrigger asChild>
                                <Button type="button" variant="ghost" size="sm" className="mr-auto text-danger" disabled={save.state === 'saving'}>
                                    <Archive size={15} aria-hidden="true" />
                                    {strings.form.archive}
                                </Button>
                            </DialogTrigger>
                            <DialogContent role="alertdialog" showClose={false}>
                                <DialogTitle>{strings.form.archiveTitle(name)}</DialogTitle>
                                <DialogDescription>{strings.form.archiveDescription}</DialogDescription>
                                {save.state === 'failed' && pendingAction.current === 'archive' && (
                                    <p role="alert" className="mt-3 text-xs text-danger">{strings.form.failed}</p>
                                )}
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="ghost" size="sm" disabled={save.state === 'saving'}>{strings.form.cancel}</Button>
                                    </DialogClose>
                                    <Button type="button" variant="destructive" size="sm" disabled={save.state === 'saving'} onClick={archiveBank}>
                                        <Archive size={15} aria-hidden="true" />
                                        {strings.form.confirmArchive}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    )}
                    <SaveIndicator className={!editing || state === 'archived' ? 'ml-auto' : undefined} state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
                    <Button type="submit" size="sm" disabled={save.state === 'saving'}>
                        <Save size={15} aria-hidden="true" />
                        {editing ? strings.form.save : strings.form.create}
                    </Button>
                </div>
            </form>
            <aside className="vy-bank-form-aside"><LibraryBig size={27} className="text-accent" aria-hidden="true" /><h2>{strings.workspace.ownership}</h2><p>{strings.workspace.ownershipHint}</p><p className="mt-4">{strings.workspace.ownershipFixed}</p></aside>
            </div>
        </div>
    );
}
