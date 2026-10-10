import { describe, expect, it } from 'vitest';
import {
    parseBlankPrompt,
    parseChoiceDefinition,
    parseFillBlankDefinition,
    parseOrderingDefinition,
    parsePromptDefinition,
} from './definitions';

describe('parseBlankPrompt', () => {
    it('splits the prompt into text and blank segments in reading order', () => {
        const parsed = parseBlankPrompt('Needs [[a]]:1 and [[b]]:1.', [{ key: 'a' }, { key: 'b' }]);
        expect(parsed.blankCount).toBe(2);
        expect(parsed.segments).toEqual([
            { kind: 'text', text: 'Needs ' },
            { kind: 'blank', key: 'a', index: 1 },
            { kind: 'text', text: ':1 and ' },
            { kind: 'blank', key: 'b', index: 2 },
            { kind: 'text', text: ':1.' },
        ]);
    });

    it('keeps a marker literal when its key is not a declared blank', () => {
        const parsed = parseBlankPrompt('Beware [[rogue]] markers of [[a]].', [{ key: 'a' }]);
        expect(parsed.blankCount).toBe(1);
        expect(parsed.segments).toEqual([
            { kind: 'text', text: 'Beware [[rogue]] markers of ' },
            { kind: 'blank', key: 'a', index: 1 },
            { kind: 'text', text: '.' },
        ]);
    });

    it('appends a declared blank whose marker is missing so it stays answerable', () => {
        const parsed = parseBlankPrompt('Only [[a]] appears.', [{ key: 'a' }, { key: 'lost' }]);
        expect(parsed.blankCount).toBe(2);
        expect(parsed.segments.at(-1)).toEqual({ kind: 'blank', key: 'lost', index: 2 });
    });

    it('counts a repeated marker once', () => {
        const parsed = parseBlankPrompt('[[a]] then [[a]] again.', [{ key: 'a' }]);
        expect(parsed.blankCount).toBe(1);
        expect(parsed.segments.filter((s) => s.kind === 'blank')).toHaveLength(1);
    });
});

describe('definition parsers', () => {
    it('accepts the learner shapes the server emits', () => {
        expect(
            parseChoiceDefinition({ prompt: 'P', options: [{ key: 'a', label: 'A' }] }),
        ).toEqual({ prompt: 'P', options: [{ key: 'a', label: 'A' }] });
        expect(parsePromptDefinition({ prompt: 'P' })).toEqual({ prompt: 'P' });
        expect(parseFillBlankDefinition({ prompt: 'P [[a]]', blanks: [{ key: 'a' }] })).toEqual({
            prompt: 'P [[a]]',
            blanks: [{ key: 'a' }],
        });
        expect(
            parseOrderingDefinition({ prompt: 'P', items: [{ key: 'a', label: 'A' }] }),
        ).toEqual({ prompt: 'P', items: [{ key: 'a', label: 'A' }] });
    });

    it('returns null on malformed payloads instead of crashing the attempt', () => {
        expect(parseChoiceDefinition({ options: [{ key: 'a', label: 'A' }] })).toBeNull();
        expect(parseChoiceDefinition({ prompt: 'P', options: [] })).toBeNull();
        expect(parseChoiceDefinition({ prompt: 'P', options: [{ label: 'A' }] })).toBeNull();
        expect(parsePromptDefinition({})).toBeNull();
        expect(parseFillBlankDefinition({ prompt: 'P', blanks: [] })).toBeNull();
        expect(parseFillBlankDefinition({ prompt: 'P', blanks: [{}] })).toBeNull();
        expect(parseOrderingDefinition({ prompt: 'P', items: 'nope' })).toBeNull();
    });
});
