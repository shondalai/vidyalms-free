import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { BookOpen } from 'lucide-react';
import { CompactStats } from './compact-stats';
import { FilterSearch, FilterSelect, FilterToolbar } from './filter-toolbar';
import { PageHeader } from './page-header';

describe('Stage 1 admin primitives', () => {
    it('renders one named, compact metric collection', () => {
        render(
            <CompactStats
                label="Course summary"
                items={[
                    { id: 'published', label: 'Published', value: 18, detail: '3 this month', icon: BookOpen },
                    { id: 'drafts', label: 'Drafts', value: 4 },
                ]}
            />,
        );

        const summary = screen.getByRole('list', { name: 'Course summary' });
        expect(summary).toHaveClass('gap-2.5');
        expect(screen.getByText('Published')).toBeInTheDocument();
        expect(screen.getByText('18')).toBeInTheDocument();
        expect(screen.getByText('3 this month')).toBeInTheDocument();
    });

    it('keeps search, filters, and actions in one named toolbar', async () => {
        const onSearch = vi.fn();
        const onFilter = vi.fn();
        render(
            <FilterToolbar
                label="Course search and filters"
                search={
                    <FilterSearch
                        label="Search courses"
                        value=""
                        onValueChange={onSearch}
                        placeholder="Search courses"
                    />
                }
                filters={
                    <FilterSelect
                        label="State"
                        value="all"
                        onValueChange={onFilter}
                        options={[
                            { value: 'all', label: 'All states' },
                            { value: 'draft', label: 'Draft' },
                        ]}
                    />
                }
                actions={<button type="button">New course</button>}
            />,
        );

        expect(screen.getByRole('region', { name: 'Course search and filters' })).toBeInTheDocument();
        const search = screen.getByRole('searchbox', { name: 'Search courses' });
        expect(search).toHaveClass('h-10');
        await userEvent.type(search, 'a');
        expect(onSearch).toHaveBeenLastCalledWith('a');
        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'State' }), 'draft');
        expect(onFilter).toHaveBeenCalledWith('draft');
    });

    it('uses the requested heading level and exposes its context', () => {
        render(
            <PageHeader
                title="Learner record"
                description="Learning history and credentials"
                eyebrow="People"
                headingLevel="h2"
            />,
        );

        expect(screen.getByRole('heading', { level: 2, name: 'Learner record' })).toBeInTheDocument();
        expect(screen.getByText('Learning history and credentials')).toBeInTheDocument();
        expect(screen.getByText('People')).toBeInTheDocument();
    });
});
