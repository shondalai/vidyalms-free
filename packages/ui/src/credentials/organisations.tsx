import { useEffect, useState } from 'react';
import { ArrowRight, BookOpen, Building2, Check, MailCheck, ShieldCheck } from 'lucide-react';
import { Button, Card, EmptyState, ErrorState, Icon, Pagination, Skeleton, buttonVariants } from '@/shared/components';
import { TextField } from '@/admin/components/field';
import { useCertificateWriteAttempt as useWriteAttempt } from '@/admin/credentials/api';
import { useAcceptOrganisationInvitation, useClaimOrganisationInvitation, useOwnOrganisations } from '@/admin/organisations/api';
import { useCredentialConfig } from './config';
import { organisationJoinStrings as strings } from './organisation-strings';
import { OrganisationLearning } from './organisation-learning';
import { organisationLearningStrings as learningStrings } from './organisation-learning-strings';

type Membership = NonNullable<ReturnType<typeof useOwnOrganisations>['data']>['items'][number];

function OrganisationCard({ org }: { org: Membership }) {
    const learning = org.learning;
    const hasCourses = learning && learning.assigned + learning.available > 0;
    const action = learning && !hasCourses ? learningStrings.viewOrganisation : learningStrings.view;
    return <li className="vy-membership-item">
        <article className="vy-membership-card" aria-labelledby={`org-title-${org.id}`}>
            <header className="vy-membership-header">
                <span className="vy-membership-mark"><Icon icon={Building2} size={24} /></span>
                <div className="min-w-0">
                    <h2 id={`org-title-${org.id}`} className="vy-membership-title">{org.name}</h2>
                    <p className="vy-membership-status"><Icon icon={Check} size={14} />{strings.member}</p>
                </div>
            </header>
            {learning && (hasCourses ? <dl className="vy-membership-stats">
                <div><dt>{learningStrings.assignedCourses}</dt><dd>{learning.assigned}</dd></div>
                <div><dt>{learningStrings.availableCourses}</dt><dd>{learning.available}</dd></div>
            </dl> : <div className="vy-membership-empty">
                <Icon icon={BookOpen} size={20} />
                <div><p className="vy-membership-empty-title">{learningStrings.noCoursesYet}</p><p>{learningStrings.noCoursesYetHint}</p></div>
            </div>)}
            <footer className="vy-membership-footer">
                {learning && learning.required > 0 && <p className="vy-membership-required"><span>{learning.required}</span>{learningStrings.requiredToComplete}</p>}
                <a className="vy-membership-link" href={`#/organisation/${org.id}/learning`} aria-labelledby={`org-action-${org.id} org-title-${org.id}`}>
                    <span id={`org-action-${org.id}`}>{action}</span><Icon icon={ArrowRight} size={17} />
                </a>
            </footer>
        </article>
    </li>;
}

export function parseInvitationHash(hash: string): { token: string | null; claimId: number | null; invalid: boolean } {
    const path = hash.replace(/^#/, '');
    if (!path || path === '/') return { token: null, claimId: null, invalid: false };
    const invitation = /^\/organisation-invitation\/([A-Za-z0-9_-]{32,256})$/.exec(path);
    if (invitation) return { token: invitation[1]!, claimId: null, invalid: false };
    const claim = /^\/organisation-claim\/([1-9][0-9]{0,14})$/.exec(path);
    return { token: null, claimId: claim ? Number(claim[1]) : null, invalid: claim === null };
}

export function OrganisationInvitationPage() {
    const [hash, setHash] = useState(window.location.hash);
    useEffect(() => {
        const changed = () => setHash(window.location.hash);
        window.addEventListener('hashchange', changed);
        return () => window.removeEventListener('hashchange', changed);
    }, []);
    const organisation = /^#\/organisation\/([1-9][0-9]{0,9})\/learning$/.exec(hash);
    if (organisation && Number(organisation[1]) <= 2147483646) return <OrganisationLearning key={organisation[1]} id={Number(organisation[1])} />;
    return <OrganisationInvitationContent key={hash} />;
}

function OrganisationInvitationContent() {
    const config = useCredentialConfig();
    const [initial] = useState(() => parseInvitationHash(window.location.hash));
    const [token, setToken] = useState(initial.token); const [claimId, setClaimId] = useState(initial.claimId);
    const [code, setCode] = useState(''); const [joined, setJoined] = useState(false); const [page, setPage] = useState(1);
    const claim = useClaimOrganisationInvitation(); const accept = useAcceptOrganisationInvitation();
    const claimWrite = useWriteAttempt(); const acceptWrite = useWriteAttempt();
    const mine = useOwnOrganisations(page, config.user.id > 0 && token === null && claimId === null);
    const activeInvitation = token !== null || claimId !== null;
    return <section aria-label={strings.title} className="grid w-full min-w-0 gap-5 font-body text-fg">
        <header className="flex items-start gap-3"><span className="rounded-lg bg-accent-soft p-3 text-accent"><Icon icon={activeInvitation ? ShieldCheck : Building2} size={28} /></span>
            <div><h1 className="font-display text-2xl font-semibold">{activeInvitation ? strings.invite : strings.title}</h1><p className="mt-1 text-sm text-fg-2">{activeInvitation ? strings.inviteIntro : strings.intro}</p></div></header>
        {initial.invalid ? <EmptyState title={strings.invalid} description={strings.invalidBody} />
            : config.user.id <= 0 ? <Card className="grid gap-4 p-5"><p className="text-sm text-fg-2">{strings.loginHint}</p><div className="flex flex-wrap gap-3">
                {config.urls.login && <a className={buttonVariants({ variant: 'primary' })} href={config.urls.login} target="_blank" rel="noopener noreferrer">{strings.login}</a>}
                <Button variant="outline" onClick={() => window.location.reload()}>{strings.refresh}</Button></div></Card>
                : joined ? <Card className="grid gap-3 p-5"><Icon icon={ShieldCheck} size={28} className="text-accent" /><h2 className="font-display text-lg font-semibold">{strings.success}</h2><p className="text-sm text-fg-2">{strings.successBody}</p>{config.urls.myLearning && <a className={buttonVariants({ className: 'justify-self-start' })} href={config.urls.myLearning}>{strings.learning}</a>}</Card>
                    : activeInvitation ? <Card className="grid gap-4 p-5"><p className="text-xs text-fg-2">{strings.signedIn(config.user.name)}</p>
                        {token !== null ? <><Button className="justify-self-start" disabled={claim.isPending} onClick={() => {
                            const attempt = claimWrite.capture({ token }, () => ({ body: { token } }));
                            void claim.mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey }).then((result) => {
                                claimWrite.complete(attempt); setClaimId(result.claim_id); setToken(null);
                                // The opaque claim id is account-bound, not an invitation credential. Never persist tokens or codes.
                                const url = new URL(window.location.href); url.hash = `/organisation-claim/${result.claim_id}`; window.history.replaceState({ ...window.history.state, url: url.href }, '', url);
                            }).catch(() => undefined);
                        }}>{claim.isPending ? strings.sending : strings.verify}</Button>{claim.isError && <ErrorState error={claim.error} />}</>
                            : <form className="grid max-w-xl gap-4" onSubmit={(event) => {
                                event.preventDefault(); if (claimId === null) return;
                                const attempt = acceptWrite.capture({ claimId, code }, () => ({ body: { claim_id: claimId, code: code.trim() } }));
                                void accept.mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey }).then(() => {
                                    acceptWrite.complete(attempt); setCode(''); setClaimId(null); setJoined(true);
                                    const url = new URL(window.location.href); url.hash = ''; window.history.replaceState({ ...window.history.state, url: url.href }, '', url);
                                }).catch(() => undefined);
                            }}><div><h2 className="flex items-center gap-2 font-display text-lg font-semibold"><Icon icon={MailCheck} size={22} />{strings.proof}</h2><p className="mt-2 text-sm text-fg-2">{strings.proofIntro}</p></div>
                                <TextField label={strings.code} value={code} autoComplete="one-time-code" required maxLength={64} onChange={(event) => setCode(event.target.value)} disabled={accept.isPending} />
                                {accept.isError && <ErrorState error={accept.error} />}<Button type="submit" className="justify-self-start" disabled={accept.isPending || !code.trim()}>{accept.isPending ? strings.accepting : strings.accept}</Button>
                            </form>}
                    </Card> : <>{mine.isPending && <div className="vy-memberships" aria-label={learningStrings.loading}><div className="vy-memberships-grid">{[1, 2, 3, 4].map((n) => <Skeleton key={n} className="h-60 w-full rounded-xl" />)}</div></div>}{mine.isError && <ErrorState error={mine.error} onRetry={() => { void mine.refetch(); }} />}
                        {mine.data?.items.length === 0 && <EmptyState title={strings.empty} description={strings.emptyBody} />}
                        {Boolean(mine.data?.items.length) && <div className="vy-memberships"><ul className="vy-memberships-grid">{mine.data?.items.map((org) => <OrganisationCard key={org.id} org={org} />)}</ul></div>}
                        <Pagination label={strings.pages} page={page} perPage={20} total={mine.data?.total ?? 0} onPageChange={setPage} /></>}
    </section>;
}
