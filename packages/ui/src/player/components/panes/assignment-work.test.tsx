import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AssignmentWork } from './assignment-work';
import { makeConfig, makeHarness, jsonResponse } from '../../testing/harness';
import { uploadFile } from '@/shared/upload';

vi.mock('@/shared/upload', async importOriginal => ({ ...await importOriginal<typeof import('@/shared/upload')>(), uploadFile: vi.fn() }));

const rules = { assignment: { lesson_id: 9, max_points: 100, max_files: 10, allowed_file_types: ['jpg', 'png'], max_file_size_kb: 8192, due_at: null, allow_resubmission: true }, submissions: [], can_submit: true, overdue: false };

beforeEach(() => {
    vi.mocked(uploadFile).mockReset().mockImplementation(async ({ file }) => ({ path: `submissions/42/${file.name}`, name: file.name, size_bytes: file.size, mime: file.type, url: null }));
});

function setup() {
    const harness = makeHarness(request => {
        if (request.path === '/v1/assignments/9/submissions') return jsonResponse(request.method === 'GET' ? rules : { id: 1 });
        return null;
    }, makeConfig({ urls: { course: '/course/demo', myLearning: '/my-learning', upload: '/uploads' } }));
    render(<AssignmentWork lessonId={9} />, { wrapper: harness.wrapper });
    return harness;
}

it('uploads ten photos privately and submits all ten in one attempt', async () => {
    const harness = setup();
    const user = userEvent.setup();
    const input = await screen.findByLabelText('Choose photos or files');
    const photos = Array.from({ length: 10 }, (_, i) => new File(['photo'], `step-${i}.jpg`, { type: 'image/jpeg' }));
    await user.upload(input, photos);
    await user.click(screen.getByRole('button', { name: 'Submit for assessment' }));
    await waitFor(() => expect(harness.requests.some(request => request.method === 'POST')).toBe(true));
    expect(uploadFile).toHaveBeenCalledTimes(10);
    const body = harness.requests.find(request => request.method === 'POST')?.body as { files: unknown[] };
    expect(body.files).toHaveLength(10);
    expect(vi.mocked(uploadFile).mock.calls[0]?.[0].purpose).toBe('assignment');
});

it('refuses an eleventh photo without starting any upload', async () => {
    setup();
    const input = await screen.findByLabelText('Choose photos or files');
    fireEvent.change(input, { target: { files: Array.from({ length: 11 }, (_, i) => new File(['photo'], `${i}.png`, { type: 'image/png' })) } });
    expect(await screen.findByRole('alert')).toHaveTextContent('exceed the assignment file limit');
    expect(uploadFile).not.toHaveBeenCalled();
});

it('keeps selected photos available after an upload failure', async () => {
    setup();
    const user = userEvent.setup();
    vi.mocked(uploadFile).mockRejectedValueOnce(new Error('Connection interrupted'));
    await user.upload(await screen.findByLabelText('Choose photos or files'), new File(['photo'], 'cake.jpg', { type: 'image/jpeg' }));
    await user.click(screen.getByRole('button', { name: 'Submit for assessment' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Connection interrupted');
    expect(screen.getByText('cake.jpg')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Submit for assessment' })).toBeEnabled();
});
