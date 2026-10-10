import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { GradebookTab } from './tabs/gradebook-tab';
import { jsonResponse, makeConfig, makeGradebookPage, makeHarness, type RecordedRequest } from './testing/harness';

/*
 * The one thing the contract forces on this tab: the course total is produced
 * by a registered GradeAggregation provider, so the method arrives from the
 * server and nothing here adds up a cell.
 *
 * Both states are driven, because the miss direction is the half that is
 * usually only in a docblock: a guessed total is a grade dispute, an absent
 * one is a support ticket, and the screen has to name the missing key rather
 * than showing a number nobody can defend.
 */

function courseTotal(overrides: Record<string, unknown> = {}) {
    return {
        id: 900,
        course_id: 1,
        parent_item_id: null,
        item_type: 'course_total',
        source_kind: 'manual',
        source_id: 0,
        title: 'Course total',
        grade_max: '100.00000',
        grade_min: '0.00000',
        weight_bp: 10000,
        weight_overridden: false,
        extra_credit: false,
        aggregation_key: 'natural',
        aggregation_label: 'Natural',
        aggregation_available: true,
        aggregation_params: null,
        aggregation_params_version: 1,
        empty_grade_policy: 'exclude',
        drop_lowest: 0,
        display_mode: 'real',
        decimals: 2,
        hidden: false,
        locked: false,
        position: 0,
        ...overrides,
    };
}

function renderTab(total: Record<string, unknown> | null, available = true) {
    const page = makeGradebookPage();
    const harness = makeHarness((request: RecordedRequest): Response | null => {
        const key = `${request.method} ${request.path.split('?')[0] ?? ''}`;
        if (key === 'GET /v1/manage/courses/1/gradebook') {
            return jsonResponse(page);
        }
        if (key === 'GET /v1/manage/courses/1/gradebook/items') {
            return jsonResponse({ course_id: 1, items: total === null ? [] : [total], course_total: total });
        }
        return null;
    }, makeConfig({ routes: available ? ['/manage/courses/{id}/gradebook/items'] : [] }));
    render(<GradebookTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
    return harness;
}

describe('the gradebook tab reads its aggregation from the registry', () => {
    it('does not request an aggregation route the host does not advertise', async () => {
        const harness = renderTab(null, false);
        await screen.findByText(/Accessibility audit exercise/);
        expect(harness.requests.some((request) => request.path.includes('/gradebook/items'))).toBe(false);
    });
    it('names the provider the course total is produced by', async () => {
        renderTab(courseTotal());
        expect(await screen.findByText('Course total by Natural')).toBeInTheDocument();
    });

    it('falls back to the provider key when nothing has translated its label', async () => {
        renderTab(courseTotal({ aggregation_label: null }));
        expect(await screen.findByText('Course total by natural')).toBeInTheDocument();
    });

    it('names the missing provider rather than showing a guessed total', async () => {
        renderTab(courseTotal({ aggregation_key: 'weighted-mean', aggregation_label: null, aggregation_available: false }));
        expect(
            await screen.findByText(
                'Course total not computed: no aggregation provider answers "weighted-mean" on this site.',
            ),
        ).toBeInTheDocument();
    });

    it('says nothing at all on a course whose gradebook has never been built', async () => {
        renderTab(null);
        await screen.findByText(/Accessibility audit exercise/);
        expect(screen.queryByText(/Course total/)).not.toBeInTheDocument();
    });
});
