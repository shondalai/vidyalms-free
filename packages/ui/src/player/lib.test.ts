import { describe, expect, it } from 'vitest';
import { MOUNT_CONTRACT } from '@/shared/config';
import type { Curriculum } from '@/shared/api';
import type { MountConfig } from '@/shared/config';
import {
    courseRuleText,
    fileExt,
    flattenCurriculum,
    formatBytes,
    formatDuration,
    neighbours,
    quizRunnerHref,
    resolveInitialLessonId,
    videoEmbedSrc,
} from './lib';

function lesson(id: number, status: 'not_started' | 'in_progress' | 'completed' | null) {
    return {
        id,
        title: `Lesson ${id}`,
        slug: `lesson-${id}`,
        type: 'reading',
        summary: null,
        duration_seconds: null,
        position: id,
        free_preview: false,
        access: { state: 'available' as const, reason: null, reason_parameters: {}, opens_at: null, opens_at_timezone: null },
        progress:
            status === null
                ? null
                : {
                      lesson_id: id,
                      status,
                      progress_percent: status === 'completed' ? 100 : 40,
                      seconds_spent: 60,
                      position_seconds: null,
                      first_accessed_at: null,
                      completed_at: null,
                      last_heartbeat_at: null,
                  },
    };
}

function curriculum(states: ('not_started' | 'in_progress' | 'completed' | null)[]): Curriculum {
    return {
        course_id: 1,
        enrolment: { id: 3, status: 'active', progress_percent: 25 },
        modules: [
            {
                id: 1,
                title: 'Getting started',
                summary: null,
                position: 1,
                access: { state: 'available' as const, reason: null, reason_parameters: {}, opens_at: null, opens_at_timezone: null },
                lessons: states.slice(0, 2).map((state, index) => lesson(index + 1, state)),
            },
            {
                id: 2,
                title: 'Perceivable content',
                summary: null,
                position: 2,
                access: { state: 'available' as const, reason: null, reason_parameters: {}, opens_at: null, opens_at_timezone: null },
                lessons: states.slice(2).map((state, index) => lesson(index + 3, state)),
            },
        ],
    };
}

const config: MountConfig = {
    contract: MOUNT_CONTRACT,
    api: { base: '/v1', mode: 'prefix', headers: {} },
    course: { id: 1, slug: 'demo' },
    lessonId: null,
    user: { id: 42, name: 'Priya' },
    locale: 'en-GB',
    idleTimeoutSeconds: 300,
    urls: { course: '/course/demo', myLearning: '/my-learning' },
};

describe('flattenCurriculum and numbering', () => {
    it('numbers lessons by rendered module and lesson order', () => {
        const flat = flattenCurriculum(curriculum(['completed', 'completed', 'in_progress', null]));
        expect(flat).toHaveLength(4);
        expect(flat[2]?.moduleNumber).toBe(2);
        expect(flat[2]?.lessonNumber).toBe(1);
        expect(flat[3]?.lessonNumber).toBe(2);
    });
});

describe('resolveInitialLessonId', () => {
    it('honours a deep link that exists', () => {
        expect(resolveInitialLessonId(curriculum([null, null, null, null]), 3)).toBe(3);
    });

    it('falls back from an unknown deep link to the resume rules', () => {
        expect(resolveInitialLessonId(curriculum(['completed', 'in_progress', null, null]), 999)).toBe(2);
    });

    it('resumes at the first in-progress lesson', () => {
        expect(resolveInitialLessonId(curriculum(['completed', null, 'in_progress', null]), null)).toBe(3);
    });

    it('otherwise opens the first incomplete lesson', () => {
        expect(resolveInitialLessonId(curriculum(['completed', 'completed', null, null]), null)).toBe(3);
    });

    it('opens the first lesson of a fully completed course', () => {
        expect(
            resolveInitialLessonId(curriculum(['completed', 'completed', 'completed', 'completed']), null),
        ).toBe(1);
    });
});

describe('neighbours', () => {
    it('walks the flat order across module boundaries', () => {
        const flat = flattenCurriculum(curriculum([null, null, null, null]));
        const { prev, next } = neighbours(flat, 2);
        expect(prev?.lesson.id).toBe(1);
        expect(next?.lesson.id).toBe(3);
        expect(neighbours(flat, 1).prev).toBeNull();
        expect(neighbours(flat, 4).next).toBeNull();
    });
});

describe('formatters', () => {
    it('formats durations per type', () => {
        expect(formatDuration(480, 'video')).toBe('8 min');
        expect(formatDuration(360, 'reading')).toBe('6 min read');
        expect(formatDuration(null, 'video')).toBeNull();
    });

    it('formats file sizes and extensions', () => {
        expect(formatBytes(421_888)).toBe('412 KB');
        expect(formatBytes(3_250_585)).toBe('3.1 MB');
        expect(formatBytes(null)).toBeNull();
        expect(fileExt('contrast-checklist-v3.pdf')).toBe('PDF');
        expect(fileExt('archive')).toBe('FILE');
    });

    it('phrases the course completion rule', () => {
        const base = {
            id: 1,
            title: 'T',
            slug: 't',
            summary: null,
            image: null,
            category_id: null,
            level: null,
            language: null,
            price_mode: 'free',
            featured: false,
            published_at: null,
            description: null,
            visibility: 'public' as const,
            state: 'published' as const,
            completion_threshold: null,
            price: null,
            purchase_url: null,
            price_reason: null,
        };
        expect(courseRuleText({ ...base, completion_rule: 'all_lessons' })).toBe(
            'completes when all lessons are done',
        );
        expect(
            courseRuleText({ ...base, completion_rule: 'percentage', completion_threshold: 80 }),
        ).toBe('completes at 80% progress');
        expect(courseRuleText({ ...base, completion_rule: 'pass_final' })).toBe(
            'completes when you pass the final quiz',
        );
    });
});

describe('videoEmbedSrc', () => {
    it('builds privacy-friendly YouTube embeds from the common URL shapes', () => {
        expect(videoEmbedSrc('youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')).toBe(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        );
        expect(videoEmbedSrc('youtube', 'https://youtu.be/dQw4w9WgXcQ')).toBe(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        );
        expect(videoEmbedSrc('youtube', 'https://www.youtube.com/embed/dQw4w9WgXcQ')).toBe(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        );
    });

    it('builds Vimeo player embeds and rejects what it cannot parse', () => {
        expect(videoEmbedSrc('vimeo', 'https://vimeo.com/76979871')).toBe(
            'https://player.vimeo.com/video/76979871',
        );
        expect(videoEmbedSrc('youtube', 'https://example.com/watch?v=abc')).toBeNull();
        expect(videoEmbedSrc('vimeo', 'not a url')).toBeNull();
    });
});

describe('quizRunnerHref', () => {
    it('appends the vy-quiz parameter to the course URL', () => {
        expect(quizRunnerHref(config, 9)).toBe('/course/demo?vy-quiz=9');
    });

    it('keeps existing query strings and fragments intact', () => {
        const withQuery = { ...config, urls: { ...config.urls, course: '/index.php?option=com_vidyalms&view=course#top' } };
        expect(quizRunnerHref(withQuery, 9)).toBe('/index.php?option=com_vidyalms&view=course&vy-quiz=9#top');
    });
});
