import type { KeyboardEvent } from 'react';
import { questionTypeStrings as strings } from '../strings';
import { createReactQuestionModule, type LearnerRendererProps } from '../react-module';
import { SingleSelectGroup } from '../primitives';
import type { AnsweredStatus, AuthoringContract, QuestionTypeClientModule } from '../contract';
import { parsePromptDefinition } from './definitions';
import { asBoolean } from './values';
import { Kicker, QuestionPrompt, UnsupportedQuestion } from './parts';
import { TrueFalseBlock } from './blocks';

/**
 * Artboard 2c: two large targets over the select primitive. The 1 and 2
 * digit shortcuts the artboard names work anywhere inside the group, and
 * they are scoped to the group rather than installed globally, because a
 * global key handler collides with JAWS and NVDA modifier chords, Windows
 * Magnifier panning and ZoomText hotkeys. Answer: a boolean.
 */
const TRUE_KEY = 'vy-true';
const FALSE_KEY = 'vy-false';

function TrueFalseRenderer({
    definition,
    response,
    readOnly,
    announce,
    controlId,
    errors,
    onResponse,
}: LearnerRendererProps) {
    const parsed = parsePromptDefinition(definition);
    if (parsed === null) {
        return <UnsupportedQuestion />;
    }
    const selected = asBoolean(response);
    const promptId = controlId('prompt');

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === '1') {
            onResponse(true);
        } else if (event.key === '2') {
            onResponse(false);
        }
    };

    return (
        <div>
            <Kicker>{strings.trueFalse}</Kicker>
            <QuestionPrompt id={promptId}>{parsed.prompt}</QuestionPrompt>
            <div onKeyDown={onKeyDown}>
                <SingleSelectGroup
                    variant="pair"
                    options={[
                        { key: TRUE_KEY, label: strings.trueLabel },
                        { key: FALSE_KEY, label: strings.falseLabel },
                    ]}
                    value={selected === null ? null : selected ? TRUE_KEY : FALSE_KEY}
                    onChange={(key) => onResponse(key === TRUE_KEY)}
                    controlId={controlId}
                    labelledBy={promptId}
                    announce={announce}
                    readOnly={readOnly}
                    error={errors[0]?.message}
                />
            </div>
        </div>
    );
}

const authoring: AuthoringContract = {
    Block: ({ form }) => <TrueFalseBlock form={form} />,
    toFormValues: (definition) => ({ correctBool: definition.correct === true ? 'true' : 'false' }),
    fromFormValues: (values) => ({ correct: values.correctBool === 'true' }),
    ownedKeys: () => ['correct'],
    blankFormValues: () => ({ correctBool: 'true' }),
    formSchema: (base) => base,
};

export function trueFalseModule(): QuestionTypeClientModule {
    return createReactQuestionModule({
        Renderer: TrueFalseRenderer,
        answeredStatus: (response, ctx): AnsweredStatus =>
            asBoolean(response) !== null ? 'answered' : ctx.presented ? 'presented' : 'notpresented',
        describeResponse: (response) => {
            const value = asBoolean(response);
            return value === null ? null : value ? strings.trueLabel : strings.falseLabel;
        },
        authoring,
        conformanceResult: 'vidyalms.kit.true_false.1',
    });
}
