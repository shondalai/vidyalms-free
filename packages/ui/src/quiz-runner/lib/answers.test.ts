import { describe, expect, it } from 'vitest';
import { answeredStatus, compressQuestionNumbers, formatPoints, isAnswered } from './answers';

describe('isAnswered', () => {
    it('judges each wire shape by its own type', () => {
        expect(isAnswered('single_choice', 'aa')).toBe(true);
        expect(isAnswered('single_choice', '')).toBe(false);
        expect(isAnswered('multiple_choice', ['a'])).toBe(true);
        expect(isAnswered('multiple_choice', [])).toBe(false);
        expect(isAnswered('true_false', false)).toBe(true);
        expect(isAnswered('true_false', null)).toBe(false);
        expect(isAnswered('short_answer', 'because focus')).toBe(true);
        expect(isAnswered('short_answer', '   ')).toBe(false);
        expect(isAnswered('fill_blank', { a: '4.5', b: '' })).toBe(true);
        expect(isAnswered('fill_blank', { a: ' ' })).toBe(false);
        expect(isAnswered('ordering', ['b', 'a'])).toBe(true);
        expect(isAnswered('ordering', undefined)).toBe(false);
    });

    /*
     * The old switch returned false for every key it did not know, so a
     * question with a working third-party renderer read as permanently
     * unanswered in the palette and tripped the submit warning. The
     * shape-keyed fallback answers from the response itself instead.
     */
    it('counts an unregistered type as answered when its stored response is not empty', () => {
        expect(isAnswered('x_acme_molecule', 'anything')).toBe(true);
        expect(isAnswered('x_acme_molecule', [{ x: 10, y: 20 }])).toBe(true);
        expect(isAnswered('x_acme_molecule', null)).toBe(false);
        expect(isAnswered('x_acme_molecule', [])).toBe(false);
        expect(isAnswered('x_acme_molecule', { base: null })).toBe(false);
    });
});

describe('answeredStatus', () => {
    it('separates an unseen item from a seen but skipped one', () => {
        expect(answeredStatus('single_choice', null, { presented: false })).toBe('notpresented');
        expect(answeredStatus('single_choice', null, { presented: true })).toBe('presented');
        expect(answeredStatus('single_choice', 'aa', { presented: true })).toBe('answered');
    });

    it('separates a responded but invalid answer from a valid one', () => {
        const errors = [{ controlId: 'c', kind: 'TOO_MANY_SELECTED', message: 'too many' }];
        expect(answeredStatus('multiple_choice', ['a', 'b'], { errors })).toBe('attempted');
        expect(answeredStatus('multiple_choice', ['a', 'b'], { errors: [] })).toBe('answered');
    });
});

describe('compressQuestionNumbers', () => {
    it('compresses runs of three or more and lists shorter runs, like the artboard', () => {
        expect(compressQuestionNumbers([1, 2, 3, 5, 6, 7, 9, 10])).toBe('Q1-Q3, Q5-Q7, Q9, Q10');
        expect(compressQuestionNumbers([4])).toBe('Q4');
        expect(compressQuestionNumbers([2, 1, 3])).toBe('Q1-Q3');
        expect(compressQuestionNumbers([])).toBe('');
    });
});

describe('formatPoints', () => {
    it('drops trailing zeros', () => {
        expect(formatPoints(7.8)).toBe('7.8');
        expect(formatPoints(9)).toBe('9');
        expect(formatPoints(0.5)).toBe('0.5');
        expect(formatPoints(1.0)).toBe('1');
    });
});
