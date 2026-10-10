import { Fragment } from 'react';
import { questionAuthoringStrings as authoringStrings } from '@/question-types/authoring-strings';
import { questionTypeStrings as strings } from '../strings';
import { createReactQuestionModule, type LearnerRendererProps } from '../react-module';
import { TextEntry } from '../primitives';
import type { AnsweredStatus, AuthoringContract, QuestionTypeClientModule } from '../contract';
import { parseBlankPrompt, parseFillBlankDefinition } from './definitions';
import { asStringRecord } from './values';
import { Kicker, UnsupportedQuestion } from './parts';
import { FillBlankBlock } from './blocks';
import { linesOf, refineForm, stringList, uniqueKeys, type BlankValue, type FormIssue } from './form-model';

/**
 * Artboard 2e: the prompt with each [[key]] marker rendered as an inline
 * text primitive, labelled "Blank n of m" for assistive technology. The
 * markers are rendered in reading order in the DOM, never repositioned, so
 * the tab order and the reading order are the sentence. Answer: a map of
 * blank key to text.
 */
function FillBlankRenderer({
    definition,
    response,
    readOnly,
    announce,
    controlId,
    errors,
    onResponse,
}: LearnerRendererProps) {
    const parsed = parseFillBlankDefinition(definition);
    if (parsed === null) {
        return <UnsupportedQuestion />;
    }
    const { segments, blankCount } = parseBlankPrompt(parsed.prompt, parsed.blanks);
    const record = asStringRecord(response);
    const promptId = controlId('prompt');

    return (
        <div>
            <Kicker>{strings.fillBlanks(blankCount)}</Kicker>
            <div
                id={promptId}
                role="group"
                aria-label={strings.fillBlanks(blankCount)}
                className="max-w-[62ch] font-display text-[15px] font-medium leading-[2.1]"
            >
                {segments.map((segment, position) => {
                    if (segment.kind === 'text') {
                        return <Fragment key={`t-${position}`}>{segment.text}</Fragment>;
                    }
                    const gapError = errors.find((error) => error.partId === segment.key);
                    return (
                        <TextEntry
                            key={segment.key}
                            inline
                            value={record[segment.key] ?? ''}
                            onChange={(text) => onResponse({ ...record, [segment.key]: text })}
                            controlId={(local) => controlId(`${segment.key}-${local}`)}
                            label={strings.blankLabel(segment.index, blankCount)}
                            announce={announce}
                            readOnly={readOnly}
                            error={gapError?.message}
                        />
                    );
                })}
            </div>
        </div>
    );
}

const authoring: AuthoringContract = {
    Block: ({ form }) => <FillBlankBlock form={form} />,
    toFormValues: (definition) => {
        const blanks = Array.isArray(definition.blanks) ? definition.blanks : [];
        return {
            blanks: blanks.flatMap((entry) => {
                if (typeof entry !== 'object' || entry === null) {
                    return [];
                }
                const record = entry as Record<string, unknown>;
                if (typeof record.key !== 'string' || record.key === '') {
                    return [];
                }
                return [{ key: record.key, accepted: stringList(record.accepted).join('\n') }];
            }),
        };
    },
    fromFormValues: (values) => {
        const blanks = (values.blanks ?? []) as BlankValue[];
        return {
            blanks: blanks.map((blank) => ({ key: blank.key, accepted: linesOf(blank.accepted) })),
            case_sensitive: values.caseSensitive === true,
        };
    },
    ownedKeys: () => ['blanks', 'case_sensitive'],
    blankFormValues: () => ({ blanks: [{ key: 'blank1', accepted: '' }], caseSensitive: false }),
    formSchema: (base) =>
        refineForm(base, (values) => {
            const issues: FormIssue[] = [];
            if (values.blanks.length === 0) {
                issues.push({ path: ['blanks'], message: authoringStrings.form.atLeastOne });
            }
            if (!uniqueKeys(values.blanks)) {
                issues.push({ path: ['blanks'], message: authoringStrings.form.uniqueKeys });
            }
            values.blanks.forEach((blank, index) => {
                if (blank.key.trim() === '') {
                    issues.push({ path: ['blanks', index, 'key'], message: authoringStrings.form.keyRequired });
                } else if (!values.prompt.includes(`[[${blank.key}]]`)) {
                    issues.push({
                        path: ['blanks', index, 'key'],
                        message: authoringStrings.question.blankMissingMarker(blank.key),
                    });
                }
                if (linesOf(blank.accepted).length === 0) {
                    issues.push({
                        path: ['blanks', index, 'accepted'],
                        message: authoringStrings.form.acceptedRequired,
                    });
                }
            });
            return issues;
        }),
};

export function fillBlankModule(): QuestionTypeClientModule {
    return createReactQuestionModule({
        Renderer: FillBlankRenderer,
        answeredStatus: (response, ctx): AnsweredStatus => {
            const record = asStringRecord(response);
            const any = Object.values(record).some((value) => value.trim() !== '');
            return any ? 'answered' : ctx.presented ? 'presented' : 'notpresented';
        },
        describeResponse: (response, presented) => {
            const record = asStringRecord(response);
            const parsed = parseFillBlankDefinition(presented);
            const order = parsed !== null ? parseBlankPrompt(parsed.prompt, parsed.blanks).segments : [];
            const keys =
                order.length > 0
                    ? order.filter((s) => s.kind === 'blank').map((s) => (s.kind === 'blank' ? s.key : ''))
                    : Object.keys(record);
            const parts = keys.map((key) => (record[key] ?? '').trim()).filter((value) => value !== '');
            return parts.length > 0 ? parts.join(', ') : null;
        },
        authoring,
        conformanceResult: 'vidyalms.kit.fill_blank.1',
    });
}
