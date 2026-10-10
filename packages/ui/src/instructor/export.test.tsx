import { describe, expect, it } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QuizzesTab } from './tabs/quizzes-tab';
import {
    jsonResponse,
    makeConfig,
    makeHarness,
    makeQuiz,
    makeQuizAuthoring,
    questionTypesFixture,
    type RecordedRequest,
} from './testing/harness';

/*
 * The Export control.
 *
 * It is shown ONLY where the host says it routes the export. The bundle and
 * the extension ship and upgrade separately, so a workbench newer than its
 * host would otherwise offer a control that answers 404, which is worse than
 * no control at all. Discovery is a mount key and never a permission: what
 * the caller may do is still the 403 the API answers.
 *
 * The notice about correct responses comes BEFORE the download and not
 * after it, because after it is too late: the package is useless without the
 * answers, and anybody who can open the file can read the key.
 */

function harnessFor(routes?: string[]) {
    const config = makeConfig(routes === undefined ? {} : { routes });
    return makeHarness((request: RecordedRequest) => {
        const path = request.path.split('?')[0] ?? '';
        if (request.method === 'GET' && path === '/v1/manage/courses/1/quizzes') {
            return jsonResponse({ items: [makeQuiz()] });
        }
        if (request.method === 'GET' && path === '/v1/manage/quizzes/21') {
            return jsonResponse(makeQuizAuthoring());
        }
        if (request.method === 'GET' && path === '/v1/question-types') {
            return jsonResponse(questionTypesFixture);
        }
        if (request.method === 'GET' && path === '/v1/manage/quizzes/21/export') {
            return jsonResponse({
                quiz_id: 21,
                format: 'qti3',
                profile: 'strict',
                download_url: 'https://example.test/signed/quiz-21.zip',
                items: 10,
                media: 0,
                contains_correct_responses: true,
                downgrades: [],
                warnings: [],
            });
        }
        return null;
    }, config);
}

describe('the export control', () => {
    it('is absent on a host that does not declare the route', async () => {
        const harness = harnessFor();
        render(<QuizzesTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        expect(await screen.findByRole('button', { name: 'Quiz settings' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Export' })).not.toBeInTheDocument();
    });

    it('appears once the host declares it', async () => {
        const harness = harnessFor(['/manage/quizzes/{id}/export']);
        render(<QuizzesTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        expect(await screen.findByRole('button', { name: 'Export' })).toBeInTheDocument();
    });

    it('states that the file carries the correct responses before anything is downloaded', async () => {
        const harness = harnessFor(['/manage/quizzes/{id}/export']);
        render(<QuizzesTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('button', { name: 'Export' }));

        expect(await screen.findByText(/contains the correct responses/)).toBeInTheDocument();
        /* Nothing has been fetched yet: the notice is the first thing, and
           the download link only exists after the operator asks for it. */
        expect(screen.queryByRole('link', { name: 'Download' })).not.toBeInTheDocument();
    });

    it('offers the signed link the route answers rather than streaming bytes through the contract', async () => {
        const harness = harnessFor(['/manage/quizzes/{id}/export']);
        render(<QuizzesTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('button', { name: 'Export' }));
        const dialog = await screen.findByRole('dialog');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Export' }));

        const link = await screen.findByRole('link', { name: 'Download' });
        expect(link).toHaveAttribute('href', 'https://example.test/signed/quiz-21.zip');
    });
});
