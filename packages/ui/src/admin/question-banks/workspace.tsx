import { translate as uiText } from '@/shared/i18n';
import type { ReactNode } from 'react';
import { ArrowLeft, FolderOpen, type LucideIcon } from 'lucide-react';
import { Icon, Pagination } from '@/shared/components';
import { FilterSearch, FilterSelect, type FilterSelectProps } from '../components/filter-toolbar';
import type { ListFrameProps } from '../components/list-frame';
import { pageRange } from '../lib';
import { bankStrings as strings } from './strings';

export function BankSummary({
    items,
}: {
    items: { label: string; value: ReactNode; detail: string; icon: LucideIcon }[];
}) {
    return (
        <dl className="vy-bank-summary" aria-label={strings.workspace.summary}>
            {items.map(({ label, value, detail, icon }, index) => (
                <div key={label}>
                    <span className={`vy-bank-stat-icon vy-bank-tone-${index}`}>
                        <Icon icon={icon} size={19} />
                    </span>
                    <dt>{label}</dt>
                    <dd>{value}</dd>
                    <small>{detail}</small>
                </div>
            ))}
        </dl>
    );
}

export function BankFilter(props: FilterSelectProps) {
    return (
        <div className="vy-bank-labeled-filter">
            <span aria-hidden="true">{props.label}</span>
            <FilterSelect {...props} />
        </div>
    );
}

/** The library's two-row toolbar, with page controls kept beside the result count. */
export function BankCollection({
    title,
    meta,
    search,
    chips,
    action,
    banner,
    children,
    note,
    page,
    perPage,
    total,
    shownCount,
    onPageChange,
    footerAction,
}: ListFrameProps & { footerAction?: ReactNode }) {
    return (
        <section className="vy-bank-collection" aria-label={title}>
            <h2 className="sr-only">{title}</h2>
            <div
                className="vy-bank-collection-toolbar"
                role="search"
                aria-label={uiText("vidyalms.ui.fd837c88da6a8dfd", "{p0} search and filters", { p0: title })}
            >
                <div className="vy-bank-collection-top">
                    {search && (
                        <FilterSearch
                            label={search.label}
                            placeholder={search.placeholder}
                            value={search.value}
                            onValueChange={search.onChange}
                        />
                    )}
                    <div className="vy-bank-collection-actions">{action}</div>
                </div>
                {chips && <div className="vy-bank-collection-filters">{chips}</div>}
            </div>
            {banner && <div className="vy-bank-collection-banner">{banner}</div>}
            {children}
            <footer className="vy-bank-collection-footer">
                <span aria-live="polite">{pageRange(page, perPage, total, shownCount)}</span>
                <div>
                    {footerAction}
                    <Pagination
                        page={page}
                        perPage={perPage}
                        total={total}
                        onPageChange={onPageChange}
                        label={uiText("vidyalms.ui.78e681cd67d0e046", "{p0} pages", { p0: title })}
                    />
                </div>
            </footer>
            {note && <p className="vy-bank-collection-note">{note}</p>}
            {meta && <span className="sr-only">{meta}</span>}
        </section>
    );
}

export function BankNavigation({
    bankId,
    active,
    navigate,
}: {
    bankId: number;
    active: 'questions' | 'settings';
    navigate: (path: string) => void;
}) {
    return (
        <nav className="vy-bank-navigation" aria-label={strings.workspace.navigation}>
            <button type="button" onClick={() => navigate('/')}>
                <Icon icon={ArrowLeft} size={15} />
                {strings.questions.back}
            </button>
            <span aria-hidden="true" />
            <button
                type="button"
                aria-current={active === 'questions' ? 'page' : undefined}
                onClick={() => navigate(`/banks/${bankId}/questions`)}
            >
                <Icon icon={FolderOpen} size={15} />
                {strings.questions.table}
            </button>
            <button
                type="button"
                aria-current={active === 'settings' ? 'page' : undefined}
                onClick={() => navigate(`/banks/${bankId}`)}
            >
                {strings.questions.editBank}
            </button>
        </nav>
    );
}

export function BankEmpty({
    filtered,
    onReset,
    onCreate,
    question = false,
}: {
    filtered: boolean;
    onReset: () => void;
    onCreate: () => void;
    question?: boolean;
}) {
    return (
        <div className="vy-bank-empty">
            <span className="vy-bank-empty-icon">
                <Icon icon={FolderOpen} size={28} />
            </span>
            <h3>
                {filtered
                    ? strings.workspace.noMatches
                    : question
                      ? strings.workspace.firstQuestion
                      : strings.workspace.firstBank}
            </h3>
            <p>
                {filtered
                    ? strings.workspace.noMatchesHint
                    : question
                      ? strings.workspace.firstQuestionHint
                      : strings.workspace.firstBankHint}
            </p>
            <button type="button" className="vy-bank-text-action" onClick={filtered ? onReset : onCreate}>
                {filtered
                    ? strings.workspace.clearFilters
                    : question
                      ? strings.questions.add
                      : strings.list.new}
            </button>
        </div>
    );
}
