import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it } from 'vitest';
import { BankPickerDialog } from './tabs/bank-picker-dialog';
import { makeBankEntry } from './testing/assessment-fixtures';
import { jsonResponse, makeHarness } from './testing/harness';

it('pages large question banks at the server and keeps type filters independent of the current results', async () => {
    const user = userEvent.setup();
    const harness = makeHarness((request) => {
        if (request.path.startsWith('/v1/question-types')) return jsonResponse({
            items: [{ key: 'single_choice', label: 'Single choice' }, { key: 'essay', label: 'Essay' }], presets: [],
        });
        if (request.path.startsWith('/v1/manage/banks/entries')) {
            const params = new URL(request.path, 'https://example.test').searchParams;
            const page = Number(params.get('page') ?? 1);
            const entry = makeBankEntry();
            return jsonResponse({ items: [{ ...entry, id: page, latest_version: {
                ...entry.latest_version, type: 'single_choice', definition: { prompt: `Question on page ${page}` },
            } }], page, per_page: 20, total: 1200 });
        }
        return null;
    });
    render(<BankPickerDialog courseId={1} onPick={() => undefined} onClose={() => undefined} />, { wrapper: harness.wrapper });
    await screen.findByText('Question on page 1');
    expect(screen.getByRole('option', { name: 'Essay' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /^Next/ }));
    await screen.findByText('Question on page 2');
    await user.selectOptions(screen.getByLabelText('Type'), 'essay');
    await waitFor(() => expect(harness.requests.at(-1)?.path).toContain('type=essay'));
    expect(harness.requests.at(-1)?.path).toContain('page=1');
    expect(harness.requests.at(-1)?.path).toContain('per_page=20');
    expect(screen.getByRole('option', { name: 'Essay' })).toBeInTheDocument();
});
