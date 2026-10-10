import { describe, expect, it } from 'vitest';
import { fillSentence, placeholderValues, readSentence } from './sentences';
import { fieldsOf } from './params-form';
import { parseSubjectIds } from './preview';

describe('the rehearsal subject list', () => {
    it('reads a comma separated list of ids', () => {
        expect(parseSubjectIds('482, 900')).toEqual([482, 900]);
        expect(parseSubjectIds('482')).toEqual([482]);
    });

    it('drops anything that is not a positive id rather than sending it', () => {
        expect(parseSubjectIds('482, 0, -1, abc, 1.5, ')).toEqual([482]);
        expect(parseSubjectIds('')).toEqual([]);
    });

    it('names one subject once, however many times it is typed', () => {
        expect(parseSubjectIds('7, 7, 7')).toEqual([7]);
    });
});

describe('sentences rather than expressions', () => {
    it('handles localization keys returned by installed providers without exposing them as copy', () => {
        expect(readSentence('vidyalms.automation.action.notify.sentence', 'Notify')).toBe('Notify');
        expect(readSentence('Notify the learner', 'Notify')).toBe('Notify the learner');
        expect(placeholderValues('vidyalms.automation.action.notify.sentence', { recipient: 7 })).toEqual([]);
    });
    it('fills numbered placeholders in order', () => {
        expect(fillSentence('Enrol {0} in {1}', ['the learner', 'Accessibility'])).toBe(
            'Enrol the learner in Accessibility',
        );
    });

    it('leaves a placeholder it has no value for, rather than writing an empty gap', () => {
        expect(fillSentence('Enrol {0} in {1}', ['the learner'])).toBe('Enrol the learner in {1}');
        expect(fillSentence('Enrol {0} in {1}', ['the learner', ''])).toBe('Enrol the learner in {1}');
    });

    it('falls back to the label when a provider declares no sentence', () => {
        expect(readSentence(null, 'Add to cohort')).toBe('Add to cohort');
        expect(readSentence(undefined, 'Add to cohort')).toBe('Add to cohort');
    });

    it('reads the plain form when there are no values to fill', () => {
        expect(readSentence({ template: 'Enrol {0}', readable: 'Enrol the learner' }, 'Enrol')).toBe(
            'Enrol the learner',
        );
    });

    it('reads the plain form while every value is still empty, rather than showing placeholder names', () => {
        expect(readSentence({ template: 'Enrol {0}', readable: 'Enrol the learner' }, 'Enrol', [''])).toBe(
            'Enrol the learner',
        );
        expect(readSentence({ template: 'Enrol {0}', readable: 'Enrol the learner' }, 'Enrol', ['Ana'])).toBe(
            'Enrol Ana',
        );
    });

    it('takes placeholder values from the stored params, in the declared order', () => {
        const sentence = { template: 'Wait {0} then tag {1}', readable: '', placeholders: ['days', 'tag'] };
        expect(placeholderValues(sentence, { tag: 'vip', days: 7 })).toEqual(['7', 'vip']);
        expect(placeholderValues(sentence, { days: 7 })).toEqual(['7', '']);
        expect(placeholderValues(sentence, null)).toEqual(['', '']);
    });

    it('contributes nothing for a value no sentence can carry', () => {
        const sentence = { template: '{0}', readable: '', placeholders: ['bag'] };
        expect(placeholderValues(sentence, { bag: { nested: true } })).toEqual(['']);
    });
});

describe('the params form reads a provider schema and never a free-text expression', () => {
    it('publishes one field per property, keeping the schema order', () => {
        const fields = fieldsOf({
            type: 'object',
            required: ['course_id'],
            properties: {
                course_id: { type: 'integer', title: 'Course' },
                note: { type: 'string' },
                mode: { type: 'string', enum: ['soft', 'hard'] },
                active: { type: 'boolean' },
            },
        });
        expect(fields.map((field) => [field.name, field.kind])).toEqual([
            ['course_id', 'integer'],
            ['note', 'string'],
            ['mode', 'enum'],
            ['active', 'boolean'],
        ]);
        expect(fields[0]?.label).toBe('Course');
        expect(fields[0]?.required).toBe(true);
        expect(fields[1]?.required).toBe(false);
        expect(fields[2]?.options).toEqual(['soft', 'hard']);
    });

    it('recognizes a structured object parameter without treating it as an expression', () => {
        const fields = fieldsOf({ type: 'object', properties: { bag: { type: 'object' } } });
        expect(fields[0]?.kind).toBe('object');
    });

    it('answers with no fields for an absent or shapeless schema', () => {
        expect(fieldsOf(undefined)).toEqual([]);
        expect(fieldsOf(null)).toEqual([]);
        expect(fieldsOf({ type: 'object' })).toEqual([]);
    });
});
