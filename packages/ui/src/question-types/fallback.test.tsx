import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import { renderQuestion } from './testing';
import { QuestionTypeRegistry } from './registry';
import { createReactQuestionModule } from './react-module';
import { resolveAuthoringModule, resolveQuestionModule } from './resolve';
import { describeByShape, readResponseShape } from './shape';

/*
 * Registry miss is a normal path. Client side it does not fall through to a
 * blunt unsupported panel: it falls through to a generic renderer keyed on
 * the RESPONSE SHAPE, which core knows because it is a real column. A
 * missing associate renderer still shows a readable list of the learner's
 * chosen pairs; a missing point renderer still shows the coordinate list.
 *
 * That is the difference between the placeholder promise being technically
 * kept and being genuinely graceful, and it is what keeps review and manual
 * grading working the day a package is removed.
 */

describe('the shape-keyed fallback', () => {
    it('names the type, says the answer is preserved, and reads the answer back', () => {
        renderQuestion({
            typeId: 'associate_media',
            definition: { prompt: 'Match the parts' },
            response: [
                ['s1', 't3'],
                ['s2', 't1'],
            ],
            responseShape: readResponseShape('multiple:directed_pair'),
        });
        expect(screen.getByText('associate_media is not available on this page')).toBeInTheDocument();
        expect(screen.getByText(/Your answer is stored and nothing is lost/)).toBeInTheDocument();
        expect(screen.getByText('s1 to t3')).toBeInTheDocument();
        expect(screen.getByText('s2 to t1')).toBeInTheDocument();
    });

    it('reads a point response as a coordinate list', () => {
        renderQuestion({
            typeId: 'point_media',
            definition: { prompt: 'Mark the fault' },
            response: [
                { x: 12, y: 40 },
                { x: 65, y: 8 },
            ],
            responseShape: readResponseShape({ cardinality: 'multiple', base_type: 'point' }),
        });
        expect(screen.getByText('12, 40')).toBeInTheDocument();
        expect(screen.getByText('65, 8')).toBeInTheDocument();
    });

    it('says so plainly when nothing was answered', () => {
        renderQuestion({ typeId: 'x_acme_thing', definition: { prompt: 'Nothing yet' }, response: null });
        expect(screen.getByText('Nothing answered yet.')).toBeInTheDocument();
    });

    it('resolves the shape from the served column, and infers one when the column is absent', () => {
        expect(readResponseShape('ordered:identifier')).toEqual({ cardinality: 'ordered', baseType: 'identifier' });
        expect(readResponseShape('nonsense')).toBeNull();
        expect(describeByShape(['b', 'a'], null, { items: [{ key: 'a', label: 'Alpha' }] })).toEqual(['b', 'Alpha']);
    });

    /*
     * The record form used to return null, because the string was split on
     * ":" and "record(blank" is not a cardinality. That is every
     * record-shaped question, which is exactly the population the shape-keyed
     * fallback exists for.
     */
    it('parses the record form of the column, signature and all', () => {
        expect(readResponseShape('record(blank:single:string)')).toEqual({
            cardinality: 'record',
            baseType: null,
            fields: [{ name: 'blank', cardinality: 'single', baseType: 'string' }],
        });
        expect(
            readResponseShape('record(stringValue:single:string,floatValue:single:float)')?.fields,
        ).toHaveLength(2);
    });

    /*
     * The column is 30 characters, so a long signature is stored as the bare
     * head. Both forms are the same shape as far as the fallback is
     * concerned; only the field list differs.
     */
    it('reads the truncated head the column stores for a long signature', () => {
        expect(readResponseShape('record')).toEqual({
            cardinality: 'record',
            baseType: null,
            fields: [],
        });
    });

    it('renders a record answer through the shape rather than guessing from the value', () => {
        const lines = describeByShape(
            { blank1: 'water' },
            readResponseShape('record(blank:single:string)'),
            { blanks: [{ key: 'blank1', label: 'First gap' }] },
        );
        expect(lines).toEqual(['First gap: water']);
    });

    it('maps identifiers through the served labels, so the reader sees words not keys', () => {
        const lines = describeByShape('aa', readResponseShape('single:identifier'), {
            options: [{ key: 'aa', label: 'Level AA' }],
        });
        expect(lines).toEqual(['Level AA']);
    });
});

describe('an unsupported definition version is the same miss', () => {
    it('routes to the fallback rather than into a renderer that cannot read the definition', () => {
        const registry = new QuestionTypeRegistry();
        registry.register(
            'fill_blank',
            createReactQuestionModule({
                Renderer: () => <p>version one renderer</p>,
                answeredStatus: () => 'answered',
                describeResponse: () => 'from the renderer',
            }),
        );
        const one = resolveQuestionModule(registry, 'fill_blank', { schema_version: 1 }, null);
        expect(one.source).toBe('registry');

        /* Pro decorates the free six through replace(), so removing Pro
           leaves the key resolvable and nothing ever throws. The version is
           the only thing that can tell the shell the stored definition is
           one this renderer cannot read. */
        const two = resolveQuestionModule(registry, 'fill_blank', { schema_version: 2 }, null);
        expect(two.source).toBe('fallback');
        expect(two.unsupportedVersion).toBe(2);
        expect(two.module.describeResponse({ a: 'x' }, {}, { locale: 'en-GB', responseShape: null })).not.toBe('from the renderer');
    });

    /* The gate has to hold on the authoring side too. The workbench looked the
       module up by key alone, so an editor built for version one opened a
       version two definition, showed the fields it understood, and saved the
       rest away. */
    it('refuses the authoring block for a definition the editor cannot read', () => {
        const registry = new QuestionTypeRegistry();
        const module = createReactQuestionModule({
            Renderer: () => null,
            answeredStatus: () => 'answered',
            describeResponse: () => null,
        });
        module.supportedDefinitionVersions = () => [1];
        registry.register('fill_blank', module);

        expect(resolveAuthoringModule(registry, 'fill_blank', { schema_version: 1 }).module).not.toBeNull();

        const stale = resolveAuthoringModule(registry, 'fill_blank', { schema_version: 2 });

        expect(stale.module).toBeNull();
        expect(stale.unsupportedVersion).toBe(2);
    });

    it('honours a renderer that declares the versions it can read', () => {
        const registry = new QuestionTypeRegistry();
        const module = createReactQuestionModule({
            Renderer: () => null,
            answeredStatus: () => 'answered',
            describeResponse: () => null,
        });
        module.supportedDefinitionVersions = () => [1, 2];
        registry.register('short_answer', module);
        expect(resolveQuestionModule(registry, 'short_answer', { schema_version: 2 }, null).source).toBe('registry');
        expect(resolveQuestionModule(registry, 'short_answer', { schema_version: 3 }, null).source).toBe('fallback');
    });
});
