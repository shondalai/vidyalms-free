import { describe, expect, it } from 'vitest';
import { parseQuestionBankRoute, questionBankHash } from './routes';

describe('question bank routes', () => {
    it('parses every durable resource route', () => {
        expect(parseQuestionBankRoute('')).toMatchObject({ kind: 'banks' });
        expect(parseQuestionBankRoute('/new')).toEqual({ kind: 'new-bank' });
        expect(parseQuestionBankRoute('/banks/7')).toEqual({ kind: 'edit-bank', bankId: 7 });
        expect(parseQuestionBankRoute('/banks/7/questions?status=ready&page=3')).toMatchObject({
            kind: 'questions',
            bankId: 7,
        });
        expect(parseQuestionBankRoute('/banks/7/questions/new?type=likert')).toEqual({
            kind: 'new-question',
            bankId: 7,
            preset: 'likert',
        });
        expect(parseQuestionBankRoute('/banks/7/questions/42/edit')).toEqual({
            kind: 'edit-question',
            bankId: 7,
            questionId: 42,
        });
    });

    it('rejects invalid identifiers and unknown paths', () => {
        expect(parseQuestionBankRoute('/banks/0')).toEqual({ kind: 'not-found' });
        expect(parseQuestionBankRoute('/banks/abc/questions')).toEqual({ kind: 'not-found' });
        expect(parseQuestionBankRoute('/banks/1/questions/-2/edit')).toEqual({ kind: 'not-found' });
        expect(parseQuestionBankRoute('/questions')).toEqual({ kind: 'not-found' });
    });

    it('encodes stable filter hashes and omits inactive values', () => {
        expect(questionBankHash('/banks/7/questions', {
            q: 'civil rights', status: 'ready', page: 2, descendants: true, tag: '', owner: null,
        })).toBe('/banks/7/questions?q=civil+rights&status=ready&page=2&descendants=true');
    });
});
