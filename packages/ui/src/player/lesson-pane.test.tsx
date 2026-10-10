import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { CurriculumEnrolment, LessonView } from '@/shared/api';
import { LessonPane } from './components/lesson-pane';
import { flattenCurriculum } from './lib';
import {
    jsonResponse,
    makeConfig,
    makeCurriculum,
    makeHarness,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

/*
 * Each lesson pane type against the SDK boundary: reading (sanitized),
 * video (embed and native), resource (download completes), assignment
 * (stage strip), quiz (launch panel), the Pro placeholder, and the
 * suspended 409 lift.
 */

const flat = flattenCurriculum(makeCurriculum());
const enrolment = { id: 3, status: 'active' as const, progress_percent: 25 };

function renderPane(
    lesson: LessonView | null,
    options: {
        lessonId?: number;
        lessonResponse?: Response;
        onSuspended?: () => void;
        onSelect?: (id: number) => void;
        offline?: boolean;
        /** Null is a visitor previewing without an enrolment. */
        enrolment?: CurriculumEnrolment | null;
        /** A guest mount: the host config names no user. */
        guest?: boolean;
        /** Simulate the server refusing an early completion claim. */
        acceptCompletion?: boolean;
    } = {},
) {
    const lessonId = options.lessonId ?? lesson?.id ?? 1;
    const config = options.guest === true ? makeConfig({ user: { id: 0, name: '' } }) : makeConfig();
    let completed = false;
    const harness = makeHarness((request: RecordedRequest) => {
        if (request.method === 'GET' && request.path === `/v1/lessons/${lessonId}`) {
            return options.lessonResponse ?? (lesson !== null ? jsonResponse(completed
                ? { ...lesson, progress: { ...lesson.progress, status: 'completed' } }
                : lesson) : null);
        }
        if (request.method === 'POST' && request.path === `/v1/lessons/${lessonId}/progress`) {
            if ((request.body as { completed?: boolean })?.completed && options.acceptCompletion !== false) {
                completed = true;
            }
            return new Response(null, { status: 204 });
        }
        if (request.method === 'GET' && request.path === `/v1/assignments/${lessonId}/submissions`) {
            return jsonResponse({ assignment: { lesson_id: lessonId, max_points: 100, max_files: 10, allowed_file_types: ['jpg', 'png'], max_file_size_kb: 8192, due_at: null, allow_resubmission: true }, submissions: [], can_submit: true, overdue: false });
        }
        return null;
    }, config);
    const view = render(
        <LessonPane
            lessonId={lessonId}
            flat={flat}
            courseId={1}
            enrolment={options.enrolment === undefined ? enrolment : options.enrolment}
            offline={options.offline ?? false}
            onSelect={options.onSelect ?? (() => undefined)}
            onSuspended={options.onSuspended ?? (() => undefined)}
            onEngine={() => undefined}
        />,
        { wrapper: harness.wrapper },
    );
    return { ...harness, view };
}

describe('LessonPane framing', () => {
    it('shows the meta line with numbering, duration and the completion rule', async () => {
        const lesson = flatLessonView(8, {
            type: 'reading',
            title: 'Focus management',
            duration_seconds: 360,
            content: { body: '<p>Content</p>' },
        });
        renderPane(lesson);
        await screen.findByRole('heading', { name: 'Focus management' });
        expect(screen.getByText('READING')).toBeInTheDocument();
        expect(
            screen.getByText('Lesson 3.1 · 6 min read · completes when you reach the end'),
        ).toBeInTheDocument();
    });

    it('renders prev and next from the flat curriculum order', async () => {
        const onSelect = vi.fn();
        const lesson = flatLessonView(5, { type: 'resource', title: 'Colour and contrast checklist', content: { files: [] } });
        renderPane(lesson, { onSelect });
        await screen.findByRole('heading', { name: 'Colour and contrast checklist' });
        await userEvent.click(screen.getByRole('button', { name: /Previous/ }));
        expect(onSelect).toHaveBeenCalledWith(4);
        await userEvent.click(screen.getByRole('button', { name: /Continue/ }));
        expect(onSelect).toHaveBeenCalledWith(6);
    });

    it('lifts a 409 lesson load with the suspension detail as the suspended enrolment state', async () => {
        const onSuspended = vi.fn();
        renderPane(null, {
            lessonId: 8,
            lessonResponse: problemResponse(
                409,
                'Conflict',
                'The enrolment is suspended; the lesson cannot be opened.',
            ),
            onSuspended,
        });
        await waitFor(() => expect(onSuspended).toHaveBeenCalled());
    });

    it('keeps a 409 without the suspension detail as a plain pane error', async () => {
        const onSuspended = vi.fn();
        renderPane(null, {
            lessonId: 8,
            lessonResponse: problemResponse(409, 'Conflict', 'Some other conflict.'),
            onSuspended,
        });
        await screen.findByText("This lesson couldn't load");
        expect(onSuspended).not.toHaveBeenCalled();
    });

    it('renders the pane error state with retry on other failures', async () => {
        renderPane(null, { lessonId: 8, lessonResponse: problemResponse(500, 'Boom') });
        await screen.findByRole('alert');
        expect(screen.getByText("This lesson couldn't load")).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });
});

describe('reading pane', () => {
    it('renders the authored body through the sanitizer', async () => {
        /* The strict XSS-stripping proof lives in sanitize.test.ts under a
           real jsdom DOM; here the pane proves the sanitized body renders. */
        const lesson = flatLessonView(8, {
            type: 'reading',
            title: 'Focus management',
            content: { body: '<p>Safe paragraph</p>' },
        });
        renderPane(lesson);
        await screen.findByText('Safe paragraph');
    });

    it('shows the reading position strip with the save indicator', async () => {
        const lesson = flatLessonView(8, { type: 'reading', title: 'Focus management', content: { body: '<p>Body</p>' } });
        renderPane(lesson);
        await screen.findByText('Your progress saves automatically as you learn.');
        expect(screen.getByText('Marks complete at the end of the page')).toBeInTheDocument();
    });
});

describe('video pane', () => {
    it('embeds YouTube through the privacy domain with the lesson title as accessible name', async () => {
        const lesson = flatLessonView(4, {
            type: 'video',
            title: 'Captions and transcripts',
            duration_seconds: 483,
            content: { provider: 'youtube', url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' },
        });
        const { view } = renderPane(lesson);
        await screen.findByRole('heading', { name: 'Captions and transcripts' });
        const iframe = view.container.querySelector('iframe');
        expect(iframe?.getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
        expect(iframe?.getAttribute('title')).toBe('Captions and transcripts');
    });

    it('plays direct URLs through a native video element with captions', async () => {
        const lesson = flatLessonView(4, {
            type: 'video',
            title: 'Captions and transcripts',
            duration_seconds: 483,
            content: {
                provider: 'url',
                url: 'https://cdn.example.com/lesson.mp4',
                caption_url: 'https://cdn.example.com/lesson.vtt',
            },
        });
        const { view } = renderPane(lesson);
        await screen.findByRole('heading', { name: 'Captions and transcripts' });
        const video = view.container.querySelector('video');
        expect(video?.getAttribute('src')).toBe('https://cdn.example.com/lesson.mp4');
        expect(video?.getAttribute('controls')).not.toBeNull();
        expect(video?.querySelector('track')?.getAttribute('src')).toBe('https://cdn.example.com/lesson.vtt');
    });

    it('renders the Transcript, About and Downloads tabs from the fields the contract has', async () => {
        const lesson = flatLessonView(4, {
            type: 'video',
            title: 'Captions and transcripts',
            summary: 'Why captions ship on by default.',
            duration_seconds: 483,
            content: {
                provider: 'url',
                url: 'https://cdn.example.com/lesson.mp4',
                caption_url: 'https://cdn.example.com/lesson.vtt',
            },
        });
        renderPane(lesson);
        await screen.findByRole('tablist', { name: 'Lesson details' });
        const transcriptTab = screen.getByRole('tab', { name: 'Transcript' });
        expect(transcriptTab).toHaveAttribute('aria-selected', 'true');
        /* No transcript field exists in the contract: the empty state
           renders instead of invented data; captions are noted as fact. */
        expect(screen.getByText('No transcript yet')).toBeInTheDocument();
        expect(screen.getByText(/This video has captions/)).toBeInTheDocument();
        /* About carries the lesson summary the contract does have. */
        await userEvent.click(screen.getByRole('tab', { name: 'About this lesson' }));
        expect(screen.getByText('Why captions ship on by default.')).toBeInTheDocument();
        /* Downloads has no contract field either: honest empty state. */
        await userEvent.click(screen.getByRole('tab', { name: 'Downloads (0)' }));
        expect(screen.getByText('No downloads')).toBeInTheDocument();
    });

    it('shows the watched-progress hint in the footer as progress accrues', async () => {
        const lesson = flatLessonView(4, {
            type: 'video',
            title: 'Captions and transcripts',
            duration_seconds: 483,
            content: { provider: 'url', url: 'https://cdn.example.com/lesson.mp4' },
        });
        const { view } = renderPane(lesson);
        await screen.findByRole('heading', { name: 'Captions and transcripts' });
        const video = view.container.querySelector('video');
        expect(video).not.toBeNull();
        if (video !== null) {
            Object.defineProperty(video, 'duration', { configurable: true, get: () => 100 });
            Object.defineProperty(video, 'currentTime', { configurable: true, get: () => 62 });
            fireEvent.timeUpdate(video);
        }
        await screen.findByText('62% watched, keep going to complete');
    });
});

describe('resource pane', () => {
    const resourceLesson = flatLessonView(5, {
        type: 'resource',
        title: 'Colour and contrast checklist',
        content: {
            files: [
                {
                    name: 'contrast-checklist-v3.pdf',
                    size_bytes: 421888,
                    url: 'https://files.example.test/signed/contrast-checklist-v3.pdf?expires=3600&sig=abc',
                },
            ],
        },
    });

    it('lists files with size and a download link', async () => {
        renderPane(resourceLesson);
        await screen.findByText('contrast-checklist-v3.pdf');
        expect(screen.getByText('412 KB')).toBeInTheDocument();
        const link = screen.getByRole('link', { name: /Download/ });
        expect(link).toHaveAttribute(
            'href',
            'https://files.example.test/signed/contrast-checklist-v3.pdf?expires=3600&sig=abc',
        );
    });

    it('claims completion through the heartbeat when the file is downloaded', async () => {
        const { requests } = renderPane(resourceLesson);
        const link = await screen.findByRole('link', { name: /Download/ });
        link.addEventListener('click', (event) => event.preventDefault());
        await userEvent.click(link);
        await waitFor(() => {
            const beat = requests.find(
                (request) => request.method === 'POST' && request.path === '/v1/lessons/5/progress'
                    && (request.body as { completed?: boolean })?.completed,
            );
            expect(beat).toBeDefined();
            expect(beat?.body).toMatchObject({ enrolment_id: 3, completed: true, progress_percent: 100 });
        });
        /* The server confirmed: the downloaded strip renders. */
        await screen.findByText('Downloaded ✓');
        expect(screen.getByText(/The file stays available here any time/)).toBeInTheDocument();
    });

    it('does not mark completion from a successful write alone', async () => {
        const { requests } = renderPane(resourceLesson, { acceptCompletion: false });
        const link = await screen.findByRole('link', { name: /Download/ });
        link.addEventListener('click', event => event.preventDefault());
        await userEvent.click(link);
        await waitFor(() => expect(requests.filter(request =>
            request.method === 'GET' && request.path === '/v1/lessons/5')).toHaveLength(2));
        expect(screen.queryByText('Downloaded ✓')).not.toBeInTheDocument();
    });

    it('explains locked files instead of linking nowhere', async () => {
        const locked = flatLessonView(5, {
            type: 'resource',
            title: 'Colour and contrast checklist',
            content: { files: [{ name: 'contrast-checklist-v3.pdf', size_bytes: 421888 }] },
        });
        renderPane(locked);
        await screen.findByText('contrast-checklist-v3.pdf');
        expect(screen.queryByRole('link', { name: /Download/ })).toBeNull();
        expect(screen.getByText('Files unlock when you enrol.')).toBeInTheDocument();
    });

    it('never links a raw private storage path, only the signed url', async () => {
        const stale = flatLessonView(5, {
            type: 'resource',
            title: 'Colour and contrast checklist',
            content: {
                files: [{ name: 'contrast-checklist-v3.pdf', size_bytes: 421888, path: 'resources/private.pdf' }],
            },
        });
        renderPane(stale);
        await screen.findByText('contrast-checklist-v3.pdf');
        expect(screen.queryByRole('link', { name: /Download/ })).toBeNull();
        expect(document.querySelector('a[href*="private.pdf"]')).toBeNull();
    });
});

describe('assignment pane', () => {
    it('loads practical assignment rules and provides an in-page submission form', async () => {
        renderPane(flatLessonView(9, { type: 'assignment', content: { instructions: '<p>Upload your practical photos.</p>' } }));
        expect(await screen.findByText('Upload your practical photos.')).toBeInTheDocument();
        expect(await screen.findByLabelText('Choose photos or files')).toHaveAttribute('multiple');
        expect(screen.getByText('jpg, png')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Submit for assessment' })).toBeInTheDocument();
        expect(screen.queryByTestId('assignment-stages')).not.toBeInTheDocument();
    });
});

describe('quiz pane', () => {
    const quizLesson = flatLessonView(6, {
        type: 'quiz',
        title: 'Module 2 check',
        quiz: {
            id: 11,
            title: 'Module 2 check',
            description: null,
            pass_mark: 70,
            max_attempts: 3,
            attempts_used: 1,
            attempts_remaining: 2,
            time_limit_seconds: 1200,
            question_count: 10,
            show_results: 'after_submit',
            best_percentage: 60,
            last_attempt: { id: 21, status: 'graded', percentage: 60, passed: false, expires_at: null },
        },
    });

    it('shows the launch panel: stats, last attempt, best score and the start link', async () => {
        renderPane(quizLesson);
        await screen.findByRole('heading', { name: 'Module 2 check' });
        expect(screen.getByText('questions').parentElement).toHaveTextContent('10');
        expect(screen.getByText('time limit').parentElement).toHaveTextContent('20 min');
        expect(screen.getByText('pass mark').parentElement).toHaveTextContent('70%');
        expect(screen.getByText('attempts left').parentElement).toHaveTextContent('2 of 3');
        expect(screen.getByText('Attempt 1')).toBeInTheDocument();
        expect(screen.getByText('Not passed')).toBeInTheDocument();
        expect(screen.getByText('Best score: 60%')).toBeInTheDocument();
        expect(screen.getByText('The timer starts when you do')).toBeInTheDocument();
        expect(
            screen.getByText('Lesson 2.3 · completes when you pass with 70% or more'),
        ).toBeInTheDocument();
        const start = screen.getByRole('link', { name: /View assessment/ });
        expect(start).toHaveAttribute('href', '/course/web-accessibility?vy-quiz=6');
    });

    it('offers resume while an attempt is open', async () => {
        const withOpen = flatLessonView(6, {
            type: 'quiz',
            title: 'Module 2 check',
            quiz: {
                ...quizLesson.quiz!,
                last_attempt: { id: 22, status: 'in_progress', percentage: null, passed: null, expires_at: null },
            },
        });
        renderPane(withOpen);
        await screen.findByText('You have an attempt in progress. Your answers are saved.');
        expect(screen.getByRole('link', { name: /View assessment/ })).toBeInTheDocument();
    });

    it('renders the no-results-revealed presentation when show_results is never', async () => {
        const hidden = flatLessonView(6, {
            type: 'quiz',
            title: 'Module 2 check',
            quiz: {
                ...quizLesson.quiz!,
                show_results: 'never',
                best_percentage: null,
                last_attempt: { id: 21, status: 'graded', percentage: null, passed: null, expires_at: null },
            },
        });
        renderPane(hidden);
        await screen.findByText('Attempt 1');
        /* Recorded, not a fake zero, not a pending-grade promise. */
        expect(screen.getByText('Recorded')).toBeInTheDocument();
        expect(screen.queryByText('0%')).not.toBeInTheDocument();
        expect(screen.queryByText('Pending grade')).not.toBeInTheDocument();
        expect(screen.queryByText(/Best score/)).not.toBeInTheDocument();
        expect(
            screen.getByText('This quiz does not reveal scores. Attempts are recorded and count toward completion.'),
        ).toBeInTheDocument();
    });

    it('stays calm when no attempts are left', async () => {
        const spent = flatLessonView(6, {
            type: 'quiz',
            title: 'Module 2 check',
            quiz: { ...quizLesson.quiz!, attempts_used: 3, attempts_remaining: 0 },
        });
        renderPane(spent);
        await screen.findByText('No attempts left. Your best score stands.');
        expect(screen.queryByRole('link', { name: /Start attempt/ })).toBeNull();
        expect(screen.getByRole('button', { name: /Continue/ })).toBeInTheDocument();
    });
});

describe('a visitor without an enrolment', () => {
    /*
     * A guest in a free preview who opens a lesson the course does not
     * expose. The 401 is the server's answer to "may this visitor see it",
     * not a failed load, so a retry can never succeed and must not be
     * offered.
     */
    it('shows the permission card for a 401, never an error with a retry', async () => {
        renderPane(null, {
            lessonId: 4,
            lessonResponse: problemResponse(401, 'Unauthorized'),
            enrolment: null,
            guest: true,
        });
        expect(await screen.findByText("This lesson isn't available to you.")).toBeInTheDocument();
        expect(screen.getByText(/Sign in to continue\./)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View the course' })).toHaveAttribute('href', '/course/web-accessibility');
        expect(screen.queryByText("This lesson couldn't load")).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
    });

    it('says why for a 403: the lesson belongs to a course the visitor is not enrolled in', async () => {
        renderPane(null, {
            lessonId: 4,
            lessonResponse: problemResponse(403, 'Forbidden', 'An enrolment in the course is required to open this lesson.'),
            enrolment: null,
        });
        expect(await screen.findByText("This lesson isn't available to you.")).toBeInTheDocument();
        expect(screen.getByText(/It belongs to a course you're not enrolled in\./)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
    });

    it('previews an assignment brief without loading the submission workspace', async () => {
        const harness = renderPane(
            flatLessonView(9, {
                type: 'assignment',
                free_preview: true,
                content: { instructions: '<p>Upload your practical photos.</p>' },
            }),
            { enrolment: null, guest: true },
        );
        expect(await screen.findByText('Upload your practical photos.')).toBeInTheDocument();
        expect(screen.getByText('This is a free preview. Enrol in the course to submit your work.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Submit for assessment' })).not.toBeInTheDocument();
        expect(harness.requests.some((request) => request.path.startsWith('/v1/assignments/'))).toBe(false);
    });

    it('previews a quiz overview without inviting an attempt the server would refuse', async () => {
        renderPane(
            flatLessonView(6, {
                type: 'quiz',
                title: 'Module 2 check',
                free_preview: true,
                content: { quiz_id: 0 },
                quiz: {
                    id: 11,
                    title: 'Module 2 check',
                    description: null,
                    pass_mark: 70,
                    max_attempts: 3,
                    attempts_used: 0,
                    attempts_remaining: 3,
                    time_limit_seconds: null,
                    question_count: 10,
                    show_results: 'after_submit',
                    best_percentage: null,
                    last_attempt: null,
                },
            }),
            { enrolment: null, guest: true },
        );
        await screen.findByRole('heading', { name: 'Module 2 check' });
        expect(screen.getByText('questions').parentElement).toHaveTextContent('10');
        expect(screen.getByText('This is a free preview. Enrol in the course to take this quiz.')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /View assessment/ })).not.toBeInTheDocument();
    });

    it('tells a previewing visitor that nothing is saved instead of promising completion', async () => {
        const harness = renderPane(
            flatLessonView(8, { type: 'reading', title: 'Focus management', free_preview: true, content: { body: '<p>Preview body</p>' } }),
            { enrolment: null, guest: true },
        );
        await screen.findByText('Preview body');
        expect(screen.getByText('Free preview. Your progress is not saved.')).toBeInTheDocument();
        expect(screen.queryByText('Marks complete at the end of the page')).not.toBeInTheDocument();
        expect(harness.requests.some((request) => request.method === 'POST')).toBe(false);
    });
});

describe('Pro placeholder', () => {
    it('renders the neutral placeholder when the provider is not installed', async () => {
        const orphaned = flatLessonView(8, {
            type: 'scorm',
            title: 'Interactive module',
            content: null,
            content_available: false,
        });
        renderPane(orphaned);
        await screen.findByTestId('pro-placeholder');
        expect(screen.getByText('This lesson uses a scorm add-on')).toBeInTheDocument();
        expect(screen.getByText(/needs VidyaLMS Pro/)).toBeInTheDocument();
        /* Directory-safe: neutral tone, no buy link. */
        expect(screen.queryByRole('link', { name: /buy|upgrade|pro/i })).toBeNull();
    });
});

/** A LessonView whose id lines up with the shared curriculum fixture. */
function flatLessonView(id: number, overrides: Partial<LessonView>): LessonView {
    return {
        id,
        course_id: 1,
        module_id: 2,
        title: 'Lesson',
        slug: `lesson-${id}`,
        type: 'reading',
        summary: null,
        duration_seconds: null,
        position: 1,
        free_preview: false,
        access: { state: 'available', reason: null, reason_parameters: {}, opens_at: null, opens_at_timezone: null },
        content: null,
        content_available: true,
        progress: null,
        quiz: null,
        ...overrides,
    };
}
