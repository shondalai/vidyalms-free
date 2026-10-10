import { useEffect, useState } from 'react';
import { useNavigationGuard } from '@/shared/navigation-guard';
import type { BadgeAward, Certificate } from '@vidyalms/api-sdk';
import { Badge, Button, Card, EmptyState, ErrorState, Pagination, Skeleton, buttonVariants } from '@/shared/components';
import { useHashRoute } from '@/admin/hooks';
import { useAnnounce } from '@/shared/a11y';
import { useCredentialConfig } from './config';
import {
    isNotRendered,
    useCertificateArtefact,
    useMyBadges,
    useMyCertificates,
    useMyCreditIdentities,
    useSetMyCreditIdentities,
} from './api';
import { TextField } from './fields';
import { CredentialPublication } from './publication';
import { formatCredentialDate } from './lib';
import { credentialPageStrings as strings } from './strings';

/*
 * Everything issued to this account, in the shape the design gives the
 * holder: the code is the link to its own public verification page, the
 * status is the single value the published precedence resolves to, and the
 * file is reached only through a signed, expiring URL.
 *
 * The download is asked for at the moment somebody wants the file rather
 * than fetched with the list, because the URL expires and a link handed out
 * early is a link that has already died. A certificate whose artefact has
 * not been rendered yet says so and invites another try: rendering is a
 * scheduled task, and a learner arriving in the seconds before it runs
 * should be told rather than handed a broken link.
 */

const mine = strings.mine;

function statusTone(status: string): 'ok' | 'warn' | 'danger' | 'neutral' {
    if (status === 'active') {
        return 'ok';
    }
    if (status === 'revoked') {
        return 'danger';
    }
    if (status === 'suspended' || status === 'expired') {
        return 'warn';
    }
    return 'neutral';
}

const STATUS_LABELS: Record<string, string> = {
    active: 'Valid',
    not_yet_valid: 'Not yet valid',
    expired: 'Expired',
    suspended: 'Suspended',
    revoked: 'Revoked',
    superseded: 'Replaced',
};

export function MyCredentialsPage() {
    const config = useCredentialConfig();
    const [hash, navigate] = useHashRoute();
    const params = new URL(hash || '/', 'https://route.invalid').searchParams;
    const pageFor = (key: string) => {
        const page = Number(params.get(key) ?? 1);
        return Number.isSafeInteger(page) && page >= 1 && page <= 1_000_000 ? page : 1;
    };
    const certificatePage = pageFor('certificates'); const badgePage = pageFor('badges');
    const setPage = (key: string, page: number) => { const next = new URLSearchParams(params); if (page === 1) next.delete(key); else next.set(key, String(page)); navigate(next.size ? `/?${next}` : '/'); };
    const certificates = useMyCertificates(true, { page: certificatePage, per_page: 20 });
    const hasBadges = config.routes?.includes('/me/badges') ?? true;
    const hasIdentities = config.routes?.includes('/me/credit-identities') ?? true;
    const hasPublication = config.routes?.includes('/me/credentials/publication') ?? true;
    const badges = useMyBadges(hasBadges, { page: badgePage, per_page: 20 });

    return (
        <div className="grid gap-5">
            <header className="grid gap-1.5">
                <h1 className="font-display text-lg font-semibold text-fg">{mine.title}</h1>
                <p className="text-[13.5px] text-fg-2">{mine.intro}</p>
                <nav aria-label={mine.learnerNavigation} className="mt-2 flex flex-wrap gap-2">
                    {config.urls.myLearning && <a className={buttonVariants({ variant: 'outline', size: 'sm' })} href={config.urls.myLearning}>{mine.myLearning}</a>}
                    {config.urls.organisations && <a className={buttonVariants({ variant: 'ghost', size: 'sm' })} href={config.urls.organisations}>{mine.organisations}</a>}
                    {config.urls.preferences && <a className={buttonVariants({ variant: 'ghost', size: 'sm' })} href={config.urls.preferences}>{mine.preferences}</a>}
                </nav>
            </header>

            {hasPublication && <CredentialPublication />}

            <section aria-label={mine.certificates} className="grid gap-3">
                <h2 className="font-display text-sm font-bold text-fg">{mine.certificates}</h2>
                {certificates.isPending && <Skeleton className="h-24 w-full" />}
                {certificates.isError && (
                    <ErrorState
                        error={certificates.error}
                        title={mine.error}
                        onRetry={() => void certificates.refetch()}
                    />
                )}
                {certificates.isSuccess &&
                    (certificates.data.items.length === 0 ? (
                        <EmptyState title={mine.empty} />
                    ) : (
                        <ul className="grid gap-2.5">
                            {certificates.data.items.map((certificate) => (
                                <li key={certificate.id}>
                                    <CertificateCard certificate={certificate} locale={config.locale} />
                                </li>
                            ))}
                        </ul>
                    ))}
                <Pagination label={mine.certificatePages} page={certificatePage} perPage={20} total={certificates.data?.total ?? 0} onPageChange={(page) => setPage('certificates', page)} />
            </section>

            {hasBadges && <section aria-label={mine.badges} className="grid gap-3">
                <h2 className="font-display text-sm font-bold text-fg">{mine.badges}</h2>
                {badges.isPending && <Skeleton className="h-16 w-full" />}
                {badges.isError && (
                    <ErrorState error={badges.error} title={mine.error} onRetry={() => void badges.refetch()} />
                )}
                {badges.isSuccess &&
                    (badges.data.available === false ? (
                        <p className="text-[13px] text-fg-2">{mine.badgesUnavailable}</p>
                    ) : badges.data.items.length === 0 ? (
                        <EmptyState title={mine.badgesEmpty} />
                    ) : (
                        <ul className="grid gap-2.5">
                            {badges.data.items.map((award) => (
                                <li key={award.id}>
                                    <BadgeCard award={award} locale={config.locale} />
                                </li>
                            ))}
                        </ul>
                    ))}
                <Pagination label={mine.badgePages} page={badgePage} perPage={20} total={badges.data?.total ?? 0} onPageChange={(page) => setPage('badges', page)} />
            </section>}

            {hasIdentities && <CreditIdentities />}
        </div>
    );
}

function CertificateCard({ certificate, locale }: { certificate: Certificate; locale: string }) {
    const announce = useAnnounce();
    const artefact = useCertificateArtefact();
    const withdrawn = certificate.status === 'revoked' || certificate.status === 'superseded';
    const link = withdrawn ? null : artefact.data ?? null;
    const date = (value: string | null | undefined) => formatCredentialDate(value, locale);

    useEffect(() => {
        if (link !== null) {
            announce(mine.downloadReady);
        }
    }, [link, announce]);

    const code = certificate.code_display ?? certificate.code;

    return (
        <Card className="grid gap-2.5 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <code className="font-mono text-[12px] text-fg">{code}</code>
                <Badge variant={statusTone(certificate.status)}>
                    {STATUS_LABELS[certificate.status] ?? certificate.status}
                </Badge>
            </div>
            <dl className="grid grid-cols-[minmax(0,120px)_minmax(0,1fr)] gap-x-4 gap-y-1 text-[12.5px]">
                {date(certificate.completed_at) !== null && (
                    <>
                        <dt className="text-fg-2">{strings.verify.labels.completed}</dt>
                        <dd className="text-fg">{date(certificate.completed_at)}</dd>
                    </>
                )}
                <dt className="text-fg-2">{strings.verify.labels.issued}</dt>
                <dd className="text-fg">{date(certificate.issued_at)}</dd>
                {date(certificate.expires_at) !== null && (
                    <>
                        <dt className="text-fg-2">{strings.verify.labels.expires}</dt>
                        <dd className="text-fg">{date(certificate.expires_at)}</dd>
                    </>
                )}
                {date(certificate.revoked_at) !== null && (
                    <>
                        <dt className="text-fg-2">{strings.verify.labels.revoked}</dt>
                        <dd className="text-fg">{date(certificate.revoked_at)}</dd>
                    </>
                )}
                {certificate.credits.length > 0 && (
                    <>
                        <dt className="text-fg-2">{strings.verify.labels.credit}</dt>
                        <dd className="text-fg">
                            {certificate.credits
                                .map((credit) =>
                                    mine.creditLine(credit.amount, credit.unit, credit.scheme_label ?? credit.scheme),
                                )
                                .join(', ')}
                        </dd>
                    </>
                )}
            </dl>

            {artefact.isError && !isNotRendered(artefact.error) && <ErrorState error={artefact.error} />}
            {artefact.isError && isNotRendered(artefact.error) && (
                <p role="status" className="text-[12.5px] text-warn-strong">
                    {mine.notRendered}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={artefact.isPending || withdrawn}
                    aria-label={mine.downloadNamed(code)}
                    onClick={() => {
                        void artefact.mutateAsync({ certificateId: certificate.id }).catch(() => undefined);
                    }}
                >
                    {artefact.isPending ? mine.downloading : mine.download}
                </Button>
                {link !== null && (
                    <>
                        <a
                            className={buttonVariants({ variant: 'ghost', size: 'sm' })}
                            href={link.url}
                            download={link.filename}
                        >
                            {mine.openArtefact}
                        </a>
                        {/* The media type is whatever the renderer produced,
                            which is HTML in the free build and may be a PDF
                            or an image where a paid renderer is registered,
                            so it is read rather than assumed. */}
                        <span className="text-[11.5px] text-fg-2">
                            {link.filename} ({link.media_type})
                        </span>
                    </>
                )}
                {certificate.credential_url !== null && (
                    <a
                        className="text-[12.5px] text-accent underline-offset-2 hover:underline"
                        aria-label={mine.credentialNamed(code)}
                        href={certificate.credential_url}
                    >
                        {mine.credential}
                    </a>
                )}
            </div>
            <p className="text-[11.5px] text-fg-2">{mine.credentialHint}</p>
        </Card>
    );
}

function BadgeCard({ award, locale }: { award: BadgeAward; locale: string }) {
    return (
        <Card className="grid gap-2 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-display text-[13px] font-semibold text-fg">
                    {award.badge_name ?? award.code}
                </span>
                <Badge variant={statusTone(award.status)}>{STATUS_LABELS[award.status] ?? award.status}</Badge>
            </div>
            <p className="text-[12.5px] text-fg-2">
                {mine.issuedOn(formatCredentialDate(award.issued_at, locale) ?? '')}
            </p>
            <div className="flex flex-wrap gap-3 text-[12.5px]">
                {award.verification_url !== null && (
                    <a
                        className="text-accent underline-offset-2 hover:underline"
                        aria-label={mine.verifyNamed(award.code)}
                        href={award.verification_url}
                    >
                        {mine.verify}
                    </a>
                )}
                {award.image_url !== undefined && award.image_url !== null && (
                    <a
                        className="text-accent underline-offset-2 hover:underline"
                        aria-label={mine.badgeImageNamed(award.code)}
                        href={award.image_url}
                    >
                        {mine.badgeImage}
                    </a>
                )}
                {award.credential_url !== null && (
                    <a className="text-accent underline-offset-2 hover:underline" href={award.credential_url}>
                        {mine.credential}
                    </a>
                )}
            </div>
        </Card>
    );
}

function CreditIdentities() {
    const announce = useAnnounce();
    const identities = useMyCreditIdentities();
    const save = useSetMyCreditIdentities();
    const [values, setValues] = useState<Record<string, string>>({});
    useNavigationGuard(save.isPending || (identities.data?.items ?? []).some((item) => {
        const value = values[`${item.scheme}:${item.field}`];
        return value !== undefined && value !== (item.value ?? '');
    }));

    useEffect(() => {
        const next: Record<string, string> = {};
        for (const item of identities.data?.items ?? []) {
            next[`${item.scheme}:${item.field}`] = item.value ?? '';
        }
        setValues(next);
    }, [identities.data]);

    if (identities.isPending) {
        return <Skeleton className="h-16 w-full" />;
    }
    if (identities.isError) {
        return null;
    }

    const items = identities.data.items;

    return (
        <section aria-label={mine.identities} className="grid gap-3">
            <h2 className="font-display text-sm font-bold text-fg">{mine.identities}</h2>
            <p className="text-[12.5px] text-fg-2">{mine.identitiesHint}</p>
            {items.length === 0 ? (
                <p className="text-[13px] text-fg-2">{mine.identitiesEmpty}</p>
            ) : (
                <Card className="grid gap-2.5 p-4">
                    {items.map((item) => {
                        const key = `${item.scheme}:${item.field}`;
                        return (
                            <TextField
                                key={key}
                                label={item.label}
                                hint={item.available ? undefined : mine.identityUnavailable}
                                value={values[key] ?? ''}
                                onChange={(event) => setValues({ ...values, [key]: event.target.value })}
                            />
                        );
                    })}
                    {save.isError && <ErrorState error={save.error} />}
                    <div className="flex justify-end">
                        <Button
                            variant="primary"
                            size="sm"
                            disabled={save.isPending}
                            onClick={() => {
                                void save
                                    .mutateAsync({
                                        body: {
                                            items: items.map((item) => ({
                                                scheme: item.scheme,
                                                field: item.field,
                                                value: values[`${item.scheme}:${item.field}`] ?? null,
                                            })),
                                        },
                                    })
                                    .then(() => announce(mine.saved))
                                    .catch(() => undefined);
                            }}
                        >
                            {save.isPending ? mine.saving : mine.save}
                        </Button>
                    </div>
                </Card>
            )}
        </section>
    );
}
