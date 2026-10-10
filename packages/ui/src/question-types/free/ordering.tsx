import { questionAuthoringStrings as authoringStrings } from '@/question-types/authoring-strings';
import { primitiveStrings, questionTypeStrings as strings } from '../strings';
import { createReactQuestionModule, type LearnerRendererProps } from '../react-module';
import { OrderedList } from '../primitives';
import type {
    AnsweredStatus,
    AuthoringContract,
    QuestionDefinition,
    QuestionError,
    QuestionTypeClientModule,
} from '../contract';
import { parseOrderingDefinition } from './definitions';
import { asStringArray } from './values';
import { Kicker, QuestionPrompt, UnsupportedQuestion } from './parts';
import { OrderingBlock } from './blocks';
import { keyedList, refineForm, stringList, uniqueKeys, type FormIssue, type ItemValue } from './form-model';

/**
 * Artboard 2f over the order primitive. Move buttons are first-class and
 * drag would only ever be an enhancement; select then place and grab and
 * move both come from the primitive rather than from here.
 *
 * Answer: the item keys in the learner's order. Untouched means unanswered,
 * so a deliberate skip stays possible.
 */
function OrderingRenderer({
    definition,
    response,
    readOnly,
    announce,
    shell,
    controlId,
    errors,
    onResponse,
}: LearnerRendererProps) {
    const parsed = parseOrderingDefinition(definition);
    if (parsed === null) {
        return <UnsupportedQuestion />;
    }
    const promptId = controlId('prompt');
    const saved = asStringArray(response) ?? [];
    return (
        <div>
            <Kicker>{strings.ordering}</Kicker>
            <QuestionPrompt id={promptId}>{parsed.prompt}</QuestionPrompt>
            <OrderedList
                items={parsed.items}
                value={saved}
                onChange={(order) => onResponse(order)}
                controlId={controlId}
                labelledBy={promptId}
                announce={announce}
                shell={shell}
                readOnly={readOnly}
                error={errors[0]?.message}
            />
        </div>
    );
}

/**
 * A response that is not an exact permutation of the presented item set is
 * invalid, not wrong: [A, A, A, A] against [A, B, C, D] must never reach a
 * scoring policy.
 */
function validate(
    response: unknown,
    definition: QuestionDefinition,
    shell: { controlId: (local: string) => string },
): QuestionError[] {
    const parsed = parseOrderingDefinition(definition);
    const saved = asStringArray(response);
    if (parsed === null || saved === null || saved.length === 0) {
        return [];
    }
    const presented = new Set(parsed.items.map((item) => item.key));
    const seen = new Set(saved);
    const permutation = saved.length === presented.size && seen.size === saved.length
        && saved.every((key) => presented.has(key));
    return permutation
        ? []
        : [
              {
                  controlId: shell.controlId('group'),
                  kind: 'INVALID_PERMUTATION',
                  message: primitiveStrings.invalidPermutation,
              },
          ];
}

const authoring: AuthoringContract = {
    Block: ({ form }) => <OrderingBlock form={form} />,
    toFormValues: (definition) => {
        /* The stored order is the correct one; the editor shows exactly that
           sequence, so "items in their correct order" is literal. */
        const items = keyedList(definition.items);
        const correct = stringList(definition.correct);
        const byKey = new Map(items.map((item) => [item.key, item] as const));
        const ordered = correct.flatMap((key) => {
            const item = byKey.get(key);
            return item === undefined ? [] : [item];
        });
        const rest = items.filter((item) => !correct.includes(item.key));
        return { items: [...ordered, ...rest] };
    },
    fromFormValues: (values) => {
        const items = (values.items ?? []) as ItemValue[];
        return {
            items: items.map(({ key, label }) => ({ key, label: label.trim() })),
            correct: items.map((item) => item.key),
        };
    },
    ownedKeys: () => ['items', 'correct'],
    blankFormValues: () => ({
        items: [
            { key: 'item1', label: '' },
            { key: 'item2', label: '' },
        ],
    }),
    formSchema: (base) =>
        refineForm(base, (values) => {
            const issues: FormIssue[] = [];
            if (values.items.length < 2) {
                issues.push({ path: ['items'], message: authoringStrings.form.atLeastOne });
            }
            if (!uniqueKeys(values.items)) {
                issues.push({ path: ['items'], message: authoringStrings.form.uniqueKeys });
            }
            values.items.forEach((item, index) => {
                if (item.label.trim() === '') {
                    issues.push({ path: ['items', index, 'label'], message: authoringStrings.form.labelRequired });
                }
            });
            return issues;
        }),
};

export function orderingModule(): QuestionTypeClientModule {
    return createReactQuestionModule({
        Renderer: OrderingRenderer,
        validate,
        answeredStatus: (response, ctx): AnsweredStatus => {
            const keys = asStringArray(response);
            if (keys === null || keys.length === 0) {
                return ctx.presented ? 'presented' : 'notpresented';
            }
            return (ctx.errors?.length ?? 0) > 0 ? 'attempted' : 'answered';
        },
        describeResponse: (response, presented) => {
            const keys = asStringArray(response);
            if (keys === null || keys.length === 0) {
                return null;
            }
            const parsed = parseOrderingDefinition(presented);
            return keys.map((key) => parsed?.items.find((item) => item.key === key)?.label ?? key).join(', ');
        },
        authoring,
        conformanceResult: 'vidyalms.kit.ordering.1',
    });
}
