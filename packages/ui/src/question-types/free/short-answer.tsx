import { questionTypeStrings as strings } from '../strings';
import { createReactQuestionModule, type LearnerRendererProps } from '../react-module';
import { TextEntry } from '../primitives';
import type { AnsweredStatus, AuthoringContract, QuestionTypeClientModule } from '../contract';
import { parsePromptDefinition } from './definitions';
import { asString } from './values';
import { Kicker, QuestionPrompt, UnsupportedQuestion } from './parts';
import { ShortAnswerBlock } from './blocks';
import { linesOf, stringList } from './form-model';

/*
 * The artboard's 500-character cap; the contract does not state one, so the
 * client keeps the artboard's value (recorded as a contract question).
 */
const MAX_LENGTH = 500;

/** Artboard 2d: free text over the text primitive. Answer: the text. */
function ShortAnswerRenderer({
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
    const promptId = controlId('prompt');
    return (
        <div>
            <Kicker>{strings.shortAnswer}</Kicker>
            <QuestionPrompt id={promptId}>{parsed.prompt}</QuestionPrompt>
            <TextEntry
                multiline
                rows={4}
                maxLength={MAX_LENGTH}
                counter="characters"
                placeholder={strings.answerPlaceholder}
                value={asString(response) ?? ''}
                onChange={(text) => onResponse(text)}
                controlId={controlId}
                labelledBy={promptId}
                announce={announce}
                readOnly={readOnly}
                error={errors[0]?.message}
            />
        </div>
    );
}

const authoring: AuthoringContract = {
    Block: ({ form }) => <ShortAnswerBlock form={form} />,
    toFormValues: (definition) => ({ accepted: stringList(definition.accepted).join('\n') }),
    fromFormValues: (values) => ({
        accepted: linesOf(typeof values.accepted === 'string' ? values.accepted : ''),
        case_sensitive: values.caseSensitive === true,
    }),
    ownedKeys: () => ['accepted', 'case_sensitive'],
    blankFormValues: () => ({ accepted: '', caseSensitive: false }),
    formSchema: (base) => base,
    /* An empty accepted list is the author saying a human marks this one. */
    needsManualMarking: (definition) => stringList(definition.accepted).length === 0,
};

export function shortAnswerModule(): QuestionTypeClientModule {
    return createReactQuestionModule({
        Renderer: ShortAnswerRenderer,
        answeredStatus: (response, ctx): AnsweredStatus => {
            const text = asString(response);
            return text !== null && text.trim() !== ''
                ? 'answered'
                : ctx.presented
                  ? 'presented'
                  : 'notpresented';
        },
        describeResponse: (response) => asString(response),
        authoring,
        conformanceResult: 'vidyalms.kit.short_answer.1',
    });
}
