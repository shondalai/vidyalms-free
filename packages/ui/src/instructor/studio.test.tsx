import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { CurriculumTab } from './tabs/curriculum-tab';
import { SettingsTab } from './tabs/settings-tab';
import {
    jsonResponse,
    makeCourse,
    makeCurriculum,
    makeHarness,
    lessonTypesFixture,
} from './testing/harness';

describe('course studio navigation', () => {
    it('protects an inline lesson draft when another lesson is selected', async () => {
        const user = userEvent.setup();
        const harness = makeHarness((request) => {
            if (request.path.includes('/curriculum')) return jsonResponse(makeCurriculum());
            if (request.path.includes('/lesson-types')) return jsonResponse({ items: lessonTypesFixture });
            return null;
        });
        render(<CurriculumTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
        await user.click(await screen.findByRole('button', { name: 'Expand all' }));
        await user.click(screen.getByRole('button', { name: 'Text alternatives' }));
        await user.clear(screen.getByLabelText('Title'));
        await user.type(screen.getByLabelText('Title'), 'Unsaved lesson title');
        await user.click(screen.getByRole('button', { name: 'Colour checklist' }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Title')).toHaveValue('Unsaved lesson title');
        expect(harness.requests.some((request) => request.method === 'PATCH')).toBe(false);
    });

    it('keeps course drafts while browsing configuration sections and fetches only visited tools', async () => {
        const user = userEvent.setup();
        const harness = makeHarness((request) =>
            request.path.includes('/instructors') ? jsonResponse({ items: [] }) : null,
        );
        render(<SettingsTab course={makeCourse()} narrow={false} />, { wrapper: harness.wrapper });
        await user.clear(screen.getByLabelText('Title'));
        await user.type(screen.getByLabelText('Title'), 'A working draft');
        expect(harness.requests.some((request) => request.path.includes('/instructors'))).toBe(false);
        await user.click(screen.getByRole('button', { name: /^Teaching team/ }));
        await waitFor(() =>
            expect(harness.requests.some((request) => request.path.includes('/instructors'))).toBe(true),
        );
        await user.click(screen.getByRole('button', { name: /^Course details/ }));
        expect(screen.getByLabelText('Title')).toHaveValue('A working draft');
    });
});
