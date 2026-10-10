import { describe, expect, it } from 'vitest';
import { dateTimeText, fileSize, initials, isApiDate, parseApiDate, pointsText, shortDate, waitingFor, wholePercent } from './format';
import { applyDrop, moveItem, orderAfterVisibleMove } from './reorder';
import { archiveMessage, isArchived, isNotArchived, RESTORED_STATE } from './archive';
import { tabFromHash } from './layout';
import {
    blankQuestionValues,
    linesOf,
    needsManualMarking,
    nextKey,
    questionDefinition,
    questionFormSchema,
    questionFormValues,
} from './question-definitions';
import { assignmentRequest, durationSeconds, lessonContent, lessonFormSchema, lessonFormValues } from './lesson-content';
import { makeLesson } from '../testing/harness';

const NOW = Date.UTC(2026, 8, 2, 12, 0, 0);

describe('format', () => {
    it('parses the contract datetime as UTC', () => {
        expect(parseApiDate('2026-09-01 10:30:00')).toBe(Date.UTC(2026, 8, 1, 10, 30, 0));
    });

    it('refuses anything that is not the contract datetime', () => {
        expect(parseApiDate('01/09/2026')).toBeNull();
        expect(parseApiDate(null)).toBeNull();
        expect(isApiDate('2026-09-01 10:30:00')).toBe(true);
        expect(isApiDate('2026-09-01')).toBe(false);
    });

    it('says how long something has been waiting in coarse words', () => {
        expect(waitingFor('2026-09-02 11:59:30', NOW)).toBe('just now');
        expect(waitingFor('2026-09-02 11:30:00', NOW)).toBe('30 minutes');
        expect(waitingFor('2026-09-02 06:00:00', NOW)).toBe('6 hours');
        expect(waitingFor('2026-08-29 12:00:00', NOW)).toBe('4 days');
        expect(waitingFor(null, NOW)).toBe('never');
    });

    it('shows the current day as today and other days as their date', () => {
        expect(shortDate('2026-09-02 06:00:00', NOW)).toBe('today');
        expect(shortDate('2026-08-14 09:00:00', NOW)).toBe('2026-08-14');
        expect(dateTimeText('2026-08-14 09:05:00')).toBe('2026-08-14 09:05');
    });

    it('formats file sizes and points the way the design reads them', () => {
        expect(fileSize(512)).toBe('512 bytes');
        expect(fileSize(2202009)).toBe('2.1 MB');
        expect(pointsText(1)).toBe('1');
        expect(pointsText(1.5)).toBe('1.5');
    });

    it('clamps a percentage to a whole number inside 0 to 100', () => {
        expect(wholePercent(83.4)).toBe(83);
        expect(wholePercent(-5)).toBe(0);
        expect(wholePercent(140)).toBe(100);
        expect(wholePercent(null)).toBe(0);
    });

    it('builds initials from a display name and survives a removed account', () => {
        expect(initials('Priya Nair')).toBe('PN');
        expect(initials('Ana')).toBe('A');
        expect(initials(null)).toBe('?');
    });
});

describe('reorder', () => {
    it('moves an item and leaves the rest in order', () => {
        expect(moveItem([1, 2, 3, 4], 0, 2)).toEqual([2, 3, 1, 4]);
        expect(moveItem([1, 2, 3, 4], 3, 0)).toEqual([4, 1, 2, 3]);
    });

    it('is a no-op for a move that goes nowhere or out of the list', () => {
        expect(moveItem([1, 2, 3], 1, 1)).toEqual([1, 2, 3]);
        expect(moveItem([1, 2, 3], 0, -1)).toEqual([1, 2, 3]);
        expect(moveItem([1, 2, 3], 0, 9)).toEqual([1, 2, 3]);
    });

    it('leaves the order alone when a drag is dropped outside the list', () => {
        expect(applyDrop([1, 2, 3], 0, null)).toEqual([1, 2, 3]);
        expect(applyDrop([1, 2, 3], null, 2)).toEqual([1, 2, 3]);
        expect(applyDrop([1, 2, 3], 0, 1)).toEqual([2, 1, 3]);
    });

    it('writes a move made among the visible rows over the whole list', () => {
        const full = ['a', 'hidden', 'b', 'c'];
        const visible = ['a', 'b', 'c'];
        /* b moves above a; the hidden row stays after a, where it was. */
        expect(orderAfterVisibleMove(full, visible, 1, 0)).toEqual(['b', 'a', 'hidden', 'c']);
        /* a moves to the end of what is visible. */
        expect(orderAfterVisibleMove(full, visible, 0, 2)).toEqual(['hidden', 'b', 'c', 'a']);
    });

    it('keeps every row of the list, because the order write carries all of them', () => {
        const full = ['a', 'hidden', 'b'];
        expect(orderAfterVisibleMove(full, ['a', 'b'], 0, 1)).toHaveLength(3);
    });

    it('leaves the list alone for a move that names a row it does not hold', () => {
        expect(orderAfterVisibleMove(['a', 'b'], ['a', 'b'], 0, 5)).toEqual(['a', 'b']);
        expect(orderAfterVisibleMove(['a', 'b'], ['x'], 0, 0)).toEqual(['a', 'b']);
    });
});

describe('archive', () => {
    it('reads the archived state, and only that state', () => {
        expect(isArchived({ state: 'archived' })).toBe(true);
        expect(isArchived({ state: 'draft' })).toBe(false);
        expect(isNotArchived({ state: 'published' })).toBe(true);
    });

    it('restores to a draft, so a restored row is never back in front of learners on its own', () => {
        expect(RESTORED_STATE).toBe('draft');
    });

    it('says what happened when a recompute is running over real enrolments', () => {
        const message = archiveMessage('Basics', 'module', {
            lessons_archived: 3,
            enrolments_affected: 2,
            progress_recompute: 'queued',
        });

        expect(message).toContain('Module Basics is archived.');
        expect(message).toContain('2 enrolments');
    });

    it('reads the singular from the count the server sent', () => {
        expect(
            archiveMessage('Welcome', 'lesson', {
                lessons_archived: 1,
                enrolments_affected: 1,
                progress_recompute: 'queued',
            }),
        ).toContain('1 enrolment;');
    });

    it('promises nothing further when no recompute was queued', () => {
        expect(
            archiveMessage('Welcome', 'lesson', {
                lessons_archived: 1,
                enrolments_affected: 0,
                progress_recompute: 'not_needed',
            }),
        ).toBe('Lesson Welcome is archived.');
    });

    it('does not talk about a recompute the server did not queue, however many are enrolled', () => {
        expect(
            archiveMessage('Welcome', 'lesson', {
                lessons_archived: 0,
                enrolments_affected: 40,
                progress_recompute: 'not_needed',
            }),
        ).toBe('Lesson Welcome is archived.');
    });
});

describe('tabFromHash', () => {
    const tabs = ['curriculum', 'quizzes', 'gradebook'] as const;

    it('reads the namespaced hash', () => {
        expect(tabFromHash('#vy/quizzes', tabs, 'curriculum')).toBe('quizzes');
    });

    it('falls back for a hash that belongs to the host page', () => {
        expect(tabFromHash('#comments', tabs, 'curriculum')).toBe('curriculum');
        expect(tabFromHash('', tabs, 'curriculum')).toBe('curriculum');
    });

    it('falls back for a tab this app does not have', () => {
        expect(tabFromHash('#vy/reports', tabs, 'curriculum')).toBe('curriculum');
    });
});

describe('question definitions', () => {
    it('reads a single choice definition into the form model and back unchanged', () => {
        const definition = {
            prompt: 'Which audience needs captions?',
            options: [
                { key: 'a', label: 'Sound-off viewers' },
                { key: 'b', label: 'Deaf and hard-of-hearing viewers' },
            ],
            correct: 'b',
            feedback: 'Revisit the captions lesson.',
        };
        const values = questionFormValues('single_choice', definition);
        expect(values.options.map((option) => option.correct)).toEqual([false, true]);
        expect(questionDefinition('single_choice', values)).toEqual(definition);
    });

    /*
     * The choice shape the provider actually validates: options are a key
     * and a label, correctness is the separate "correct" member (a key for
     * single choice, a list of keys for multiple), and feedback belongs to
     * the question. Locked down here because the contract's prose about it
     * was wrong once.
     */
    it('writes a choice option as a key and a label, with correctness and feedback beside them', () => {
        const values = questionFormValues('single_choice', {
            prompt: 'Which audience needs captions?',
            options: [
                { key: 'a', label: 'Sound-off viewers' },
                { key: 'b', label: 'Deaf and hard-of-hearing viewers' },
            ],
            correct: 'b',
            feedback: 'Revisit the captions lesson.',
        });
        const definition = questionDefinition('single_choice', values);

        expect(definition.options).toEqual([
            { key: 'a', label: 'Sound-off viewers' },
            { key: 'b', label: 'Deaf and hard-of-hearing viewers' },
        ]);
        expect(definition.correct).toBe('b');
        expect(definition.feedback).toBe('Revisit the captions lesson.');
    });

    it('keeps no per-option feedback, which no free question type stores', () => {
        const values = questionFormValues('multiple_choice', {
            prompt: 'Which need text alternatives?',
            options: [
                { key: 'a', label: 'Informative images', feedback: 'not a field the provider knows' },
                { key: 'b', label: 'Icon buttons' },
            ],
            correct: ['a'],
        });
        const options = questionDefinition('multiple_choice', values).options;

        expect(options).toEqual([
            { key: 'a', label: 'Informative images' },
            { key: 'b', label: 'Icon buttons' },
        ]);
    });

    it('keeps every correct key of a multiple choice question', () => {
        const definition = {
            prompt: 'Which need text alternatives?',
            options: [
                { key: 'a', label: 'Informative images' },
                { key: 'b', label: 'Decorative images' },
                { key: 'c', label: 'Icon buttons' },
            ],
            correct: ['a', 'c'],
        };
        const values = questionFormValues('multiple_choice', definition);
        expect(values.options.filter((option) => option.correct).map((option) => option.key)).toEqual(['a', 'c']);
        expect(questionDefinition('multiple_choice', values).correct).toEqual(['a', 'c']);
    });

    it('carries the boolean answer of a true or false question', () => {
        expect(questionFormValues('true_false', { prompt: 'x', correct: true }).correctBool).toBe('true');
        expect(questionFormValues('true_false', { prompt: 'x', correct: false }).correctBool).toBe('false');
        const values = questionFormValues('true_false', { prompt: 'x', correct: false });
        expect(questionDefinition('true_false', values)).toEqual({ prompt: 'x', correct: false });
    });

    it('treats a short answer with no accepted answers as one a human marks', () => {
        expect(needsManualMarking('short_answer', { prompt: 'x', accepted: [] })).toBe(true);
        expect(needsManualMarking('short_answer', { prompt: 'x', accepted: ['focus'] })).toBe(false);
        expect(needsManualMarking('single_choice', { prompt: 'x' })).toBe(false);
    });

    it('writes accepted answers as a list and keeps the case flag', () => {
        const values = questionFormValues('short_answer', {
            prompt: 'Why?',
            accepted: ['Focus', 'focus ring'],
            case_sensitive: true,
        });
        expect(values.accepted).toBe('Focus\nfocus ring');
        expect(questionDefinition('short_answer', values)).toEqual({
            prompt: 'Why?',
            accepted: ['Focus', 'focus ring'],
            case_sensitive: true,
        });
    });

    it('reads fill-blank blanks with their accepted values', () => {
        const values = questionFormValues('fill_blank', {
            prompt: 'Normal text needs [[ratio]]:1.',
            blanks: [{ key: 'ratio', accepted: ['4.5'] }],
        });
        expect(values.blanks).toEqual([{ key: 'ratio', accepted: '4.5' }]);
        expect(questionDefinition('fill_blank', values).blanks).toEqual([{ key: 'ratio', accepted: ['4.5'] }]);
    });

    it('shows ordering items in their stored correct sequence', () => {
        const values = questionFormValues('ordering', {
            prompt: 'Order the audit',
            items: [
                { key: 'auto', label: 'Run automated checks' },
                { key: 'sample', label: 'Define the sample' },
            ],
            correct: ['sample', 'auto'],
        });
        expect(values.items.map((item) => item.key)).toEqual(['sample', 'auto']);
        expect(questionDefinition('ordering', values).correct).toEqual(['sample', 'auto']);
    });

    /*
     * The workbench owns the prompt, the feedback and whatever its block
     * declares. Everything else in a stored definition belongs to whoever
     * wrote it and must survive an open-and-save.
     *
     * Rebuilding the definition from the form alone deleted every one of
     * these on every save, silently: a new multiple-choice item reverted from
     * guess_neutral to plus_minus and a new ordering item from
     * chance-corrected Kendall tau to raw absolute position, because a
     * definition naming no policy falls through to the legacy pin. An item
     * authored human-marked became machine-marked, an item the author fixed in
     * order started shuffling again, and the selection constraints and their
     * authored messages went with them.
     */
    it('keeps every stored key the authoring block does not own', () => {
        const stored = {
            policy: { key: 'guess_neutral' },
            prompt: 'Pick some.',
            options: [
                { key: 'a', label: 'A' },
                { key: 'b', label: 'B' },
                { key: 'c', label: 'C' },
                { key: 'd', label: 'D' },
            ],
            correct: ['a', 'b', 'c'],
            min_selections: 1,
            max_selections: 3,
            min_message: 'Choose at least one.',
            max_message: 'That is too many.',
            scoring_intent: 'machine',
            shuffle: false,
            layout_classes: ['qti-choices-stacking-2'],
        };

        const saved = questionDefinition(
            'multiple_choice',
            questionFormValues('multiple_choice', stored),
            stored,
        );

        expect(saved.policy).toEqual({ key: 'guess_neutral' });
        expect(saved.min_selections).toBe(1);
        expect(saved.max_selections).toBe(3);
        expect(saved.min_message).toBe('Choose at least one.');
        expect(saved.max_message).toBe('That is too many.');
        expect(saved.scoring_intent).toBe('machine');
        expect(saved.shuffle).toBe(false);
        expect(saved.layout_classes).toEqual(['qti-choices-stacking-2']);
        expect(saved.correct).toEqual(['a', 'b', 'c']);
    });

    it('keeps an ordering item on its chance-corrected policy and its fixed order', () => {
        const stored = {
            policy: { key: 'kendall_tau' },
            prompt: 'Order these.',
            items: [
                { key: 'A', label: 'A' },
                { key: 'B', label: 'B' },
            ],
            correct: ['A', 'B'],
            shuffle: false,
        };

        const saved = questionDefinition('ordering', questionFormValues('ordering', stored), stored);

        expect(saved.policy).toEqual({ key: 'kendall_tau' });
        expect(saved.shuffle).toBe(false);
    });

    /*
     * A key the block does own is still replaced by what the form produced,
     * which is what lets a field be cleared rather than being merged over
     * for ever.
     */
    it('still replaces the keys the block owns, including clearing one', () => {
        const stored = {
            prompt: 'Explain.',
            accepted: ['water', 'H2O'],
            case_sensitive: true,
            feedback: 'Well done.',
            policy: { key: 'text_match' },
        };
        const values = questionFormValues('short_answer', stored);
        values.accepted = '';
        values.caseSensitive = false;
        values.feedback = '';

        const saved = questionDefinition('short_answer', values, stored);

        expect(saved.accepted).toEqual([]);
        expect(saved.case_sensitive).toBe(false);
        expect(saved.feedback).toBeUndefined();
        expect(saved.policy).toEqual({ key: 'text_match' });
    });

    it('hands an unregistered type its stored definition back untouched', () => {
        const stored = { prompt: 'A matrix question', rows: [{ key: 'r1' }] };
        const values = questionFormValues('matrix', stored);
        expect(questionDefinition('matrix', values, stored)).toEqual(stored);
    });

    it('gives a new question the smallest shape its provider accepts', () => {
        expect(blankQuestionValues('single_choice').options).toHaveLength(2);
        expect(blankQuestionValues('single_choice').options[0]?.correct).toBe(true);
        expect(blankQuestionValues('ordering').items).toHaveLength(2);
        expect(blankQuestionValues('fill_blank').blanks).toHaveLength(1);
    });

    it('never reuses a key a sibling already holds', () => {
        expect(nextKey([{ key: 'opt1' }, { key: 'opt2' }], 'opt')).toBe('opt3');
        expect(nextKey([{ key: 'opt3' }], 'opt')).toBe('opt2');
    });

    it('drops blank lines when reading a textarea list', () => {
        expect(linesOf(' a \n\n b\n')).toEqual(['a', 'b']);
    });
});

describe('question validation', () => {
    const base = blankQuestionValues('single_choice');

    it('requires a prompt', () => {
        const result = questionFormSchema('single_choice').safeParse({ ...base, prompt: '  ' });
        expect(result.success).toBe(false);
    });

    it('requires exactly one correct option on a single choice question', () => {
        const schema = questionFormSchema('single_choice');
        const options = [
            { key: 'a', label: 'A', correct: true },
            { key: 'b', label: 'B', correct: true },
        ];
        expect(schema.safeParse({ ...base, prompt: 'p', options }).success).toBe(false);
        expect(
            schema.safeParse({
                ...base,
                prompt: 'p',
                options: [options[0] as (typeof options)[number], { key: 'b', label: 'B', correct: false }],
            }).success,
        ).toBe(true);
    });

    it('requires at least one correct option on a multiple choice question', () => {
        const schema = questionFormSchema('multiple_choice');
        const values = {
            ...base,
            prompt: 'p',
            options: [
                { key: 'a', label: 'A', correct: false },
                { key: 'b', label: 'B', correct: false },
            ],
        };
        expect(schema.safeParse(values).success).toBe(false);
    });

    it('requires every blank to have its marker in the prompt and an accepted answer', () => {
        const schema = questionFormSchema('fill_blank');
        const missingMarker = {
            ...blankQuestionValues('fill_blank'),
            prompt: 'Normal text needs 4.5:1.',
            blanks: [{ key: 'ratio', accepted: '4.5' }],
        };
        expect(schema.safeParse(missingMarker).success).toBe(false);
        const noAccepted = {
            ...blankQuestionValues('fill_blank'),
            prompt: 'Normal text needs [[ratio]]:1.',
            blanks: [{ key: 'ratio', accepted: '' }],
        };
        expect(schema.safeParse(noAccepted).success).toBe(false);
        const good = {
            ...blankQuestionValues('fill_blank'),
            prompt: 'Normal text needs [[ratio]]:1.',
            blanks: [{ key: 'ratio', accepted: '4.5' }],
        };
        expect(schema.safeParse(good).success).toBe(true);
    });

    it('requires points above zero', () => {
        expect(questionFormSchema('true_false').safeParse({ ...base, prompt: 'p', points: '0' }).success).toBe(false);
        expect(questionFormSchema('true_false').safeParse({ ...base, prompt: 'p', points: '2' }).success).toBe(true);
    });
});

describe('lesson content', () => {
    it('reads a video lesson into the form and writes the provider payload back', () => {
        const values = lessonFormValues(
            makeLesson({ id: 2, duration_seconds: 300, content: { provider: 'vimeo', url: 'https://v/1', caption_url: null } }),
        );
        expect(values.provider).toBe('vimeo');
        expect(values.durationMinutes).toBe('5');
        expect(lessonContent('video', values)).toEqual({
            provider: 'vimeo',
            url: 'https://v/1',
            caption_url: null,
        });
        expect(durationSeconds(values)).toBe(300);
    });

    it('writes a resource lesson with its private storage paths', () => {
        const values = lessonFormValues(
            makeLesson({
                id: 3,
                type: 'resource',
                content: { files: [{ path: 'private/a.pdf', name: 'a.pdf', size_bytes: 10 }] },
            }),
        );
        expect(lessonContent('resource', values)).toEqual({
            files: [{ path: 'private/a.pdf', name: 'a.pdf', size_bytes: 10 }],
        });
    });

    it('sends no content for a type it has no block for, so nothing is dropped', () => {
        const values = lessonFormValues(makeLesson({ id: 4, type: 'scorm', content: { package: 'x.zip' } }));
        expect(lessonContent('scorm', values)).toBeNull();
    });

    it('builds the assignment row as a full replacement', () => {
        const values = lessonFormValues(
            makeLesson({
                id: 5,
                type: 'assignment',
                content: { instructions: 'Audit a page' },
                assignment: {
                    lesson_id: 5,
                    instructions: 'Audit a page',
                    max_points: 50,
                    allowed_file_types: ['pdf'],
                    max_file_size_kb: 2048,
                    max_files: 2,
                    due_at: '2026-09-30 23:59:00',
                    allow_resubmission: true,
            pass_percentage: null,
            rubric: [],
                },
            }),
        );
        expect(assignmentRequest({ ...values, allowedTypes: '.PDF, zip' })).toEqual({
            instructions: 'Audit a page',
            max_points: 50,
            allowed_file_types: ['pdf', 'zip'],
            max_file_size_kb: 2048,
            max_files: 2,
            due_at: '2026-09-30 23:59:00',
            allow_resubmission: true,
            pass_percentage: null,
            rubric: [],
        });
    });

    it('requires an http address on a video lesson and a body on a reading', () => {
        const values = lessonFormValues(makeLesson({ id: 6, duration_seconds: 300 }));
        expect(lessonFormSchema('video').safeParse({ ...values, url: 'not a url' }).success).toBe(false);
        expect(lessonFormSchema('video').safeParse({ ...values, url: 'https://ok/1' }).success).toBe(true);
        expect(lessonFormSchema('reading').safeParse({ ...values, body: '' }).success).toBe(false);
        expect(lessonFormSchema('reading').safeParse({ ...values, body: '<p>Hello</p>' }).success).toBe(true);
    });

    it('refuses a due date that is not the contract datetime', () => {
        const values = lessonFormValues(makeLesson({ id: 7, type: 'assignment' }));
        expect(lessonFormSchema('assignment').safeParse({ ...values, dueAt: 'tomorrow' }).success).toBe(false);
        expect(lessonFormSchema('assignment').safeParse({ ...values, dueAt: '' }).success).toBe(true);
    });
});
