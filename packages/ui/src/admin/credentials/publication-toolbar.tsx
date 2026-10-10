import { useState } from 'react';
import type { CertificateTemplateDetail } from '@vidyalms/api-sdk';
import { Badge, Button, Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle, ErrorState } from '@/shared/components';
import { useCertificateWriteAttempt } from './api';
import { useDefaultCertificateTemplate, usePublishCertificateTemplate } from './studio-api';
import { useStudioNavigation } from './studio-drafts';
import { certificateStudioStrings as strings } from './studio-strings';

export function CertificatePublicationToolbar({ template, onDone }: {
    template: CertificateTemplateDetail; onDone: (message: string) => void;
}) {
    const { dirty } = useStudioNavigation();
    const publish = usePublishCertificateTemplate();
    const makeDefault = useDefaultCertificateTemplate();
    const write = useCertificateWriteAttempt();
    const [confirmation, setConfirmation] = useState<'publish' | 'default' | null>(null);
    const pending = publish.isPending || makeDefault.isPending;
    const publishedRevision = template.published_revision ?? null;
    const submit = async () => {
        try {
            if (confirmation === 'publish') {
                const attempt = write.capture({ id: template.id, operation: 'publish' }, () => ({
                    id: template.id, body: { expected_revision: template.revision },
                }));
                await publish.mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey });
                write.complete(attempt);
                onDone(strings.published);
            } else if (confirmation === 'default' && template.default_selection !== undefined) {
                const selection = template.default_selection;
                const attempt = write.capture({ id: template.id, operation: 'default' }, () => ({
                    id: template.id, body: { expected_revision: template.revision,
                        expected_default_id: selection.id, expected_default_revision: selection.revision },
                }));
                await makeDefault.mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey });
                write.complete(attempt);
                onDone(strings.defaultSaved);
            }
            setConfirmation(null);
        } catch { /* Keep the exact retry attempt and the confirmation open. */ }
    };
    return <section className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-surface p-3">
        <Badge variant={publishedRevision === null ? 'neutral' : 'accent'}>
            {publishedRevision === null ? strings.draftOnly : strings.publishedRevision(publishedRevision)}
        </Badge>
        {template.has_unpublished_changes && <Badge variant="warn">{strings.unpublished}</Badge>}
        <div className="ml-auto flex flex-wrap gap-2">
            {!template.is_default && publishedRevision !== null && <Button variant="outline" size="sm"
                disabled={dirty || pending || template.default_selection === undefined || template.state === 'archived'}
                onClick={() => setConfirmation('default')}>{strings.setDefault}</Button>}
            <Button size="sm" disabled={dirty || pending || template.state === 'archived'}
                onClick={() => setConfirmation('publish')}>{strings.publish}</Button>
        </div>
        <Dialog open={confirmation !== null} onOpenChange={(open) => { if (!open && !pending) setConfirmation(null); }}>
            <DialogContent>
                <DialogTitle>{confirmation === 'default' ? strings.defaultTitle : strings.publishTitle}</DialogTitle>
                <DialogDescription>{confirmation === 'default' ? strings.defaultBody : strings.publishBody}</DialogDescription>
                {(publish.isError || makeDefault.isError) && <ErrorState error={publish.error ?? makeDefault.error} />}
                <DialogFooter>
                    <Button variant="ghost" disabled={pending} onClick={() => setConfirmation(null)}>{strings.cancel}</Button>
                    <Button disabled={pending || dirty} onClick={() => { void submit(); }}>
                        {pending ? strings.publishing : confirmation === 'default' ? strings.setDefault : strings.publish}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>;
}
