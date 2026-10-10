// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { lessonContent, lessonFormValues } from './lib/lesson-content';
import { readPresentation } from '@/shared/lesson-presentation';
import { prepareReading } from '@/player/lesson-reading';
import { visualCompatible } from './components/article-editor';
import { makeLesson } from './testing/harness';

describe('course studio data safety', () => {
    it('preserves provider fields and allows resetting a saved delivery override to the default', () => {
        const lesson = makeLesson({
            id: 10,
            type: 'video',
            content: {
                provider: 'url',
                url: 'https://example.test/video.mp4',
                caption_url: null,
                provider_revision: 7,
                presentation: {
                    resume: false,
                    transcript: 'Hello learners',
                    addon_metadata: { license: 'team' },
                },
            },
        });
        const values = lessonFormValues(lesson);
        values.presentation.resume = true;
        const content = lessonContent(lesson.type, values, lesson.content);
        expect(content).toMatchObject({
            provider_revision: 7,
            presentation: {
                transcript: 'Hello learners',
                addon_metadata: { license: 'team' },
            },
        });
        expect(content?.presentation).not.toHaveProperty('resume');
        expect(readPresentation(content).resume).toBe(true);
    });

    it('does not trust malformed delivery values or unsafe caption addresses', () => {
        const value = readPresentation({
            presentation: {
                resume: 'false',
                reading_width: 'tiny',
                captions: [{ label: 'English', language: 'en', url: 'javascript:alert(1)' }],
            },
        });
        expect(value.resume).toBe(true);
        expect(value.reading_width).toBe('comfortable');
        expect(value.captions).toEqual([]);
    });

    it('sanitizes article content before generating unique contents links', () => {
        const article = prepareReading(
            '<h2 id="other">Getting started</h2><h3>Next</h3><img src=x onerror="alert(1)"><script>bad()</script>',
            42,
        );
        expect(article.headings.map((heading) => heading.id)).toEqual(['vy-reading-42-0', 'vy-reading-42-1']);
        expect(article.html).not.toMatch(/onerror|<script/);
        expect(visualCompatible('<table><tr><td>Existing layout</td></tr></table>')).toBe(false);
        expect(visualCompatible('<p style="color:red">Custom formatting</p>')).toBe(false);
        expect(visualCompatible('<h2>Start</h2><p>Learn <strong>more</strong>.</p>')).toBe(true);
    });
});

