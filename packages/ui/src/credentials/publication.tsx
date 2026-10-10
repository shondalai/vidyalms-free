import { useId, useState } from 'react';
import { Button, Card, ErrorState, Skeleton } from '@/shared/components';
import { translate as uiText } from '@/shared/i18n';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { useMyCredentialPublication, useSetMyCredentialPublication } from './api';

export function CredentialPublication() {
    const id = useId();
    const query = useMyCredentialPublication();
    const save = useSetMyCredentialPublication();
    const [draft, setDraft] = useState<boolean | null>(null);
    const dirty = draft !== null && draft !== query.data?.publish_learner_name;
    useNavigationGuard(dirty || save.isPending);
    const title = uiText('vidyalms.ui.credentialPublicationTitle', 'Public verification privacy');

    return (
        <section aria-labelledby={`${id}-title`} className="grid gap-3">
            <h2 id={`${id}-title`} className="font-display text-sm font-bold text-fg">{title}</h2>
            {query.isPending && <div aria-busy="true" aria-label={title}><Skeleton className="h-24 w-full" /></div>}
            {query.isError && <ErrorState error={query.error}
                title={uiText('vidyalms.ui.credentialPublicationLoadError', 'Could not load your privacy preference.')}
                onRetry={() => void query.refetch()} />}
            {query.isSuccess && <Card className="grid gap-3 p-4">
                <form onSubmit={(event) => {
                    event.preventDefault();
                    if (dirty && draft !== null && !save.isPending) {
                        save.mutate(draft, { onSuccess: () => setDraft(null) });
                    }
                }}>
                    <fieldset disabled={save.isPending} className="grid gap-3">
                        <label htmlFor={id} className="flex items-start gap-2 text-[13px] text-fg">
                            <input id={id} type="checkbox" className="mt-0.5 accent-accent"
                                aria-describedby={`${id}-help ${id}-visibility`}
                                checked={draft ?? query.data.publish_learner_name}
                                onChange={(event) => {
                                    setDraft(event.target.checked);
                                    save.reset();
                                }} />
                            {uiText('vidyalms.ui.credentialPublicationHolderLabel', 'Allow my name on public verification pages')}
                        </label>
                        <p id={`${id}-help`} className="text-[12.5px] text-fg-2">
                            {uiText('vidyalms.ui.credentialPublicationHolderHelp', 'Applies to public certificate and badge verification. Your name can appear only when the site allows it. Turn this off to keep your name hidden even if the site enables publication. Credentials remain verifiable; names in downloaded files are not changed.')}
                        </p>
                        <p id={`${id}-visibility`} role="status" className="text-[12.5px] text-fg-2">
                            {query.data.effective_publish_learner_name
                                ? uiText('vidyalms.ui.credentialPublicationVisible', 'Your saved preference allows your name to appear on public verification pages.')
                                : query.data.publish_learner_name
                                  ? uiText('vidyalms.ui.credentialPublicationSiteHidden', 'This site currently hides learner names. Your saved preference will apply if the site enables name publication.')
                                  : uiText('vidyalms.ui.credentialPublicationHidden', 'Your saved preference keeps your name hidden on public verification pages.')}
                        </p>
                        <Button type="submit" variant="outline" size="sm" disabled={!dirty || save.isPending}>
                            {save.isPending
                                ? uiText('vidyalms.ui.credentialPublicationSaving', 'Saving privacy preference…')
                                : uiText('vidyalms.ui.credentialPublicationHolderSave', 'Save privacy preference')}
                        </Button>
                    </fieldset>
                </form>
                {save.isError && <ErrorState error={save.error}
                    title={uiText('vidyalms.ui.credentialPublicationSaveError', 'Could not save your privacy preference. Please try again.')} />}
                {save.isSuccess && <p role="status" className="text-[12.5px] text-fg-2">
                    {uiText('vidyalms.ui.credentialPublicationHolderSaved', 'Privacy preference saved.')}
                </p>}
            </Card>}
        </section>
    );
}
