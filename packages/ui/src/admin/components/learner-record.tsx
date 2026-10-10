import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import {
    Activity,
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    Award,
    BookOpen,
    Download,
    GraduationCap,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { Badge, Button, EmptyState, Icon, ProgressBar, Skeleton, buttonVariants } from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { useLearnerRecord, type CertificateRecord, type EnrolmentRecord } from '../api';
import { useAdminConfig, workbenchUrl } from '../config';
import { formatAgo, formatDate, roundPercent } from '../lib';
import { useHashRoute } from '../hooks';
import { adminStrings } from '../strings';
import { AdminNotice, ScreenError } from './states';
import { ExtendDialog } from './extend-dialog';
import { GrantAccessDialog } from './grant-access-dialog';
import { PageHeader } from './page-header';
import { FilterSearch } from './filter-toolbar';
import { Tabs } from '@/instructor/components/tabs';
import {
    LearnerAvatar,
    LearnerFilter,
    LearnerPanel,
    LearnerSummary,
    csvContent,
    displayValue,
    downloadFile,
} from '../learners/workspace';
import { learnerWorkspaceStrings as s } from '../learners/strings';
import { ActivityPanel, AssessmentsPanel, EvidenceDialog, ProgressDialog } from '../learners/panels';

const strings = adminStrings.learners.record;
type Section = keyof typeof s.tabs;

function EnrolmentCard({
    enrolment,
    onExtend,
    onProgress,
}: {
    enrolment: EnrolmentRecord;
    onExtend: (row: EnrolmentRecord) => void;
    onProgress: (row: EnrolmentRecord) => void;
}) {
    const config = useAdminConfig();
    const percent = roundPercent(enrolment.progress_percent) ?? 0;
    return (
        <li className="vy-learner-course-card">
            <div className="vy-learner-course-top">
                <span className="vy-learner-course-icon">
                    <Icon icon={BookOpen} size={20} />
                </span>
                <div>
                    <button onClick={() => onProgress(enrolment)}>{enrolment.course.title}</button>
                    <small>
                        {s.enrolled} {formatDate(enrolment.enrolled_at, config.locale)} ·{' '}
                        {displayValue(enrolment.entitlement.source)}
                    </small>
                </div>
                <Badge
                    variant={
                        enrolment.status === 'completed' || enrolment.status === 'active' ? 'ok' : 'neutral'
                    }
                >
                    {enrolment.status === 'completed' && enrolment.completed_at
                        ? strings.completedOn(formatDate(enrolment.completed_at, config.locale) ?? '')
                        : strings.statuses[enrolment.status]}
                </Badge>
            </div>
            <div className="vy-learner-course-progress">
                <ProgressBar value={percent} label={strings.progressLabel(enrolment.course.title)} />
                <strong>
                    {enrolment.status === 'suspended' ? strings.kept(percent) : strings.progress(percent)}
                </strong>
            </div>
            <footer>
                <span>
                    {enrolment.entitlement.expires_at
                        ? uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: s.accessUntil, p1: formatDate(enrolment.entitlement.expires_at, config.locale) })
                        : strings.neverExpires}
                </span>
                <div>
                    <Button variant="ghost" size="sm" onClick={() => onProgress(enrolment)}>
                        {s.progressDetail}
                        <Icon icon={ArrowRight} size={14} />
                    </Button>
                    {enrolment.status === 'suspended' ? (
                        <Button variant="outline" size="sm" onClick={() => onExtend(enrolment)}>
                            {strings.reactivate}
                        </Button>
                    ) : (
                        <a
                            className={buttonVariants({ variant: 'outline', size: 'sm' })}
                            href={workbenchUrl(config, enrolment.course.id)}
                        >
                            {strings.open}
                        </a>
                    )}
                </div>
            </footer>
        </li>
    );
}

function CredentialCard({ certificate }: { certificate: CertificateRecord }) {
    const config = useAdminConfig();
    return (
        <article className="vy-learner-credential">
            <span className="vy-learner-credential-icon">
                <Icon icon={Award} size={29} />
            </span>
            <Badge
                variant={
                    certificate.status === 'active'
                        ? 'ok'
                        : certificate.status === 'revoked'
                          ? 'danger'
                          : 'neutral'
                }
            >
                {adminStrings.certificates.statuses[certificate.status] ?? displayValue(certificate.status)}
            </Badge>
            <h3>{certificate.course.title}</h3>
            <code>{certificate.code}</code>
            <dl>
                <div>
                    <dt>{s.issued}</dt>
                    <dd>{formatDate(certificate.issued_at, config.locale)}</dd>
                </div>
                <div>
                    <dt>{s.validFrom}</dt>
                    <dd>
                        {formatDate(certificate.valid_from, config.locale) ??
                            formatDate(certificate.issued_at, config.locale)}
                    </dd>
                </div>
                <div>
                    <dt>{s.expires}</dt>
                    <dd>{formatDate(certificate.expires_at, config.locale) ?? s.noExpiry}</dd>
                </div>
            </dl>
            {certificate.revocation_reason && <p>{certificate.revocation_reason}</p>}
            <footer>
                {certificate.verification_url && (
                    <a
                        className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        href={certificate.verification_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        {s.verify}
                        <Icon icon={ArrowUpRight} size={14} />
                    </a>
                )}
                {certificate.credential_url && (
                    <a
                        href={certificate.credential_url}
                        target="_blank"
                        rel="noreferrer"
                        className="text-xs text-accent"
                    >
                        {s.credential}
                    </a>
                )}
            </footer>
        </article>
    );
}

export interface LearnerRecordViewProps {
    userId: number;
    onBack?: () => void;
    backLabel?: string;
}

export function LearnerRecordView({ userId, onBack, backLabel }: LearnerRecordViewProps) {
    const config = useAdminConfig();
    const announce = useAnnounce();
    const record = useLearnerRecord(userId);
    const [hash, navigate] = useHashRoute();
    const requested = new URLSearchParams(hash.split('?')[1]).get('tab');
    const section: Section =
        requested && Object.hasOwn(s.tabs, requested) ? (requested as Section) : 'overview';
    const select = (tab: Section) => navigate(`/learner/${userId}${tab === 'overview' ? '' : `?tab=${tab}`}`);
    const [extending, setExtending] = useState<EnrolmentRecord | null>(null);
    const [progress, setProgress] = useState<EnrolmentRecord | null>(null);
    const [evidence, setEvidence] = useState<EnrolmentRecord | null>(null);
    const [grantOpen, setGrantOpen] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [courseSearch, setCourseSearch] = useState('');
    const [status, setStatus] = useState('all');
    const [credentialSearch, setCredentialSearch] = useState('');
    const [credentialStatus, setCredentialStatus] = useState('all');
    const back = onBack && (
        <Button variant="ghost" size="sm" onClick={onBack}>
            <Icon icon={ArrowLeft} size={15} />
            {backLabel ?? adminStrings.learners.back}
        </Button>
    );
    if (record.isPending)
        return (
            <div className="vy-learners-workspace">
                {back}
                <Skeleton className="h-40 w-full" />
                <Skeleton className="h-64 w-full" />
            </div>
        );
    if (record.isError)
        return (
            <div className="vy-learners-workspace">
                {back}
                <ScreenError error={record.error} onRetry={() => void record.refetch()} />
            </div>
        );
    const { user, enrolments, certificates } = record.data;
    const active = enrolments.filter((row) => row.status === 'active').length;
    const completed = enrolments.filter((row) => row.status === 'completed').length;
    const average = enrolments.length
        ? Math.round(enrolments.reduce((sum, row) => sum + row.progress_percent, 0) / enrolments.length)
        : 0;
    const lastActive = enrolments
        .map((row) => row.last_activity_at)
        .filter((date): date is string => date !== null)
        .sort()
        .at(-1);
    const firstEnrolled = enrolments.map((row) => row.enrolled_at).sort()[0];
    const filteredCourses = enrolments.filter(
        (row) =>
            (status === 'all' || row.status === status) &&
            row.course.title.toLowerCase().includes(courseSearch.trim().toLowerCase()),
    );
    const filteredCredentials = certificates.filter(
        (row) =>
            (credentialStatus === 'all' || row.status === credentialStatus) &&
            `${row.course.title} ${row.code}`.toLowerCase().includes(credentialSearch.trim().toLowerCase()),
    );
    const report = (text: string) => {
        setMessage(text);
        announce(text);
    };
    const transcript = () => {
        downloadFile(
            csvContent([
                [
                    'Learner ID',
                    'Learner',
                    'Email',
                    'Course',
                    'Enrolment status',
                    'Progress %',
                    'Enrolled',
                    'Completed',
                    'Last activity',
                    'Access source',
                    'Source reference',
                    'Access starts',
                    'Access expires',
                    'Certificate codes',
                ],
                ...enrolments.map((row) => [
                    user.id,
                    user.name,
                    user.email,
                    row.course.title,
                    row.status,
                    row.progress_percent,
                    row.enrolled_at,
                    row.completed_at,
                    row.last_activity_at,
                    row.entitlement.source,
                    row.entitlement.source_ref,
                    row.entitlement.starts_at,
                    row.entitlement.expires_at,
                    certificates
                        .filter((item) => item.course.id === row.course.id)
                        .map((item) => item.code)
                        .join('; '),
                ]),
            ]),
            `vidyalms-learner-${userId}-transcript.csv`,
        );
        announce(s.exported);
    };
    const context = (
        <dl className="vy-learner-facts">
            <div>
                <dt>{s.userId}</dt>
                <dd>#{user.id}</dd>
            </div>
            <div>
                <dt>{s.email}</dt>
                <dd>{user.email}</dd>
            </div>
            <div>
                <dt>{s.firstEnrolled}</dt>
                <dd>{formatDate(firstEnrolled, config.locale) ?? s.noDate}</dd>
            </div>
            <div>
                <dt>{s.lastActive}</dt>
                <dd>{formatAgo(lastActive, config.locale) ?? s.noDate}</dd>
            </div>
        </dl>
    );
    return (
        <div className="vy-learners-workspace vy-learner-profile">
            <div>{back}</div>
            <PageHeader
                title={user.name}
                eyebrow={s.profile}
                description={s.profileDescription}
                actions={
                    <>
                        <Button variant="outline" onClick={transcript} disabled={!enrolments.length}>
                            <Icon icon={Download} size={15} />
                            {s.exportTranscript}
                        </Button>
                        <Button onClick={() => setGrantOpen(true)}>
                            <Icon icon={Plus} size={16} />
                            {s.enrol}
                        </Button>
                    </>
                }
            />
            {message && (
                <AdminNotice
                    glyph="✓"
                    title={message}
                    action={
                        <Button variant="ghost" size="sm" onClick={() => setMessage(null)}>
                            {adminStrings.courses.dismiss}
                        </Button>
                    }
                />
            )}
            <section className="vy-learner-hero" aria-label={s.profileSummary(user.name)}>
                <LearnerAvatar name={user.name} id={user.id} large />
                <div className="vy-learner-identity">
                    <strong>{user.name}</strong>
                    <p>{strings.userMeta(user.id, user.email)}</p>
                    <small>
                        <Icon icon={Activity} size={14} />
                        {s.lastActive}: {formatAgo(lastActive, config.locale) ?? s.noDate}
                    </small>
                </div>
                <div className="vy-learner-overall">
                    <strong>{average}%</strong>
                    <span>{s.average}</span>
                    <ProgressBar value={average} label={s.average} />
                </div>
            </section>
            <LearnerSummary
                label={s.profileSummary(user.name)}
                items={[
                    { label: s.enrolments, value: enrolments.length, icon: BookOpen },
                    { label: s.completed, value: completed, icon: GraduationCap },
                    { label: s.active, value: active, icon: Activity },
                    {
                        label: s.credentials,
                        value: certificates.length,
                        detail: s.activeCredentials(
                            certificates.filter((row) => row.status === 'active').length,
                        ),
                        icon: Award,
                    },
                ]}
            />
            <Tabs
                label={s.tabLabel}
                className="vy-learner-tabs"
                current={`learner-${section}`}
                tabs={Object.entries(s.tabs).map(([id, label]) => ({ id: `learner-${id}`, label }))}
                onSelect={(id) => select(id.replace('learner-', '') as Section)}
            />
            <div
                role="tabpanel"
                id={`vy-tabpanel-learner-${section}`}
                aria-labelledby={`vy-tab-learner-${section}`}
            >
                {section === 'overview' && (
                    <div className="vy-learner-overview">
                        <LearnerPanel
                            title={s.currentLearning}
                            action={
                                <Button variant="ghost" size="sm" onClick={() => select('learning')}>
                                    {s.viewAll}
                                    <Icon icon={ArrowRight} size={14} />
                                </Button>
                            }
                        >
                            {enrolments.length ? (
                                <ul className="vy-learner-course-list">
                                    {enrolments.slice(0, 3).map((row) => (
                                        <EnrolmentCard
                                            key={row.id}
                                            enrolment={row}
                                            onExtend={setExtending}
                                            onProgress={setProgress}
                                        />
                                    ))}
                                </ul>
                            ) : (
                                <EmptyState title={strings.noEnrolments} />
                            )}
                        </LearnerPanel>
                        <div className="vy-learner-overview-aside">
                            <LearnerPanel title={s.learnerContext}>{context}</LearnerPanel>
                            <LearnerPanel
                                title={s.tabs.credentials}
                                action={
                                    <Button variant="ghost" size="sm" onClick={() => select('credentials')}>
                                        <Icon icon={ArrowUpRight} size={15} />
                                        <span className="sr-only">{s.tabs.credentials}</span>
                                    </Button>
                                }
                            >
                                {certificates.length ? (
                                    <ul className="vy-learner-credential-summary">
                                        {certificates.slice(0, 3).map((item) => (
                                            <li key={item.id}>
                                                <Icon icon={Award} size={21} />
                                                <div>
                                                    <strong>{item.course.title}</strong>
                                                    <small>{item.code}</small>
                                                </div>
                                                <Badge variant={item.status === 'active' ? 'ok' : 'neutral'}>
                                                    {adminStrings.certificates.statuses[item.status]}
                                                </Badge>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <EmptyState title={strings.noCertificates} />
                                )}
                            </LearnerPanel>
                        </div>
                    </div>
                )}
                {section === 'learning' && (
                    <LearnerPanel title={s.tabs.learning} description={s.retained}>
                        <div className="vy-learner-toolbar">
                            <FilterSearch
                                label={s.searchCourses}
                                value={courseSearch}
                                onValueChange={setCourseSearch}
                                placeholder={s.searchCourses}
                            />
                            <LearnerFilter
                                label={uiText("vidyalms.ui.acbf16ecf57ffbc8", "Enrolment status")}
                                value={status}
                                onValueChange={setStatus}
                                options={[
                                    { value: 'all', label: s.allStatuses },
                                    ...Object.entries(strings.statuses).map(([value, label]) => ({
                                        value,
                                        label,
                                    })),
                                ]}
                            />
                        </div>
                        {filteredCourses.length ? (
                            <ul className="vy-learner-course-list">
                                {filteredCourses.map((row) => (
                                    <EnrolmentCard
                                        key={row.id}
                                        enrolment={row}
                                        onExtend={setExtending}
                                        onProgress={setProgress}
                                    />
                                ))}
                            </ul>
                        ) : (
                            <EmptyState title={enrolments.length ? s.noMatches : strings.noEnrolments} />
                        )}
                    </LearnerPanel>
                )}
                {section === 'assessments' && <AssessmentsPanel userId={userId} enrolments={enrolments} />}
                {section === 'credentials' && (
                    <LearnerPanel title={s.tabs.credentials}>
                        <div className="vy-learner-toolbar">
                            <FilterSearch
                                label={s.searchCredentials}
                                value={credentialSearch}
                                onValueChange={setCredentialSearch}
                                placeholder={s.searchCredentials}
                            />
                            <LearnerFilter
                                label={uiText("vidyalms.ui.ced60a751fc82211", "Credential status")}
                                value={credentialStatus}
                                onValueChange={setCredentialStatus}
                                options={[
                                    { value: 'all', label: s.allCredentials },
                                    ...Object.entries(adminStrings.certificates.statuses).map(
                                        ([value, label]) => ({ value, label }),
                                    ),
                                ]}
                            />
                        </div>
                        {filteredCredentials.length ? (
                            <div className="vy-learner-credential-grid">
                                {filteredCredentials.map((row) => (
                                    <CredentialCard key={row.id} certificate={row} />
                                ))}
                            </div>
                        ) : (
                            <EmptyState title={certificates.length ? s.noMatches : strings.noCertificates} />
                        )}
                    </LearnerPanel>
                )}
                {section === 'activity' && <ActivityPanel userId={userId} enrolments={enrolments} />}
                {section === 'access' && (
                    <div className="vy-learner-access">
                        <LearnerPanel title={s.account} description={s.accountHint}>
                            <dl className="vy-learner-facts">
                                <div>
                                    <dt>{s.name}</dt>
                                    <dd>{user.name}</dd>
                                </div>
                                <div>
                                    <dt>{s.email}</dt>
                                    <dd>{user.email}</dd>
                                </div>
                                <div>
                                    <dt>{s.userId}</dt>
                                    <dd>#{user.id}</dd>
                                </div>
                            </dl>
                        </LearnerPanel>
                        <LearnerPanel title={s.accessTitle} description={s.accessDescription}>
                            {enrolments.length ? (
                                <ul className="vy-learner-access-list">
                                    {enrolments.map((row) => (
                                        <li key={row.id}>
                                            <header>
                                                <Icon icon={ShieldCheck} size={20} />
                                                <strong>{row.course.title}</strong>
                                                <Badge variant={row.status === 'active' ? 'ok' : 'neutral'}>
                                                    {strings.statuses[row.status]}
                                                </Badge>
                                            </header>
                                            <dl className="vy-learner-facts">
                                                <div>
                                                    <dt>{s.accessSource}</dt>
                                                    <dd>
                                                        {displayValue(row.entitlement.source)}
                                                        {row.entitlement.source_ref
                                                            ? uiText("vidyalms.ui.04420b9dfe3f0a87", " · {p0}", { p0: row.entitlement.source_ref })
                                                            : ''}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt>{s.accessFrom}</dt>
                                                    <dd>
                                                        {formatDate(
                                                            row.entitlement.starts_at,
                                                            config.locale,
                                                        ) ?? s.noDate}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt>{s.accessUntil}</dt>
                                                    <dd>
                                                        {formatDate(
                                                            row.entitlement.expires_at,
                                                            config.locale,
                                                        ) ?? s.noExpiry}
                                                    </dd>
                                                </div>
                                            </dl>
                                            <footer>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setEvidence(row)}
                                                >
                                                    {s.evidence}
                                                </Button>
                                                {(row.status === 'suspended' || row.status === 'active') && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => setExtending(row)}
                                                    >
                                                        {row.status === 'suspended'
                                                            ? strings.reactivate
                                                            : s.extend}
                                                    </Button>
                                                )}
                                            </footer>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <EmptyState title={strings.noEnrolments} />
                            )}
                        </LearnerPanel>
                    </div>
                )}
            </div>
            {extending && (
                <ExtendDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) setExtending(null);
                    }}
                    entitlementId={extending.entitlement.id}
                    learnerName={user.name}
                    courseTitle={extending.course.title}
                    currentExpiry={extending.entitlement.expires_at}
                    locale={config.locale}
                    submitLabel={extending.status === 'suspended' ? strings.reactivate : s.extend}
                    onDone={report}
                />
            )}
            {progress && (
                <ProgressDialog
                    key={progress.id}
                    userId={userId}
                    enrolment={progress}
                    onClose={() => setProgress(null)}
                />
            )}
            {evidence && (
                <EvidenceDialog
                    key={evidence.id}
                    userId={userId}
                    enrolment={evidence}
                    onClose={() => setEvidence(null)}
                />
            )}
            {grantOpen && (
                <GrantAccessDialog open learner={user} onOpenChange={setGrantOpen} onGranted={report} />
            )}
        </div>
    );
}
