import { translate as uiText } from '@/shared/i18n';
import { useEffect, useState } from 'react';
import {
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorState,
    Skeleton,
    type DataTableColumn,
} from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import type { CourseCredit, CreditScheme } from '@vidyalms/api-sdk';
import { EnrolmentCoursePicker, type CourseSelection } from '../learners/course-picker';
import { StudioDraftProvider, useStudioDraft, useStudioNavigation } from './studio-drafts';
import { CredentialIntro } from './workspace';
import { certificateWorkspaceStrings as ws } from './workspace-strings';
import { SelectField } from '../components/field';
import { AdminNotice, ScreenError } from '../components/states';
import { credentialStrings } from '../credential-strings';
import { ElementSettingsForm, seedElementPayload, type ElementPayload } from './element-settings';
import { useCourseCredits, useCreditSchemes, useSetCourseCredits } from './api';

/*
 * Continuing-education credit, which is a child table and never a
 * certificate element.
 *
 * The cross-scheme facts (amount, unit, contact minutes, field of study,
 * awarding body) are real columns because compliance reporting groups by
 * scheme and reporting date and the retention obligations mean these rows
 * are queried years after issue. What is scheme-specific goes in the
 * scheme's own versioned parameters, which is why this screen draws the
 * scheme's settings schema and knows no scheme key of its own.
 *
 * A course is where an author records the credit; the certificate freezes it
 * at issue inside the same transaction as the certificate insert, because a
 * credit row without its certificate is a compliance defect.
 */

const strings = credentialStrings.credits;

export function CreditsTab({ onDirtyChange }: { onDirtyChange?: (dirty: boolean) => void } = {}) {
    return (
        <StudioDraftProvider onDirtyChange={onDirtyChange}>
            <CreditsWorkspace />
        </StudioDraftProvider>
    );
}

function CreditsWorkspace() {
    const navigation = useStudioNavigation();
    const announce = useAnnounce();
    const schemes = useCreditSchemes();
    const [course, setCourse] = useState<CourseSelection | null>(null);
    const courseId = course?.id ?? null;
    const [message, setMessage] = useState<string | null>(null);

    if (schemes.isError) {
        return <ScreenError error={schemes.error} onRetry={() => void schemes.refetch()} />;
    }

    const items = schemes.data?.items ?? [];

    return (
        <div className="vy-cert-credits grid gap-4">
            <CredentialIntro title={ws.creditsTitle} description={ws.creditsHint} />
            {message !== null && (
                <AdminNotice
                    glyph="✓"
                    role="status"
                    title={message}
                    action={
                        <Button variant="ghost" size="sm" onClick={() => setMessage(null)}>
                            {credentialStrings.form.close}
                        </Button>
                    }
                />
            )}

            <section
                aria-labelledby="vy-credit-schemes"
                className="grid gap-2.5 rounded-lg border border-border bg-surface p-4"
            >
                <h3 id="vy-credit-schemes" className="font-display text-xs font-bold text-fg">
                    {strings.schemes}
                </h3>
                <p className="text-[11.5px] text-fg-2">{strings.schemesHint}</p>
                {schemes.isPending ? <Skeleton className="h-24 w-full" /> : <SchemeTable schemes={items} />}
            </section>

            <section
                aria-labelledby="vy-credit-course"
                className="grid gap-2.5 rounded-lg border border-border bg-surface p-4"
            >
                <h3 id="vy-credit-course" className="font-display text-xs font-bold text-fg">
                    {strings.courseHeading}
                </h3>
                <p className="text-[11.5px] text-fg-2">{strings.courseHint}</p>
                <EnrolmentCoursePicker
                    searchLabel={ws.courseSearch}
                    value={course}
                    onChange={(next) => navigation.requestLeave(() => setCourse(next))}
                />
                {courseId === null ? (
                    <p className="text-[12px] text-fg-2">{strings.noCourse}</p>
                ) : (
                    <CourseCreditEditor
                        key={courseId}
                        courseId={courseId}
                        schemes={items}
                        onDone={(text) => {
                            setMessage(text);
                            announce(text);
                        }}
                    />
                )}
            </section>
        </div>
    );
}

function SchemeTable({ schemes }: { schemes: readonly CreditScheme[] }) {
    const columns: DataTableColumn<CreditScheme>[] = [
        {
            key: 'scheme',
            header: strings.schemeColumns.scheme,
            weight: 1,
            cell: (row) => (
                <span className="flex flex-wrap items-center gap-1.5">
                    <span className="text-fg">{row.label}</span>
                    <code className="font-mono text-[11px] text-fg-2">{row.key}</code>
                    {!row.available && <Badge variant="warn">{strings.notInstalled}</Badge>}
                </span>
            ),
        },
        { key: 'unit', header: strings.schemeColumns.unit, width: '110px', cell: (row) => row.unit },
        {
            key: 'retention',
            header: strings.schemeColumns.retention,
            width: '140px',
            cell: (row) => (
                <span className="text-fg-2">
                    {row.retention_years === null
                        ? strings.retentionUnstated
                        : strings.retentionYears(row.retention_years)}
                </span>
            ),
        },
        {
            key: 'fields',
            header: strings.schemeColumns.fields,
            weight: 1,
            cell: (row) => (
                <span className="text-fg-2">
                    {row.required_learner_fields.length === 0
                        ? strings.noLearnerFields
                        : row.required_learner_fields.join(', ')}
                </span>
            ),
        },
    ];

    return (
        <DataTable
            caption={strings.schemes}
            columns={columns}
            rows={[...schemes]}
            rowKey={(row) => row.key}
            empty={<EmptyState title={strings.emptyAll} />}
        />
    );
}

interface CreditDraft {
    scheme: string;
    params: ElementPayload;
    params_version: number;
    derived: CourseCredit['derived'];
    available: boolean;
    label: string;
}

function toDraft(row: CourseCredit, schemes: readonly CreditScheme[]): CreditDraft {
    const scheme = schemes.find((candidate) => candidate.key === row.scheme) ?? null;
    return {
        scheme: row.scheme,
        params: seedElementPayload(scheme?.groups ?? [], row.params),
        params_version: row.params_version,
        derived: row.derived,
        available: row.available,
        label: row.scheme_label ?? scheme?.label ?? row.scheme,
    };
}

function CourseCreditEditor({
    courseId,
    schemes,
    onDone,
}: {
    courseId: number;
    schemes: readonly CreditScheme[];
    onDone: (message: string) => void;
}) {
    const credits = useCourseCredits(courseId);
    const save = useSetCourseCredits();
    const [rows, setRows] = useState<CreditDraft[]>([]);
    const [dirty, setDirty] = useState(false);
    const markDraft = useStudioDraft(`course-credit-${courseId}`);
    const editRows = (next: CreditDraft[]) => {
        setRows(next);
        setDirty(true);
        markDraft(true);
    };

    useEffect(() => {
        if (!dirty) setRows((credits.data?.items ?? []).map((row) => toDraft(row, schemes)));
    }, [credits.data, schemes, dirty]);

    if (credits.isPending) {
        return <Skeleton className="h-24 w-full" />;
    }
    if (credits.isError) {
        return <ScreenError error={credits.error} onRetry={() => void credits.refetch()} />;
    }

    const unused = schemes.filter((scheme) => !rows.some((row) => row.scheme === scheme.key));

    const add = (key: string) => {
        const scheme = schemes.find((candidate) => candidate.key === key);
        if (scheme === undefined) {
            return;
        }
        editRows([
            ...rows,
            {
                scheme: scheme.key,
                params: seedElementPayload(scheme.groups, {}),
                params_version: 1,
                derived: null,
                available: scheme.available,
                label: scheme.label,
            },
        ]);
    };

    const submit = async () => {
        try {
            await save.mutateAsync({
                courseId,
                body: {
                    items: rows.map((row) => ({
                        scheme: row.scheme,
                        params: row.params,
                        params_version: row.params_version,
                    })),
                },
            });
        } catch {
            return;
        }
        setDirty(false);
        markDraft(false);
        onDone(strings.saved);
    };

    return (
        <div className="grid gap-3">
            {rows.length === 0 && <p className="text-[12px] text-fg-2">{strings.empty}</p>}
            {rows.map((row, index) => {
                const scheme = schemes.find((candidate) => candidate.key === row.scheme) ?? null;
                return (
                    <div key={row.scheme} className="grid gap-2.5 rounded border border-border p-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="font-display text-xs font-bold text-fg">{row.label}</p>
                            <code className="font-mono text-[11px] text-fg-2">{row.scheme}</code>
                            {!row.available && <Badge variant="warn">{strings.notInstalled}</Badge>}
                            <Button
                                className="ml-auto"
                                variant="ghost"
                                size="sm"
                                aria-label={strings.removeSchemeNamed(row.label)}
                                disabled={save.isPending}
                                onClick={() => editRows(rows.filter((_, i) => i !== index))}
                            >
                                {strings.removeScheme}
                            </Button>
                        </div>
                        {scheme !== null && scheme.rule !== undefined && scheme.rule !== null && (
                            <p className="text-[11.5px] text-fg-2">{scheme.rule}</p>
                        )}
                        <ElementSettingsForm
                            groups={scheme?.groups ?? []}
                            values={row.params}
                            disabled={!row.available || save.isPending}
                            onChange={(params) =>
                                editRows(
                                    rows.map((candidate, i) =>
                                        i === index ? { ...candidate, params } : candidate,
                                    ),
                                )
                            }
                        />
                        <p className="text-[11.5px] text-fg-2">
                            <span className="font-semibold text-fg">{strings.derived}</span>{' '}
                            {!row.available
                                ? strings.derivedNone
                                : dirty || row.derived === null
                                  ? ws.computeAfterSave
                                : uiText("vidyalms.ui.380e3dbd6730acee", "{p0}{p1}", { p0: strings.amount(row.derived.amount ?? '0', row.derived.unit ?? scheme?.unit ?? ''), p1: row.derived.contact_minutes === undefined ||
                                      row.derived.contact_minutes === null
                                          ? ''
                                          : `, ${strings.contactMinutes(row.derived.contact_minutes)}` })}
                        </p>
                    </div>
                );
            })}

            {unused.length > 0 && (
                <SelectField
                    label={strings.addScheme}
                    disabled={save.isPending}
                    value=""
                    onChange={(event) => {
                        if (event.target.value !== '') {
                            add(event.target.value);
                        }
                    }}
                >
                    <option value="">{strings.addScheme}</option>
                    {unused.map((scheme) => (
                        <option key={scheme.key} value={scheme.key}>
                            {scheme.label}
                        </option>
                    ))}
                </SelectField>
            )}

            {save.isError && <ErrorState error={save.error} />}

            <div className="flex justify-end">
                <Button
                    variant="primary"
                    size="sm"
                    disabled={save.isPending}
                    onClick={() => {
                        void submit();
                    }}
                >
                    {save.isPending ? strings.saving : strings.save}
                </Button>
            </div>
        </div>
    );
}
