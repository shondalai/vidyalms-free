import { useState } from 'react';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
} from '@/shared/components';
import { TextField } from '../components/field';
import { useCertificateWriteAttempt as useWriteAttempt } from '../credentials/api';
import {
    useStudioDraft as useOrganisationDraft,
    useStudioNavigation as useOrganisationNavigation,
} from '../credentials/studio-drafts';
import { useCreateOrganisation } from './api';
import { organisationStrings as strings } from './strings';
import { orgWorkspaceStrings as s } from './workspace-strings';
export function CreateOrganisation({ close, created }: { close: () => void; created: (id: number) => void }) {
    const create = useCreateOrganisation();
    const write = useWriteAttempt();
    const navigation = useOrganisationNavigation();
    const setDirty = useOrganisationDraft('new-org');
    const [review, setReview] = useState(false);
    const [name, setName] = useState('');
    const [slug, setSlug] = useState('');
    const [slugEdited, setSlugEdited] = useState(false);
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (!review) {
            setReview(true);
            return;
        }

        const body = { name: name.trim(), slug: slug.trim() };
        const attempt = write.capture(body, () => ({ body }));

        void create
            .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
            .then((result) => {
                write.complete(attempt);
                setDirty(false);
                created(result.id);
            })
            .catch(() => undefined);
    };
    const nameChanged = (event: React.ChangeEvent<HTMLInputElement>) => {
        const value = event.target.value;

        setName(value);
        setDirty(true);

        if (!slugEdited) {
            setSlug(
                value
                    .toLowerCase()
                    .normalize('NFKD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '')
                    .slice(0, 190),
            );
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !create.isPending) navigation.requestLeave(close);
            }}
        >
            <DialogContent className="vy-org-onboarding">
                <DialogTitle>{strings.create}</DialogTitle>
                <DialogDescription>{review ? s.reviewIntro : strings.createIntro}</DialogDescription>
                <ol className="vy-org-onboarding-steps" aria-label={strings.create}>
                    <li aria-current={!review ? 'step' : undefined}>
                        <span>1</span>
                        {s.detailsStep}
                    </li>
                    <li aria-current={review ? 'step' : undefined}>
                        <span>2</span>
                        {s.reviewStep}
                    </li>
                </ol>
                <form className="grid gap-4" onSubmit={submit}>
                    <fieldset hidden={review} disabled={create.isPending} className="grid gap-4">
                        <TextField
                            label={strings.name}
                            value={name}
                            required
                            maxLength={255}
                            onChange={nameChanged}
                        />
                        <TextField
                            label={strings.slug}
                            hint={strings.slugHint}
                            value={slug}
                            required
                            pattern="[a-z0-9]+(-[a-z0-9]+)*"
                            maxLength={190}
                            onChange={(event) => {
                                setSlugEdited(true);
                                setSlug(event.target.value);
                                setDirty(true);
                            }}
                        />
                    </fieldset>
                    {review && (
                        <div className="vy-org-review">
                            <h3>{name}</h3>
                            <p>{slug}</p>
                            <strong>{s.nextSteps}</strong>
                            <p>{s.ownerNote}</p>
                            <p>{s.noInvite}</p>
                        </div>
                    )}
                    {create.isError && <ErrorState error={create.error} />}
                    <DialogFooter>
                        {review && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={create.isPending}
                                onClick={() => setReview(false)}
                            >
                                {s.previous}
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={create.isPending}
                            onClick={() => navigation.requestLeave(close)}
                        >
                            {strings.cancel}
                        </Button>
                        <Button type="submit" disabled={create.isPending || !name.trim() || !slug.trim()}>
                            {create.isPending ? strings.saving : review ? strings.create : s.next}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
