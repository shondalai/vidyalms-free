import { useEffect, useState } from 'react';
import {
    ArrowRight,
    Building2,
    CheckCircle2,
    Download,
    LayoutGrid,
    List,
    PauseCircle,
    Plus,
} from 'lucide-react';
import type { Organisation, OwnOrganisation } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorState,
    Icon,
    Pagination,
    Skeleton,
    type DataTableColumn,
} from '@/shared/components';
import { ProblemError } from '@/shared/api';
import { useDebounced } from '../hooks';
import { PageHeader } from '../components/page-header';
import { FilterSearch, FilterSelect } from '../components/filter-toolbar';
import { pageRange } from '../lib';
import { csvContent, downloadFile } from '../learners/workspace';
import { useOrganisations, useOwnOrganisations } from './api';
import { organisationHash, type OrganisationLocation } from './routes';
import { organisationStrings as strings } from './strings';
import { orgWorkspaceStrings as s } from './workspace-strings';
import { OrgAvatar, OrgStats, orgDate, orgType } from './workspace';
import { CreateOrganisation } from './onboarding';

export function Directory({
    location,
    navigate,
}: {
    location: OrganisationLocation;
    navigate: (hash: string) => void;
}) {
    const [creating, setCreating] = useState(false);
    const search = useDebounced(location.search);
    const query = useOrganisations(
        {
            page: location.page,
            per_page: 20,
            search: search || undefined,
            state: ['active', 'suspended'].includes(location.state)
                ? (location.state as Organisation['state'])
                : undefined,
        },
        !location.mine,
    );
    const delegated = query.error instanceof ProblemError && query.error.status === 403;
    const mine = location.mine || delegated;
    const own = useOwnOrganisations(location.page, mine);
    const result = mine ? own : query;
    const rows: (Organisation | OwnOrganisation)[] = result.data?.items ?? [];
    const change = (changes: Partial<OrganisationLocation>) => navigate(organisationHash(location, changes));
    const open = (id: number) => change({ id, tab: 'overview', page: 1, state: '', search: '' });
    const total = result.data?.total ?? 0;
    const lastPage = Math.max(1, Math.ceil(total / 20));
    useEffect(() => {
        if (result.isSuccess && !result.isFetching && location.page > lastPage) {
            navigate(organisationHash(location, { page: lastPage }));
        }
    }, [result.isSuccess, result.isFetching, location, lastPage, navigate]);

    const columns: DataTableColumn<Organisation | OwnOrganisation>[] = [
        {
            key: 'name',
            header: strings.name,
            weight: 3,
            minWidth: 260,
            cell: (row) => (
                <div className="vy-org-name-cell">
                    <OrgAvatar name={row.name} id={row.id} />
                    <div>
                        <button className="vy-org-name-link" onClick={() => open(row.id)}>
                            {row.name}
                        </button>
                        <p>{row.slug}</p>
                    </div>
                </div>
            ),
        },
        {
            key: 'type',
            header: s.type,
            weight: 1,
            minWidth: 160,
            cell: (row) => ('type' in row ? orgType(row.type, row.type_unresolved) : '—'),
        },
        {
            key: 'state',
            header: strings.state,
            width: '140px',
            cell: (row) => (
                <Badge variant={row.state === 'active' ? 'accent' : 'warn'}>
                    {row.state === 'active' ? strings.active : strings.suspended}
                </Badge>
            ),
        },
        {
            key: 'created',
            header: s.created,
            width: '150px',
            cell: (row) => ('created_at' in row ? orgDate(row.created_at) : '—'),
        },
        {
            key: 'actions',
            header: strings.actions,
            width: '180px',
            cell: (row) => (
                <Button variant="ghost" size="sm" onClick={() => open(row.id)}>
                    {strings.open}
                    <Icon icon={ArrowRight} size={15} />
                </Button>
            ),
        },
    ];
    const exportPage = () =>
        downloadFile(
            csvContent([
                ['ID', strings.name, s.address, strings.state, s.type, s.created],
                ...rows.map((row) => [
                    row.id,
                    row.name,
                    row.slug,
                    row.state,
                    'type' in row ? row.type : '',
                    'created_at' in row ? row.created_at : '',
                ]),
            ]),
            `organizations-page-${location.page}.csv`,
        );

    return (
        <div className="vy-org-workspace">
            <PageHeader
                title={mine ? strings.mine : strings.title}
                eyebrow={s.eyebrow}
                description={s.directoryIntro}
                actions={
                    <>
                        <Button
                            variant="outline"
                            disabled={!rows.length || result.isFetching || result.isError}
                            onClick={exportPage}
                        >
                            <Icon icon={Download} size={16} />
                            {s.exportPage}
                        </Button>
                        {!mine && query.isSuccess && (
                            <Button onClick={() => setCreating(true)}>
                                <Icon icon={Plus} size={17} />
                                {strings.create}
                            </Button>
                        )}
                    </>
                }
            />
            <OrgStats
                items={[
                    {
                        label: s.matching,
                        value: result.data ? total.toLocaleString() : '—',
                        icon: Building2,
                    },
                    {
                        label: s.activePage,
                        value: result.data ? rows.filter((row) => row.state === 'active').length : '—',
                        icon: CheckCircle2,
                    },
                    {
                        label: s.suspendedPage,
                        value: result.data ? rows.filter((row) => row.state === 'suspended').length : '—',
                        icon: PauseCircle,
                    },
                ]}
            />
            <section aria-label={s.directory} className="vy-org-directory">
                <div className="vy-org-toolbar">
                    {!mine && (
                        <>
                            <FilterSearch
                                label={strings.search}
                                placeholder={strings.search}
                                value={location.search}
                                onValueChange={(search) => change({ search, page: 1 })}
                            />
                            <FilterSelect
                                label={strings.state}
                                value={location.state}
                                options={[
                                    { value: '', label: strings.allStates },
                                    { value: 'active', label: strings.active },
                                    { value: 'suspended', label: strings.suspended },
                                ]}
                                onValueChange={(state) => change({ state, page: 1 })}
                            />
                            {(location.search || location.state) && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => change({ search: '', state: '', page: 1 })}
                                >
                                    {s.clear}
                                </Button>
                            )}
                        </>
                    )}
                    <div className="vy-org-view-switch" role="group" aria-label={s.directory}>
                        <Button
                            variant={location.layout === 'cards' ? 'outline' : 'ghost'}
                            size="sm"
                            aria-pressed={location.layout === 'cards'}
                            aria-label={s.cards}
                            onClick={() => change({ layout: 'cards' })}
                        >
                            <Icon icon={LayoutGrid} size={17} />
                        </Button>
                        <Button
                            variant={location.layout === 'table' ? 'outline' : 'ghost'}
                            size="sm"
                            aria-pressed={location.layout === 'table'}
                            aria-label={s.table}
                            onClick={() => change({ layout: 'table' })}
                        >
                            <Icon icon={List} size={17} />
                        </Button>
                    </div>
                </div>
                {result.isError ? (
                    <ErrorState
                        error={result.error}
                        onRetry={() => {
                            void result.refetch();
                        }}
                    />
                ) : result.isPending ? (
                    <div className="vy-org-cards">
                        {[1, 2, 3].map((key) => (
                            <Skeleton key={key} className="h-64 w-full" />
                        ))}
                    </div>
                ) : rows.length === 0 ? (
                    <div className="vy-org-panel">
                        <EmptyState
                            title={strings.empty}
                            action={
                                !mine && !location.search && !location.state ? (
                                    <Button onClick={() => setCreating(true)}>{s.firstOrganization}</Button>
                                ) : undefined
                            }
                        />
                    </div>
                ) : location.layout === 'table' ? (
                    <div className="vy-org-table">
                        <DataTable
                            caption={s.directory}
                            rows={rows}
                            rowKey={(row) => row.id}
                            columns={columns}
                        />
                    </div>
                ) : (
                    <div className="vy-org-cards">
                        {rows.map((row) => (
                            <article key={row.id} className="vy-org-card">
                                <div className="vy-org-card-top">
                                    <OrgAvatar name={row.name} id={row.id} large />
                                    <Badge variant={row.state === 'active' ? 'accent' : 'warn'}>
                                        {row.state === 'active' ? strings.active : strings.suspended}
                                    </Badge>
                                </div>
                                <h2>
                                    <button className="vy-org-name-link" onClick={() => open(row.id)}>
                                        {row.name}
                                    </button>
                                </h2>
                                <p className="vy-org-slug">{row.slug}</p>
                                <dl className="vy-org-card-facts">
                                    <div>
                                        <dt>{s.type}</dt>
                                        <dd>
                                            {'type' in row ? orgType(row.type, row.type_unresolved) : '—'}
                                        </dd>
                                    </div>
                                    {'created_at' in row && (
                                        <div>
                                            <dt>{s.created}</dt>
                                            <dd>{orgDate(row.created_at)}</dd>
                                        </div>
                                    )}
                                </dl>
                                <footer>
                                    <Button variant="ghost" onClick={() => open(row.id)}>
                                        {strings.open}
                                        <Icon icon={ArrowRight} size={16} />
                                    </Button>
                                </footer>
                            </article>
                        ))}
                    </div>
                )}
                {result.data && !result.isError && (
                    <footer className="vy-org-pagination">
                        <span aria-live="polite">{pageRange(location.page, 20, total, rows.length)}</span>
                        <Pagination
                            label={s.directory}
                            page={location.page}
                            perPage={20}
                            total={total}
                            onPageChange={(page) => change({ page })}
                        />
                    </footer>
                )}
            </section>
            {creating && (
                <CreateOrganisation
                    close={() => setCreating(false)}
                    created={(id) => {
                        setCreating(false);
                        open(id);
                    }}
                />
            )}
        </div>
    );
}
