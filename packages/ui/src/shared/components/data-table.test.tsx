import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DataTable, type DataTableColumn, type DataTableSort } from './data-table';

interface Row {
    id: number;
    title: string;
    enrolled: number;
}

const rows: Row[] = [
    { id: 1, title: 'Foundations of Web Accessibility', enrolled: 128 },
    { id: 2, title: 'Inclusive Design Patterns', enrolled: 95 },
];

const columns: DataTableColumn<Row>[] = [
    { key: 'title', header: 'Course', sortKey: 'title', weight: 1.7, cell: (row) => row.title },
    { key: 'enrolled', header: 'Enrolled', sortKey: 'enrolled_count', width: '110px', cell: (row) => row.enrolled },
    { key: 'actions', header: 'Actions', hideHeader: true, width: '80px', cell: () => <button type="button">Open</button> },
];

function renderTable(props: Partial<Parameters<typeof DataTable<Row>>[0]> = {}) {
    const onSortChange = vi.fn();
    render(
        <DataTable
            caption="Courses"
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            onSortChange={onSortChange}
            {...props}
        />,
    );
    return { onSortChange };
}

describe('DataTable', () => {
    it('is a table named by its caption', () => {
        renderTable();
        expect(screen.getByRole('table', { name: 'Courses' })).toBeInTheDocument();
    });

    it('renders one row per record', () => {
        renderTable();
        const table = screen.getByRole('table', { name: 'Courses' });
        /* Two records plus the header row. */
        expect(within(table).getAllByRole('row')).toHaveLength(3);
        expect(screen.getByRole('cell', { name: 'Foundations of Web Accessibility' })).toBeInTheDocument();
    });

    it('gives a fluid column the space left after fixed pixel columns', () => {
        renderTable();
        const table = screen.getByRole('table', { name: 'Courses' });
        const renderedColumns = table.querySelectorAll('col');

        expect(table.parentElement).toHaveClass('w-full', 'min-w-0', 'max-w-full', 'overflow-x-auto');
        expect(table).toHaveStyle({ minWidth: '370px' });
        expect(renderedColumns[0]?.style.width).toBe('calc(100% - 190px)');
        expect(renderedColumns[1]?.style.width).toBe('110px');
        expect(renderedColumns[2]?.style.width).toBe('80px');
    });

    it('turns fluid weights into proportional valid CSS widths', () => {
        const weightedColumns: DataTableColumn<Row>[] = [
            { key: 'title', header: 'Course', weight: 2, minWidth: 200, cell: (row) => row.title },
            { key: 'enrolled', header: 'Enrolled', weight: 1, minWidth: 100, cell: (row) => row.enrolled },
            { key: 'actions', header: 'Actions', width: '90px', cell: () => null },
        ];

        render(<DataTable caption="Weighted courses" columns={weightedColumns} rows={rows} rowKey={(row) => row.id} />);
        const table = screen.getByRole('table', { name: 'Weighted courses' });
        const renderedColumns = table.querySelectorAll('col');

        expect(table).toHaveStyle({ minWidth: '390px' });
        expect(renderedColumns[0]?.style.width).toBe('calc(66.666667% - 60px)');
        expect(renderedColumns[1]?.style.width).toBe('calc(33.333333% - 30px)');
        expect(renderedColumns[2]?.style.width).toBe('90px');
    });

    it('marks every sortable column aria-sort none until one is sorted', () => {
        renderTable();
        expect(screen.getByRole('columnheader', { name: 'Course' })).toHaveAttribute('aria-sort', 'none');
        expect(screen.getByRole('columnheader', { name: 'Enrolled' })).toHaveAttribute('aria-sort', 'none');
    });

    it('reports the active column and direction through aria-sort', () => {
        const sort: DataTableSort = { key: 'enrolled_count', order: 'desc' };
        renderTable({ sort });
        expect(screen.getByRole('columnheader', { name: 'Enrolled' })).toHaveAttribute('aria-sort', 'descending');
        expect(screen.getByRole('columnheader', { name: 'Course' })).toHaveAttribute('aria-sort', 'none');
    });

    it('asks for ascending order when a new column is chosen', async () => {
        const { onSortChange } = renderTable({ sort: { key: 'title', order: 'desc' } });
        await userEvent.click(screen.getByRole('button', { name: 'Enrolled' }));
        expect(onSortChange).toHaveBeenCalledWith({ key: 'enrolled_count', order: 'asc' });
    });

    it('flips the direction when the active column is chosen again', async () => {
        const { onSortChange } = renderTable({ sort: { key: 'title', order: 'asc' } });
        await userEvent.click(screen.getByRole('button', { name: 'Course' }));
        expect(onSortChange).toHaveBeenCalledWith({ key: 'title', order: 'desc' });
    });

    it('leaves columns without a sort key unsortable', () => {
        renderTable();
        const header = screen.getByRole('columnheader', { name: 'Actions' });
        expect(header).not.toHaveAttribute('aria-sort');
        expect(within(header).queryByRole('button')).toBeNull();
    });

    it('offers no sort controls at all without an onSortChange handler', () => {
        render(<DataTable caption="Courses" columns={columns} rows={rows} rowKey={(row) => row.id} />);
        expect(screen.queryByRole('button', { name: 'Course' })).toBeNull();
        expect(screen.getByRole('columnheader', { name: 'Course' })).toHaveAttribute('aria-sort', 'none');
    });

    it('shows shape-true skeletons and marks the table busy while loading', () => {
        renderTable({ rows: [], loading: true, skeletonRows: 3 });
        const table = screen.getByRole('table', { name: 'Courses' });
        expect(table).toHaveAttribute('aria-busy', 'true');
        expect(within(table).getAllByRole('row')).toHaveLength(4);
    });

    it('renders the empty node only when nothing is loading and nothing arrived', () => {
        renderTable({ rows: [], empty: <p>No courses yet</p> });
        expect(screen.getByText('No courses yet')).toBeInTheDocument();
    });

    it('keeps the empty node away while loading', () => {
        renderTable({ rows: [], loading: true, empty: <p>No courses yet</p> });
        expect(screen.queryByText('No courses yet')).toBeNull();
    });

    it('selects one row through a controlled accessible checkbox', async () => {
        const onSelectionChange = vi.fn();
        renderTable({
            selection: {
                selectedKeys: new Set([2]),
                onSelectionChange,
                rowLabel: (row) => row.title,
            },
        });

        expect(screen.getByRole('checkbox', { name: 'Select Inclusive Design Patterns' })).toBeChecked();
        await userEvent.click(screen.getByRole('checkbox', { name: 'Select Foundations of Web Accessibility' }));
        expect(onSelectionChange).toHaveBeenCalledWith(new Set([1, 2]));
    });

    it('selects and clears only the rendered server page', async () => {
        const onSelectionChange = vi.fn();
        const { rerender } = render(
            <DataTable
                caption="Courses"
                columns={columns}
                rows={rows}
                rowKey={(row) => row.id}
                selection={{
                    selectedKeys: new Set([99]),
                    onSelectionChange,
                    rowLabel: (row) => row.title,
                    selectAllLabel: 'Select this page',
                }}
            />,
        );
        await userEvent.click(screen.getByRole('checkbox', { name: 'Select this page' }));
        expect(onSelectionChange).toHaveBeenLastCalledWith(new Set([99, 1, 2]));

        rerender(
            <DataTable
                caption="Courses"
                columns={columns}
                rows={rows}
                rowKey={(row) => row.id}
                selection={{
                    selectedKeys: new Set([99, 1, 2]),
                    onSelectionChange,
                    rowLabel: (row) => row.title,
                    selectAllLabel: 'Select this page',
                }}
            />,
        );
        await userEvent.click(screen.getByRole('checkbox', { name: 'Select this page' }));
        expect(onSelectionChange).toHaveBeenLastCalledWith(new Set([99]));
    });
});
