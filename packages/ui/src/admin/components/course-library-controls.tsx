import { ArrowDown, ArrowUp, ChevronDown, FolderTree, LayoutGrid, List, X } from 'lucide-react';
import { Button, CourseCategoryPicker, Icon, Spinner, type DataTableSort } from '@/shared/components';
import { translate as uiText } from '@/shared/i18n';
import { studioStrings as studio } from '@/instructor/studio-strings';
import { adminStrings } from '../strings';
import { FilterChips, type FilterChip } from './filter-chips';
import { FilterSearch } from './filter-toolbar';

export function CourseLibraryControls({
    search, onSearch, filter, onFilter, chips, categoryId, onCategory,
    sort, onSort, view, onView, fetching, onReset,
}: {
    search: string; onSearch: (value: string) => void;
    filter: string; onFilter: (value: string) => void; chips: FilterChip[];
    categoryId: number | null; onCategory: (value: number | null) => void;
    sort: DataTableSort; onSort: (value: DataTableSort) => void;
    view: 'list' | 'grid'; onView: (value: 'list' | 'grid') => void;
    fetching: boolean; onReset: () => void;
}) {
    const filtered = filter !== 'all' || search.trim() !== '' || categoryId !== null;
    return (
        <div className="vy-course-controls">
            <div className="vy-course-filter-row">
                <FilterChips className="vy-course-status-tabs" label={adminStrings.list.filters}
                    value={filter} onChange={onFilter} chips={chips} />
                {filtered && <Button variant="ghost" size="sm" onClick={onReset}>
                    <Icon icon={X} size={14} />{uiText('vidyalms.ui.7179ea0035fca783', 'Clear filters')}
                </Button>}
            </div>
            <div className="vy-course-tools" role="group" aria-label={adminStrings.list.toolbar(studio.allCourses)}>
                <div className="vy-course-search">
                    <FilterSearch label={adminStrings.list.search} placeholder={adminStrings.courses.searchPlaceholder}
                        value={search} onValueChange={onSearch} />
                </div>
                <details className="vy-course-category-filter">
                    <summary><Icon icon={FolderTree} size={16} />{studio.categoryFilter}
                        {categoryId !== null && <span className="vy-course-filter-dot" />}
                        <Icon icon={ChevronDown} size={14} />
                    </summary>
                    <div className="vy-course-category-panel">
                        <CourseCategoryPicker label={studio.categoryFilter} value={categoryId} onChange={onCategory} />
                    </div>
                </details>
                <div className="vy-course-view-tools">
                    {fetching && <Spinner label={adminStrings.list.loading} />}
                    <div className="vy-course-sort-controls">
                        <select aria-label={studio.sort} value={sort.key}
                            onChange={(event) => onSort({ key: event.target.value, order: sort.order })}>
                            {Object.entries(studio.sorts).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </select>
                        <Button variant="ghost" aria-label={sort.order === 'asc' ? studio.ascending : studio.descending}
                            title={sort.order === 'asc' ? studio.ascending : studio.descending}
                            onClick={() => onSort({ ...sort, order: sort.order === 'asc' ? 'desc' : 'asc' })}>
                            <Icon icon={sort.order === 'asc' ? ArrowUp : ArrowDown} size={16} />
                        </Button>
                    </div>
                    <div className="vy-course-view-switch">
                        <Button variant="ghost" aria-label={adminStrings.courses.listView} title={adminStrings.courses.listView}
                            aria-pressed={view === 'list'} onClick={() => onView('list')}><Icon icon={List} size={17} /></Button>
                        <Button variant="ghost" aria-label={adminStrings.courses.gridView} title={adminStrings.courses.gridView}
                            aria-pressed={view === 'grid'} onClick={() => onView('grid')}><Icon icon={LayoutGrid} size={17} /></Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
