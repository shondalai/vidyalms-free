import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { CurriculumModule } from '@/shared/api';
import { CurriculumRail } from './components/curriculum-rail';

/*
 * The curriculum rail: module grouping, per-lesson state icon plus hidden
 * state label, type chips, the aria-current highlight, and keyboard
 * operability through real buttons.
 */

const OPEN_ACCESS = {
    state: 'available' as const,
    reason: null,
    reason_parameters: {},
    opens_at: null,
    opens_at_timezone: null,
};

const modules: CurriculumModule[] = [
    {
        id: 1,
        title: 'Getting started',
        summary: null,
        position: 1,
        access: { ...OPEN_ACCESS },
        lessons: [
            {
                id: 1,
                title: 'Welcome',
                slug: 'welcome',
                type: 'video',
                summary: null,
                duration_seconds: 300,
                position: 1,
                free_preview: true,
                access: { ...OPEN_ACCESS },
                progress: {
                    lesson_id: 1,
                    status: 'completed',
                    progress_percent: 100,
                    seconds_spent: 300,
                    position_seconds: null,
                    first_accessed_at: '2026-08-01 10:00:00',
                    completed_at: '2026-08-01 10:05:00',
                    last_heartbeat_at: '2026-08-01 10:05:00',
                },
            },
        ],
    },
    {
        id: 2,
        title: 'Perceivable content',
        summary: null,
        position: 2,
        access: { ...OPEN_ACCESS },
        lessons: [
            {
                id: 2,
                title: 'Captions and transcripts',
                slug: 'captions',
                type: 'video',
                summary: null,
                duration_seconds: 480,
                position: 1,
                free_preview: false,
                access: { ...OPEN_ACCESS },
                progress: {
                    lesson_id: 2,
                    status: 'in_progress',
                    progress_percent: 60,
                    seconds_spent: 290,
                    position_seconds: 298,
                    first_accessed_at: '2026-08-02 10:00:00',
                    completed_at: null,
                    last_heartbeat_at: '2026-08-02 10:05:00',
                },
            },
            {
                id: 3,
                title: 'Colour and contrast checklist',
                slug: 'contrast',
                type: 'resource',
                summary: null,
                duration_seconds: null,
                position: 2,
                free_preview: false,
                access: { ...OPEN_ACCESS },
                progress: null,
            },
            {
                id: 4,
                title: 'Module 2 check',
                slug: 'check',
                type: 'quiz',
                summary: null,
                duration_seconds: null,
                position: 3,
                free_preview: false,
                access: { ...OPEN_ACCESS },
                progress: null,
            },
        ],
    },
];

describe('CurriculumRail', () => {
    it('renders a labelled navigation with module headings in order', () => {
        render(<CurriculumRail modules={modules} currentLessonId={2} locale="en-GB" onSelect={() => undefined} />);
        const nav = screen.getByRole('navigation', { name: 'Course curriculum' });
        const headings = within(nav).getAllByRole('heading', { level: 3 });
        expect(headings.map((heading) => heading.textContent)).toEqual([
            'CHAPTER 01Getting started',
            'CHAPTER 02Perceivable content',
        ]);
    });

    it('marks the current lesson with aria-current and every state with shape plus label', () => {
        render(<CurriculumRail modules={modules} currentLessonId={2} locale="en-GB" onSelect={() => undefined} />);
        const current = screen.getByRole('button', { current: true });
        expect(current).toHaveTextContent('Captions and transcripts');

        const completedRow = screen.getByRole('button', { name: /Welcome/ });
        expect(completedRow).toHaveTextContent('Complete');
        expect(completedRow.querySelector('[data-state="completed"]')).not.toBeNull();

        expect(current).toHaveTextContent('In progress');
        expect(current.querySelector('[data-state="in_progress"]')).not.toBeNull();

        const notStartedRow = screen.getByRole('button', { name: /Colour and contrast/ });
        expect(notStartedRow).toHaveTextContent('Not started');
        expect(notStartedRow.querySelector('[data-state="not_started"]')).not.toBeNull();
    });

    it('shows the short type chips of the artboard', () => {
        render(<CurriculumRail modules={modules} currentLessonId={2} locale="en-GB" onSelect={() => undefined} />);
        expect(screen.getByRole('button', { name: /Welcome/ })).toHaveTextContent('VIDEO');
        expect(screen.getByRole('button', { name: /Colour and contrast/ })).toHaveTextContent('FILE');
        expect(screen.getByRole('button', { name: /Module 2 check/ })).toHaveTextContent('QUIZ');
    });

    it('selects a lesson by click', async () => {
        const onSelect = vi.fn();
        render(<CurriculumRail modules={modules} currentLessonId={2} locale="en-GB" onSelect={onSelect} />);
        await userEvent.click(screen.getByRole('button', { name: /Module 2 check/ }));
        expect(onSelect).toHaveBeenCalledWith(4);
    });

    it('is keyboard operable: rows are focusable buttons activated with Enter', async () => {
        const onSelect = vi.fn();
        render(<CurriculumRail modules={modules} currentLessonId={2} locale="en-GB" onSelect={onSelect} />);
        screen.getByRole('button', { name: /Captions and transcripts/ }).focus();
        await userEvent.keyboard('{Enter}');
        expect(onSelect).toHaveBeenCalledWith(2);
    });
    it('filters lessons inside collapsed chapters and restores the chapter after clearing', async () => {
        const onSelect = vi.fn();
        render(<CurriculumRail modules={modules} currentLessonId={2} locale="en-GB" onSelect={onSelect} />);
        await userEvent.click(screen.getByRole('button', { name: 'Module 2 · Perceivable content' }));
        expect(screen.queryByRole('button', { name: /Captions and transcripts/ })).not.toBeInTheDocument();
        await userEvent.type(screen.getByRole('searchbox', { name: 'Find a lesson' }), 'caption');
        await userEvent.click(screen.getByRole('button', { name: /Captions and transcripts/ }));
        expect(onSelect).toHaveBeenCalledWith(2);
        expect(screen.queryByRole('button', { name: /Welcome/ })).not.toBeInTheDocument();
        await userEvent.clear(screen.getByRole('searchbox', { name: 'Find a lesson' }));
        expect(screen.getByRole('button', { name: 'Module 2 · Perceivable content' })).toHaveAttribute('aria-expanded', 'false');
    });

});
