import { translate as uiText } from '@/shared/i18n';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { FolderPen, Trash2 } from 'lucide-react';
import {
    Button,
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    Icon,
} from '@/shared/components';
import {
    useRemoveQuestionBankCategory,
    useUpdateQuestionBankCategory,
} from '@/instructor/assessment-api';
import type { QuestionCategory } from '@/instructor/lib/assessment-types';
import { useSaveTracker } from '@/instructor/lib/save-state';
import { BankField, BankInput, BankSelect } from './fields';

export interface FlatBankFolder {
    category: QuestionCategory;
    depth: number;
}

function descendantsOf(category: QuestionCategory): Set<number> {
    const descendants = new Set<number>();
    const visit = (children: readonly QuestionCategory[]) => {
        for (const child of children) {
            descendants.add(child.id);
            visit(child.children ?? []);
        }
    };
    visit(category.children ?? []);
    return descendants;
}

export function FolderManagementDialogs({
    bankId,
    folders,
    editing,
    deleting,
    onEditingChange,
    onDeletingChange,
    onDeleted,
}: {
    bankId: number;
    folders: readonly FlatBankFolder[];
    editing: QuestionCategory | null;
    deleting: QuestionCategory | null;
    onEditingChange: (folder: QuestionCategory | null) => void;
    onDeletingChange: (folder: QuestionCategory | null) => void;
    onDeleted: (folderId: number) => void;
}) {
    const update = useUpdateQuestionBankCategory();
    const remove = useRemoveQuestionBankCategory();
    const updateSave = useSaveTracker();
    const deleteSave = useSaveTracker();
    const resetUpdate = updateSave.reset;
    const resetDelete = deleteSave.reset;
    const [name, setName] = useState('');
    const [parentId, setParentId] = useState<number | null>(null);
    const handledUpdate = useRef<number | null>(null);
    const handledDelete = useRef<number | null>(null);
    const deletedId = useRef<number | null>(null);

    useEffect(() => {
        if (editing === null) {
            return;
        }
        resetUpdate();
        handledUpdate.current = null;
        setName(editing.name);
        setParentId(editing.parent_id);
    }, [editing, resetUpdate]);

    useEffect(() => {
        if (deleting === null) {
            return;
        }
        resetDelete();
        handledDelete.current = null;
        deletedId.current = deleting.id;
    }, [deleting, resetDelete]);

    useEffect(() => {
        if (updateSave.state !== 'saved' || updateSave.savedAt === null || handledUpdate.current === updateSave.savedAt) {
            return;
        }
        handledUpdate.current = updateSave.savedAt;
        onEditingChange(null);
    }, [onEditingChange, updateSave.savedAt, updateSave.state]);

    useEffect(() => {
        if (deleteSave.state !== 'saved' || deleteSave.savedAt === null || handledDelete.current === deleteSave.savedAt) {
            return;
        }
        handledDelete.current = deleteSave.savedAt;
        const id = deletedId.current;
        onDeletingChange(null);
        if (id !== null) {
            onDeleted(id);
        }
    }, [deleteSave.savedAt, deleteSave.state, onDeleted, onDeletingChange]);

    const excludedParents = useMemo(() => {
        if (editing === null) {
            return new Set<number>();
        }
        return new Set([editing.id, ...descendantsOf(editing)]);
    }, [editing]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing === null || name.trim() === '') {
            return;
        }
        void updateSave.run((idempotencyKey) => update.mutateAsync({
            bankId,
            categoryId: editing.id,
            body: { name: name.trim(), parent_id: parentId },
            idempotencyKey,
        }));
    };

    const confirmDelete = () => {
        if (deleting === null) {
            return;
        }
        void deleteSave.run((idempotencyKey) => remove.mutateAsync({
            bankId,
            categoryId: deleting.id,
            idempotencyKey,
        }));
    };

    return (
        <>
            <Dialog open={editing !== null} onOpenChange={(open) => { if (!open && updateSave.state !== 'saving') { onEditingChange(null); } }}>
                <DialogContent showClose={false}>
                    <DialogTitle>{uiText("vidyalms.ui.4a683d9ac9f3eb4b", "Edit ")}{editing?.name ?? uiText("vidyalms.ui.034a0062488256f1", "folder")}</DialogTitle>
                    <DialogDescription>{uiText("vidyalms.ui.7f020cc4e9204d9e", "Rename this folder or move it beneath another folder in this bank.")}</DialogDescription>
                    <form onSubmit={submit} className="mt-4 grid gap-3">
                        <BankField label={uiText("vidyalms.ui.14d34edf50ef6c87", "Folder name")}>
                            {({ id }) => <BankInput id={id} autoFocus value={name} onChange={(event) => setName(event.target.value)} />}
                        </BankField>
                        <BankField label={uiText("vidyalms.ui.158f5a01ef8cfb1e", "Parent folder")}>
                            {({ id }) => (
                                <BankSelect id={id} value={parentId ?? ''} onChange={(event) => setParentId(event.target.value === '' ? null : Number(event.target.value))}>
                                    <option value="">{uiText("vidyalms.ui.1f5bb88ff73b7a85", "Bank root")}</option>
                                    {folders.filter(({ category }) => !excludedParents.has(category.id)).map(({ category, depth }) => (
                                        <option key={category.id} value={category.id}>{uiText("vidyalms.ui.380e3dbd6730acee", "{p0}{p1}", { p0: '· '.repeat(depth), p1: category.name })}</option>
                                    ))}
                                </BankSelect>
                            )}
                        </BankField>
                        {updateSave.state === 'failed' && <ErrorState title={uiText("vidyalms.ui.88352a70073365a7", "Folder not updated.")} error={updateSave.error} onRetry={updateSave.retry} />}
                        <DialogFooter>
                            <DialogClose asChild><Button type="button" variant="ghost" size="sm" disabled={updateSave.state === 'saving'}>{uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel")}</Button></DialogClose>
                            <Button type="submit" size="sm" disabled={name.trim() === '' || updateSave.state === 'saving'}><Icon icon={FolderPen} size={14} />{uiText("vidyalms.ui.2d1bffbfb1035ebe", "Save folder")}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(open) => { if (!open && deleteSave.state !== 'saving') { onDeletingChange(null); } }}>
                <DialogContent role="alertdialog" showClose={false}>
                    <DialogTitle>{uiText("vidyalms.ui.85941fb9c992536d", "Delete ")}{deleting?.name ?? uiText("vidyalms.ui.034a0062488256f1", "folder")}?</DialogTitle>
                    <DialogDescription>{uiText("vidyalms.ui.e3e304da02043fea", "Only an empty folder can be deleted. Questions and child folders are never deleted by this action.")}</DialogDescription>
                    {deleteSave.state === 'failed' && <div className="mt-3"><ErrorState title={uiText("vidyalms.ui.a080d44a246ab98e", "Folder not deleted.")} error={deleteSave.error} onRetry={deleteSave.retry} /></div>}
                    <DialogFooter>
                        <DialogClose asChild><Button type="button" variant="ghost" size="sm" disabled={deleteSave.state === 'saving'}>{uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel")}</Button></DialogClose>
                        <Button type="button" variant="destructive" size="sm" disabled={deleteSave.state === 'saving'} onClick={confirmDelete}><Icon icon={Trash2} size={14} />{uiText("vidyalms.ui.f3235a4ea9be028b", "Delete empty folder")}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
