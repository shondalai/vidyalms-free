import { useEffect, useState, type FormEvent } from 'react';
import { ProblemError } from '@vidyalms/api-sdk';
import type { CertificateVerification } from '@vidyalms/api-sdk';
import { Badge, Button, Card, ErrorState, Skeleton } from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { TextField } from './fields';
import { useCredentialConfig } from './config';
import { useVerification } from './api';
import { credentialPageStrings as strings } from './strings';
import { formatCredentialDate, formatCredentialDateTime } from './lib';

/*
 * The public verification page (artboard 1d), with every state the status
 * enumeration can report.
 *
 * The endpoint answers 200 for every input including an unknown code, so
 * probing reveals nothing structural, and the page renders the answer rather
 * than inventing one. `status` is single valued under a published precedence
 * (revoked, then superseded, then suspended, then not yet valid, then
 * expired, then active) and `revoked` and `expired` are reported
 * independently beside it, because a credential can be unexpired and revoked
 * or expired and not revoked and a single-valued status cannot say both.
 *
 * A superseded code keeps answering and points forward, so a certificate
 * printed on paper never goes silent after a correction.
 *
 * PII. Only what the server sent is shown, and a withheld name is stated as
 * withheld rather than blanked: the site setting and the certificate's own
 * publication flag decide, not this page. Nothing identifying beyond name,
 * course, issuer, dates and status ever arrives here.
 */

type Verification = CertificateVerification & {
    subject_type?: 'certificate' | 'badge_award';
    achievement_name?: string | null;
};

function toneOf(status: string): 'ok' | 'warn' | 'danger' | 'neutral' {
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

function glyphOf(status: string): string {
    if (status === 'active') {
        return '✓';
    }
    if (status === 'revoked') {
        return '✕';
    }
    return '!';
}

export function VerifyPage() {
    const config = useCredentialConfig();
    const announce = useAnnounce();
    const badge = config.subject === 'badge_award';
    const [typed, setTyped] = useState(config.code);
    const [asked, setAsked] = useState(config.code.trim());
    const verification = useVerification(config.subject, asked);
    const result = (verification.data ?? null) as Verification | null;

    useEffect(() => {
        if (result === null) {
            return;
        }
        const status = result.status ?? 'not_found';
        const heading = result.reason === 'not_found'
            ? strings.verify.headings.not_found
            : (strings.verify.headings[status] ?? status);
        announce(heading ?? '');
    }, [result, announce]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setAsked(typed.trim());
    };

    const rateLimited = verification.error instanceof ProblemError && verification.error.status === 429;

    return (
        <div className="grid gap-4">
            <header className="grid gap-1.5">
                <h1 className="font-display text-lg font-semibold text-fg">
                    {badge ? strings.verify.titleBadge : strings.verify.title}
                </h1>
                <p className="text-[13.5px] text-fg-2">{badge ? strings.verify.introBadge : strings.verify.intro}</p>
            </header>

            <form className="flex flex-wrap items-end gap-2" onSubmit={submit}>
                <TextField
                    fieldClassName="min-w-[220px] flex-1"
                    label={badge ? strings.verify.codeLabelBadge : strings.verify.codeLabel}
                    hint={strings.verify.codeHint}
                    autoCapitalize="characters"
                    maxLength={64}
                    className="font-mono tracking-[0.06em]"
                    value={typed}
                    onChange={(event) => setTyped(event.target.value)}
                />
                <Button type="submit" variant="primary" size="md" disabled={verification.isFetching}>
                    {verification.isFetching ? strings.verify.checking : strings.verify.submit}
                </Button>
            </form>

            <section aria-label={strings.verify.resultRegion} className="grid gap-3">
                {asked === '' && <p className="text-[13px] text-fg-2">{strings.verify.emptyCode}</p>}
                {verification.isPending && asked !== '' && <Skeleton className="h-40 w-full" />}
                {verification.isError && (
                    <ErrorState
                        error={verification.error}
                        title={rateLimited ? strings.verify.rateLimited : strings.verify.error}
                        onRetry={() => void verification.refetch()}
                    />
                )}
                {result !== null && <VerificationCard result={result} code={asked} locale={config.locale} />}
            </section>

            <p className="text-[11.5px] text-fg-2">{strings.verify.scope}</p>
        </div>
    );
}

function VerificationCard({
    result,
    code,
    locale,
}: {
    result: Verification;
    code: string;
    locale: string;
}) {
    const status = result.status ?? 'not_found';
    const notFound = result.reason === 'not_found' || result.status === null;
    const tone = notFound ? 'neutral' : toneOf(status);
    const heading = strings.verify.headings[status] ?? status;
    const reason = strings.verify.reasons[notFound ? 'not_found' : (result.reason ?? '')] ?? null;
    const date = (value: string | null | undefined) => formatCredentialDate(value, locale);

    return (
        <Card className="grid gap-3 p-4">
            <div className="flex flex-wrap items-center gap-3">
                <span
                    aria-hidden="true"
                    className={
                        'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full font-display '
                        + 'text-sm font-bold '
                        + (tone === 'ok'
                            ? 'bg-ok-soft text-ok-strong'
                            : tone === 'danger'
                              ? 'bg-danger-soft text-danger-strong'
                              : tone === 'warn'
                                ? 'bg-warn-soft text-warn-strong'
                                : 'bg-surface-2 text-fg-2')
                    }
                >
                    {glyphOf(status)}
                </span>
                <div className="min-w-0">
                    <p className="font-display text-sm font-bold text-fg">{heading}</p>
                    <p className="text-[12px] text-fg-2">
                        {strings.verify.checkedAt(formatCredentialDateTime(new Date(), locale))}
                    </p>
                </div>
                {/* The two axes are reported independently, because the
                    payload reports them independently: a revoked credential
                    whose expiry has also passed says both. */}
                <span className="ml-auto flex flex-wrap gap-1.5">
                    {result.revoked && <Badge variant="danger">{strings.verify.labels.revoked}</Badge>}
                    {result.expired && <Badge variant="warn">{strings.verify.labels.expired}</Badge>}
                </span>
            </div>

            {reason !== null && <p className="text-[13px] text-fg-2">{reason}</p>}

            {!notFound && (
                <dl className="grid grid-cols-[minmax(0,140px)_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[13px]">
                    <dt className="text-fg-2">{strings.verify.labels.issuedTo}</dt>
                    <dd className="font-semibold text-fg">
                        {result.learner_name ?? <span className="font-normal text-fg-2">{strings.verify.nameWithheld}</span>}
                    </dd>
                    {result.achievement_name !== undefined && result.achievement_name !== null && (
                        <>
                            <dt className="text-fg-2">{strings.verify.labels.achievement}</dt>
                            <dd className="text-fg">{result.achievement_name}</dd>
                        </>
                    )}
                    {result.course_title !== null && (
                        <>
                            <dt className="text-fg-2">{strings.verify.labels.course}</dt>
                            <dd className="text-fg">{result.course_title}</dd>
                        </>
                    )}
                    {date(result.completed_at) !== null && (
                        <>
                            <dt className="text-fg-2">{strings.verify.labels.completed}</dt>
                            <dd className="text-fg">{date(result.completed_at)}</dd>
                        </>
                    )}
                    {date(result.issued_at) !== null && (
                        <>
                            <dt className="text-fg-2">{strings.verify.labels.issued}</dt>
                            <dd className="text-fg">{date(result.issued_at)}</dd>
                        </>
                    )}
                    {date(result.valid_from) !== null && (
                        <>
                            <dt className="text-fg-2">{strings.verify.labels.validFrom}</dt>
                            <dd className="text-fg">{date(result.valid_from)}</dd>
                        </>
                    )}
                    {date(result.expires_at) !== null && (
                        <>
                            <dt className="text-fg-2">
                                {result.expired ? strings.verify.labels.expired : strings.verify.labels.expires}
                            </dt>
                            <dd className="text-fg">{date(result.expires_at)}</dd>
                        </>
                    )}
                    {date(result.revoked_at) !== null && (
                        <>
                            <dt className="text-fg-2">{strings.verify.labels.revoked}</dt>
                            <dd className="text-fg">{date(result.revoked_at)}</dd>
                        </>
                    )}
                    {result.issuer !== null && (
                        <>
                            <dt className="text-fg-2">{strings.verify.labels.issuedBy}</dt>
                            <dd className="text-fg">
                                {result.issuer.url === null ? (
                                    result.issuer.name
                                ) : (
                                    <a
                                        className="text-accent underline-offset-2 hover:underline"
                                        href={result.issuer.url}
                                        rel="noreferrer"
                                    >
                                        {result.issuer.name}
                                    </a>
                                )}
                            </dd>
                        </>
                    )}
                    {result.credits.length > 0 && (
                        <>
                            <dt className="text-fg-2">{strings.verify.labels.credit}</dt>
                            <dd className="text-fg">
                                {result.credits
                                    .map((credit) => `${credit.amount} ${credit.unit} (${credit.scheme_label ?? credit.scheme})`)
                                    .join(', ')}
                            </dd>
                        </>
                    )}
                    <dt className="text-fg-2">{strings.verify.labels.code}</dt>
                    <dd>
                        <code className="font-mono text-[12px] text-fg">{code}</code>
                    </dd>
                </dl>
            )}

            <div className="flex flex-wrap gap-3 text-[12.5px]">
                {result.superseded_by_url !== undefined && result.superseded_by_url !== null && (
                    <a
                        className="text-accent underline-offset-2 hover:underline"
                        href={result.superseded_by_url}
                    >
                        {strings.verify.replacement}
                    </a>
                )}
                {result.credential_url !== null && (
                    <a className="text-accent underline-offset-2 hover:underline" href={result.credential_url}>
                        {strings.verify.credential}
                    </a>
                )}
            </div>
        </Card>
    );
}
