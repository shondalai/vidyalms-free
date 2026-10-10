import { useState } from 'react';
import { CertificateArtwork } from './certificate-artwork';
import type { CertificateBlueprintCatalogue, CertificateTemplate } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    Skeleton,
} from '@/shared/components';
import { SelectField, TextField } from '../components/field';
import { useCertificateWriteAttempt } from './api';
import {
    useCertificateBlueprints,
    useCloneCertificateBlueprint,
    useCloneCertificateTemplate,
} from './studio-api';
import { certificateStudioStrings as strings } from './studio-strings';

type Blueprint = CertificateBlueprintCatalogue['items'][number];

export function CertificateBlueprintGallery({ onCreated }: { onCreated: (id: number) => void }) {
    const catalogue = useCertificateBlueprints();
    const [selected, setSelected] = useState<Blueprint | null>(null);
    return (
        <section className="grid gap-3" aria-labelledby="vy-certificate-blueprints">
            <div>
                <h2 id="vy-certificate-blueprints" className="font-display text-lg font-semibold text-fg">
                    {strings.blueprintHeading}
                </h2>
                <p className="mt-1 text-sm text-fg-2">{strings.description}</p>
            </div>
            {catalogue.isPending ? (
                <Skeleton className="h-56 w-full" />
            ) : catalogue.isError ? (
                <ErrorState error={catalogue.error} onRetry={() => void catalogue.refetch()} />
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {catalogue.data.items.map((blueprint) => (
                        <article
                            key={blueprint.key}
                            className="min-w-0 overflow-hidden rounded-lg border border-border bg-surface"
                        >
                            <CertificateArtwork
                                html={blueprint.preview_html}
                                title={strings.previewNamed(blueprint.name)}
                            />
                            <div className="grid gap-2 p-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="font-display text-sm font-semibold text-fg">
                                        {blueprint.name}
                                    </h3>
                                    <Badge>{blueprint.category}</Badge>
                                </div>
                                <p className="min-h-10 text-xs leading-relaxed text-fg-2">
                                    {blueprint.description}
                                </p>
                                <Button
                                    className="mt-1 justify-self-start"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setSelected(blueprint)}
                                >
                                    {strings.clone}
                                </Button>
                            </div>
                        </article>
                    ))}
                    {catalogue.data.items.length === 0 && (
                        <p className="text-sm text-fg-2">{strings.blueprintMissing}</p>
                    )}
                </div>
            )}
            {selected !== null && (
                <CertificateCloneDialog
                    blueprint={selected}
                    renderers={catalogue.data?.renderer_options ?? []}
                    onClose={() => setSelected(null)}
                    onCreated={(id) => {
                        setSelected(null);
                        onCreated(id);
                    }}
                />
            )}
        </section>
    );
}

export function CertificateCloneDialog({
    blueprint,
    template,
    renderers = [],
    onClose,
    onCreated,
}: {
    blueprint?: Blueprint;
    template?: CertificateTemplate;
    renderers?: CertificateBlueprintCatalogue['renderer_options'];
    onClose: () => void;
    onCreated: (id: number) => void;
}) {
    const cloneBlueprint = useCloneCertificateBlueprint();
    const cloneTemplate = useCloneCertificateTemplate();
    const write = useCertificateWriteAttempt();
    const [name, setName] = useState(strings.copyName(blueprint?.name ?? template?.name ?? ''));
    const [publishedRevision] = useState(template?.published_revision ?? null);
    const options = renderers.filter((renderer) => renderer.formats.includes('elements'));
    const [renderer, setRenderer] = useState(
        template?.renderer ?? options.find((option) => option.key === 'pdf')?.key ?? options[0]?.key ?? '',
    );
    const pending = cloneBlueprint.isPending || cloneTemplate.isPending;
    const submit = async () => {
        try {
            if (blueprint !== undefined) {
                const attempt = write.capture({ blueprint: blueprint.key, name, renderer }, () => ({
                    key: blueprint.key,
                    body: { name: name.trim(), version: blueprint.version, renderer },
                }));
                const created = await cloneBlueprint.mutateAsync({
                    ...attempt.input,
                    idempotencyKey: attempt.idempotencyKey,
                });
                write.complete(attempt);
                onCreated(created.id);
            } else if (template !== undefined && publishedRevision !== null) {
                const attempt = write.capture({ template: template.id, name }, () => ({
                    id: template.id,
                    body: { name: name.trim(), published_revision: publishedRevision },
                }));
                const created = await cloneTemplate.mutateAsync({
                    ...attempt.input,
                    idempotencyKey: attempt.idempotencyKey,
                });
                write.complete(attempt);
                onCreated(created.id);
            }
        } catch {
            /* The original revision and retry key stay pinned. */
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !pending) onClose();
            }}
        >
            <DialogContent>
                <DialogTitle>{strings.cloneName}</DialogTitle>
                <DialogDescription>{strings.cloneHint}</DialogDescription>
                <TextField
                    label={strings.cloneName}
                    value={name}
                    maxLength={240}
                    onChange={(event) => setName(event.target.value)}
                />
                {blueprint !== undefined && options.length > 0 && (
                    <SelectField
                        label={strings.renderer}
                        value={renderer}
                        onChange={(event) => setRenderer(event.target.value)}
                    >
                        {options.map((option) => (
                            <option key={option.key} value={option.key}>
                                {option.label}
                            </option>
                        ))}
                    </SelectField>
                )}
                {blueprint !== undefined && options.length === 0 && (
                    <p role="alert" className="text-sm text-fg-2">
                        {strings.rendererMissing}
                    </p>
                )}
                {(cloneBlueprint.isError || cloneTemplate.isError) && (
                    <ErrorState error={cloneBlueprint.error ?? cloneTemplate.error} />
                )}
                <DialogFooter>
                    <Button variant="ghost" disabled={pending} onClick={onClose}>
                        {strings.cancel}
                    </Button>
                    <Button
                        disabled={
                            pending || name.trim() === '' || (blueprint !== undefined && renderer === '')
                        }
                        onClick={() => {
                            void submit();
                        }}
                    >
                        {strings.createDraft}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
