import { translate as uiText } from '@/shared/i18n';
import { useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Eye, EyeOff, RotateCcw, Trash2 } from 'lucide-react';
import { Button, Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle, Icon } from '@/shared/components';
import { useApiClient } from '@/shared/context';
import { idempotency } from '@/shared/manage';

type Action = 'publish' | 'unpublish' | 'delete' | 'restore';
type Kind = 'course' | 'bank';
const labels = { publish: 'Publish', unpublish: 'Unpublish', delete: 'Delete', restore: 'Restore' };
const icons = { publish: Eye, unpublish: EyeOff, delete: Trash2, restore: RotateCcw };
const descriptions = {
    course: {
        publish: 'Make this course available to learners. The course must contain published learning content.',
        unpublish: 'Return this course to Draft and remove it from the catalogue. Curriculum, enrolments and learning records are retained.',
        delete: 'Move this course to Trash and remove learner access. Curriculum, enrolments, grades and certificates are retained. You can restore it as a draft from the Trash filter.',
        restore: 'Return this course to Draft. Review it before publishing it for learners again.',
    },
    bank: {
        publish: 'Make this question bank available in authoring searches and automatic question pools.',
        unpublish: 'Remove this bank from authoring searches and automatic question pools. Review quizzes that draw from it. Existing fixed placements and submitted attempts retain their questions.',
        delete: 'Move this bank to Trash. It will leave authoring searches and automatic question pools. Its questions, existing fixed quiz placements and assessment evidence are retained. You can restore it from the Trash filter.',
        restore: 'Restore this question bank as Unpublished. Review it before publishing it again.',
    },
};

export function ResourceLifecycleActions({ kind, id, name, state, onChanged }: {
    kind: Kind; id: number; name: string; state: string; onChanged: (message: string) => void;
}) {
    const client = useApiClient();
    const cache = useQueryClient();
    const trigger = useRef<HTMLButtonElement | null>(null);
    const [pending, setPending] = useState<{ action: Action; key: string } | null>(null);
    const mutation = useMutation({
        mutationFn: async ({ action, key }: { action: Action; key: string }) => {
            const headers = idempotency(key);
            if (kind === 'course') {
                const params = { path: { id } };
                if (action === 'delete') await client.DELETE('/manage/courses/{id}', { params, headers });
                else if (action === 'unpublish') await client.POST('/manage/courses/{id}/unpublish', { params, headers });
                else if (action === 'restore') await client.POST('/manage/courses/{id}/restore', { params, headers });
                else await client.POST('/manage/courses/{id}/publish', { params, headers });
            } else {
                const params = { path: { bank_id: id } };
                if (action === 'delete') await client.DELETE('/manage/banks/{bank_id}', { params, headers });
                else await client.PATCH('/manage/banks/{bank_id}', {
                    params, headers, body: { state: action === 'publish' ? 'active' : 'archived' },
                });
            }
        },
        onSuccess: (_data, { action }) => {
            setPending(null);
            onChanged(action === 'delete' ? `${name} moved to Trash.` : `${name} ${action === 'restore' ? 'restored' : action === 'unpublish' ? 'unpublished' : 'published'}.`);
            void cache.invalidateQueries({ queryKey: ['vidyalms'] });
        },
    });
    const actions: Action[] = state === 'trashed' ? ['restore'] : [state === 'published' || state === 'active' ? 'unpublish' : 'publish', 'delete'];
    return <>
        {actions.map((action) => <Button key={action} variant="ghost" size="sm" title={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: labels[action], p1: name })}
            aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: labels[action], p1: name })} className={action === 'delete' ? 'text-danger' : undefined}
            onClick={(event) => {
                trigger.current = event.currentTarget;
                mutation.reset();
                setPending({ action, key: crypto.randomUUID() });
            }}>
            <Icon icon={icons[action]} size={16} />
        </Button>)}
        <Dialog open={pending !== null} onOpenChange={(open) => { if (!open && !mutation.isPending) setPending(null); }}>
            <DialogContent role="alertdialog" showClose={!mutation.isPending} onCloseAutoFocus={(event) => {
                event.preventDefault();
                trigger.current?.focus();
            }}>
                <DialogTitle>{pending && uiText("vidyalms.ui.f6209440743a20f3", "{p0} {p1}?", { p0: labels[pending.action], p1: name })}</DialogTitle>
                <DialogDescription>{pending && descriptions[kind][pending.action]}</DialogDescription>
                {mutation.isError && <p role="alert" className="mt-3 text-sm text-danger">{mutation.error.message}</p>}
                <DialogFooter>
                    <Button variant="outline" disabled={mutation.isPending} onClick={() => setPending(null)}>{uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel")}</Button>
                    <Button variant={pending?.action === 'delete' ? 'destructive' : 'primary'} disabled={mutation.isPending}
                        onClick={() => { if (pending) mutation.mutate(pending); }}>
                        {mutation.isPending ? uiText("vidyalms.ui.23e39291d6135814", "Saving…") : pending?.action === 'delete' ? uiText("vidyalms.ui.9adcdf33dce13da7", "Move to Trash") : pending && labels[pending.action]}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </>;
}
