import { questionAuthoringStrings as authoringStrings } from '@/question-types/authoring-strings';
import { questionTypeStrings as strings } from '../strings';
import { createReactQuestionModule, type LearnerRendererProps } from '../react-module';
import { SingleSelectGroup } from '../primitives';
import type { AnsweredStatus, AuthoringContract, QuestionTypeClientModule } from '../contract';
import { parseChoiceDefinition } from './definitions';
import { asString } from './values';
import { Kicker, QuestionPrompt, UnsupportedQuestion } from './parts';
import { ChoiceBlock } from './blocks';
import { keyedList, refineForm, stringOf, uniqueKeys, type FormIssue, type OptionValue } from './form-model';

/* Artboard 2a: a radio group over the select primitive. Answer: the option key. */
function SingleChoiceRenderer({
    definition,
    response,
    readOnly,
    announce,
    controlId,
    errors,
    onResponse,
}: LearnerRendererProps) {
    const parsed = parseChoiceDefinition(definition);
    if (parsed === null) {
        return <UnsupportedQuestion />;
    }
    const promptId = controlId('prompt');
    return (
        <div>
            <Kicker>{strings.chooseOne}</Kicker>
            <QuestionPrompt id={promptId}>{parsed.prompt}</QuestionPrompt>
            <SingleSelectGroup
                options={parsed.options}
                value={asString(response)}
                onChange={(key) => onResponse(key)}
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
    Block: ({ form }) => <ChoiceBlock form={form} multiple={false} />,
    toFormValues: (definition) => {
        const correct = stringOf(definition.correct);
        return {
            options: keyedList(definition.options).map((option) => ({ ...option, correct: option.key === correct })),
        };
    },
    fromFormValues: (values) => {
        const options = (values.options ?? []) as OptionValue[];
        return {
            options: options.map(({ key, label }) => ({ key, label: label.trim() })),
            correct: options.find((option) => option.correct)?.key ?? '',
        };
    },
    ownedKeys: () => ['options', 'correct'],
    blankFormValues: () => ({
        options: [
            { key: 'opt1', label: '', correct: true },
            { key: 'opt2', label: '', correct: false },
        ],
    }),
    formSchema: (base) =>
        refineForm(base, (values) => {
            const issues: FormIssue[] = [];
            if (values.options.length === 0) {
                issues.push({ path: ['options'], message: authoringStrings.form.atLeastOne });
            }
            values.options.forEach((option, index) => {
                if (option.label.trim() === '') {
                    issues.push({ path: ['options', index, 'label'], message: authoringStrings.form.labelRequired });
                }
            });
            if (!uniqueKeys(values.options)) {
                issues.push({ path: ['options'], message: authoringStrings.form.uniqueKeys });
            }
            if (values.options.filter((option) => option.correct).length !== 1) {
                issues.push({ path: ['options'], message: authoringStrings.form.chooseCorrect });
            }
            return issues;
        }),
};

export function singleChoiceModule(): QuestionTypeClientModule {
    return createReactQuestionModule({
        Renderer: SingleChoiceRenderer,
        answeredStatus: (response, ctx): AnsweredStatus => {
            const key = asString(response);
            if (key !== null && key !== '') {
                return 'answered';
            }
            return ctx.presented ? 'presented' : 'notpresented';
        },
        describeResponse: (response, presented) => {
            const key = asString(response);
            if (key === null) {
                return null;
            }
            return parseChoiceDefinition(presented)?.options.find((option) => option.key === key)?.label ?? key;
        },
        authoring,
        conformanceResult: 'vidyalms.kit.single_choice.1',
    });
}
