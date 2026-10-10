import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import { CourseBuilder } from './course-builder';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import {
    jsonResponse,
    lessonTypesFixture,
    makeConfig,
    makeCourse,
    makeCurriculum,
    makeHarness,
    makeLesson,
    makeModule,
    makeQuestion,
    makeQuiz,
    makeQuizAuthoring,
    questionTypesFixture,
    type RecordedRequest,
} from './testing/harness';

function setup({
    existing = true,
    override,
}: { existing?: boolean; override?: (request: RecordedRequest) => Response | null } = {}) {
    let created = existing;
    let lesson = makeLesson({
        id: 4,
        type: 'quiz',
        module_id: 2,
        title: 'Knowledge check',
        content: { quiz_id: 21 },
    });
    let quiz = makeQuizAuthoring({ title: 'Knowledge check', lesson_id: 4, questions: [] });
    const harness = makeHarness(
        (request) => {
            const response = override?.(request);
            if (response) return response;
            const path = request.path.split('?')[0];
            if (path === '/v1/manage/courses/1') return jsonResponse(makeCourse());
            if (path === '/v1/lesson-types') return jsonResponse({ items: lessonTypesFixture });
            if (path === '/v1/question-types') return jsonResponse({ items: questionTypesFixture });
            if (path === '/v1/manage/courses/1/curriculum')
                return jsonResponse(
                    makeCurriculum({
                        modules: [
                            makeModule({ id: 2, title: 'Learning module', lessons: created ? [lesson] : [] }),
                        ],
                    }),
                );
            if (path === '/v1/manage/modules/2/lessons' && request.method === 'POST') {
                created = true;
                return jsonResponse(lesson, 201);
            }
            if (path === '/v1/manage/quizzes/21/questions' && request.method === 'POST') {
                const question = makeQuestion({ id: 103, ...(request.body as object) });
                quiz = {
                    ...quiz,
                    questions: [...quiz.questions, question],
                    question_count: quiz.question_count + 1,
                };
                return jsonResponse(question, 201);
            }
            if (path === '/v1/manage/quizzes/21') {
                if (request.method === 'PUT') quiz = { ...quiz, ...(request.body as object) };
                return jsonResponse(quiz);
            }
            if (path === '/v1/manage/lessons/4' && request.method === 'PATCH') {
                lesson = { ...lesson, ...(request.body as object) };
                return jsonResponse(lesson);
            }
            if (path === '/v1/manage/courses/1/quizzes')
                return jsonResponse({ items: [quiz, makeQuiz({ id: 22, title: 'Another assessment' })] });
            if (path === '/v1/manage/quizzes/21/slots')
                return jsonResponse({ quiz_id: 21, slots: [], warnings: [] });
            return null;
        },
        makeConfig({ routes: ['/manage/quizzes/{id}/slots'] }),
    );
    render(<CourseBuilder courseId={1} />, { wrapper: harness.wrapper });
    return harness;
}

beforeEach(() => window.history.replaceState(null, '', '#/course/1/vy/curriculum'));

describe('quiz lesson authoring journey', () => {
    it('creates a quiz lesson once and opens its questions in the curriculum without a quiz manager', async () => {
        const user = userEvent.setup();
        const harness = setup({ existing: false });
        await user.click(await screen.findByRole('button', { name: 'Add a lesson to Learning module' }));
        await user.click(screen.getByRole('menuitem', { name: /Quiz/ }));
        await user.type(screen.getByLabelText('Title'), 'Knowledge check');
        await user.click(screen.getByRole('button', { name: 'Add lesson' }));
        expect(await screen.findByRole('region', { name: 'Quiz lesson editor' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Questions', selected: true })).toBeInTheDocument();
        expect(window.location.hash).toBe('#/course/1/vy/curriculum');
        expect(screen.queryByRole('button', { name: 'Add quiz' })).not.toBeInTheDocument();
        expect(screen.queryByText('Build questions & assessment details')).not.toBeInTheDocument();
        expect(harness.requests.filter((request) => request.method === 'POST')).toHaveLength(1);
        expect(harness.requests.find((request) => request.method === 'POST')?.path).toBe(
            '/v1/manage/modules/2/lessons',
        );
    });

    it('creates the chosen question type against the linked quiz and retains the lesson selection', async () => {
        const user = userEvent.setup();
        const harness = setup();
        await user.click(await screen.findByRole('button', { name: 'Add question' }));
        await user.type(screen.getByRole('searchbox', { name: 'Search question types' }), 'true');
        await user.click(screen.getByRole('button', { name: /True or false/ }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Prompt'), 'A course can contain a quiz.');
        await user.click(within(dialog).getByRole('radio', { name: 'True' }));
        await user.click(within(dialog).getByRole('button', { name: 'Add question' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'POST')?.body).toEqual({
                type: 'true_false',
                definition: { prompt: 'A course can contain a quiz.', correct: true },
                points: 1,
            }),
        );
        expect(harness.requests.find((request) => request.method === 'POST')?.path).toBe(
            '/v1/manage/quizzes/21/questions',
        );
        expect(await screen.findByRole('button', { name: 'Edit question 1' })).toBeInTheDocument();
        expect(window.location.hash).toBe('#/course/1/vy/curriculum');
    });

    it('edits quiz settings inline, protects the draft, and cannot reassign the lesson', async () => {
        const user = userEvent.setup();
        const harness = setup();
        await user.click(await screen.findByRole('tab', { name: 'Quiz settings' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Attached lesson')).not.toBeInTheDocument();
        const title = screen.getByLabelText('Title');
        await user.clear(title);
        await user.type(title, 'Assessment instructions');
        await user.click(screen.getByRole('tab', { name: 'Questions' }));
        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(title).toHaveValue('Assessment instructions');
        await user.click(screen.getByRole('button', { name: 'Save settings' }));
        await waitFor(() => expect(hasUnsavedWorkspaceChanges()).toBe(false));
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toMatchObject({
            lesson_id: 4,
            title: 'Assessment instructions',
            pass_mark: 70,
            time_limit_seconds: 1200,
        });
        expect(screen.getByRole('tab', { name: 'Quiz settings', selected: true })).toBeInTheDocument();
    });

    it('updates lesson metadata without overwriting the quiz relationship', async () => {
        const user = userEvent.setup();
        const harness = setup();
        await user.click(await screen.findByRole('tab', { name: 'Lesson details' }));
        const title = screen.getByLabelText('Title');
        await user.clear(title);
        await user.type(title, 'Final knowledge check');
        await user.click(screen.getByRole('button', { name: 'Save lesson' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'PATCH')?.body).toMatchObject({
                title: 'Final knowledge check',
            }),
        );
        expect(harness.requests.find((request) => request.method === 'PATCH')?.body).not.toHaveProperty(
            'content',
        );
        expect(await screen.findByRole('heading', { name: 'Final knowledge check' })).toBeInTheDocument();
    });

    it('preserves a timer shorter than one minute when changing other quiz settings', async () => {
        const user = userEvent.setup();
        const harness = setup({
            override: (request) =>
                request.method === 'GET' && request.path === '/v1/manage/quizzes/21'
                    ? jsonResponse(makeQuizAuthoring({ lesson_id: 4, time_limit_seconds: 45 }))
                    : null,
        });
        await user.click(await screen.findByRole('tab', { name: 'Quiz settings' }));
        await user.type(screen.getByLabelText('Title'), ' revised');
        await user.click(screen.getByRole('button', { name: 'Save settings' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'PUT')?.body).toMatchObject({
                time_limit_seconds: 45,
                lesson_id: 4,
            }),
        );
    });

    it('opens bank selection rules in this lesson and removes the course-level quiz collection', async () => {
        const user = userEvent.setup();
        const harness = setup();
        await user.click(await screen.findByRole('button', { name: 'Use question banks' }));
        expect(screen.getByRole('tab', { name: 'Selection rules', selected: true })).toBeInTheDocument();
        await waitFor(() =>
            expect(harness.requests.some((request) => request.path === '/v1/manage/quizzes/21/slots')).toBe(
                true,
            ),
        );
        expect(window.location.hash).toBe('#/course/1/vy/curriculum');
        await user.click(screen.getByRole('tab', { name: 'Assessment & completion' }));
        expect(screen.queryByRole('button', { name: /Quizzes & questions/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add quiz' })).not.toBeInTheDocument();
    });

    it('never falls back to a different quiz when the stored relationship is inconsistent', async () => {
        setup({
            override: (request) =>
                request.path === '/v1/manage/quizzes/21'
                    ? jsonResponse(makeQuizAuthoring({ lesson_id: 99 }))
                    : null,
        });
        expect(await screen.findByText('The linked quiz is unavailable')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add question' })).not.toBeInTheDocument();
    });
});
