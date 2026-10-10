import { questionAuthoringStrings as authoringStrings } from '@/question-types/authoring-strings';
import { primitiveStrings, questionTypeStrings as strings } from '../strings';
import { createReactQuestionModule, type LearnerRendererProps } from '../react-module';
import { MultiSelectGroup } from '../primitives';
import type {
    AnsweredStatus,
    AuthoringContract,
    QuestionDefinition,
    QuestionError,
    QuestionTypeClientModule,
} from '../contract';
import { parseChoiceDefinition } from './definitions';
import { asStringArray } from './values';
import { Kicker, QuestionPrompt, UnsupportedQuestion } from './parts';
import { ChoiceBlock } from './blocks';
import { keyedList, refineForm, stringList, uniqueKeys, type FormIssue, type OptionValue } from './form-model';

/**
 * Artboard 2b: a checkbox group over the select primitive, "select all that
 * apply" stated up front with a live selected count. Answer: the selected
 * option keys as an array.
 *
 * min_choices and max_choices are read from the definition when a decorated
 * provider sends them, and they are announced rather than silently enforced;
 * the free provider sends neither, so the free build behaves as before.
 */
function MultipleChoiceRenderer({
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
    const selected = asStringArray(response) ?? [];
    const promptId = controlId('prompt');
    const kicker =
        selected.length > 0 ? `${strings.selectAll} · ${strings.selectedCount(selected.length)}` : strings.selectAll;

    return (
        <div>
            <Kicker>{kicker}</Kicker>
            <QuestionPrompt id={promptId}>{parsed.prompt}</QuestionPrompt>
            <MultiSelectGroup
                options={parsed.options}
                value={selected}
                onChange={(keys) => onResponse(keys)}
                controlId={controlId}
                labelledBy={promptId}
                announce={announce}
                readOnly={readOnly}
                error={errors[0]?.message}
                minChoices={numberOf(definition.min_choices)}
                maxChoices={numberOf(definition.max_choices)}
                minMessage={textOf(definition.min_message)}
                maxMessage={textOf(definition.max_message)}
            />
        </div>
    );
}

function numberOf(value: unknown): number | undefined {
    return typeof value === 'number' && Number.isFinite(value) && value > 0 ? value : undefined;
}

function textOf(value: unknown): string | undefined {
    return typeof value === 'string' && value !== '' ? value : undefined;
}

/** Shape validity, never correctness: the count, not which ones. */
function validate(response: unknown, definition: QuestionDefinition, shell: { controlId: (l: string) => string }) {
    const selected = asStringArray(response) ?? [];
    const errors: QuestionError[] = [];
    const min = numberOf(definition.min_choices);
    const max = numberOf(definition.max_choices);
    if (max !== undefined && selected.length > max) {
        errors.push({
            controlId: shell.controlId('group'),
            kind: 'TOO_MANY_SELECTED',
            message: textOf(definition.max_message) ?? primitiveStrings.maxReached(max),
        });
    }
    if (min !== undefined && selected.length > 0 && selected.length < min) {
        errors.push({
            controlId: shell.controlId('group'),
            kind: 'TOO_FEW_SELECTED',
            message: textOf(definition.min_message) ?? primitiveStrings.minNotMet(min),
        });
    }
    return errors;
}

const authoring: AuthoringContract = {
    Block: ({ form }) => <ChoiceBlock form={form} multiple />,
    toFormValues: (definition) => {
        const correct = new Set(stringList(definition.correct));
        return {
            options: keyedList(definition.options).map((option) => ({
                ...option,
                correct: correct.has(option.key),
            })),
        };
    },
    fromFormValues: (values) => {
        const options = (values.options ?? []) as OptionValue[];
        return {
            options: options.map(({ key, label }) => ({ key, label: label.trim() })),
            correct: options.filter((option) => option.correct).map((option) => option.key),
        };
    },
    ownedKeys: () => ['options', 'correct'],
    blankFormValues: () => ({
        options: [
            { key: 'opt1', label: '', correct: false },
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
            if (values.options.filter((option) => option.correct).length === 0) {
                issues.push({ path: ['options'], message: authoringStrings.form.chooseCorrectMany });
            }
            return issues;
        }),
};

export function multipleChoiceModule(): QuestionTypeClientModule {
    return createReactQuestionModule({
        Renderer: MultipleChoiceRenderer,
        validate,
        answeredStatus: (response, ctx): AnsweredStatus => {
            const keys = asStringArray(response);
            if (keys === null || keys.length === 0) {
                return ctx.presented ? 'presented' : 'notpresented';
            }
            /* Responded but invalid is attempted, which is a state a boolean
               cannot express and which the reporting model cannot rebuild. */
            return (ctx.errors?.length ?? 0) > 0 ? 'attempted' : 'answered';
        },
        describeResponse: (response, presented) => {
            const keys = asStringArray(response);
            if (keys === null || keys.length === 0) {
                return null;
            }
            const parsed = parseChoiceDefinition(presented);
            return keys.map((key) => parsed?.options.find((o) => o.key === key)?.label ?? key).join(', ');
        },
        authoring,
        conformanceResult: 'vidyalms.kit.multiple_choice.1',
    });
}
