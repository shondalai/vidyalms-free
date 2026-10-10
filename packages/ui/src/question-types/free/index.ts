import { installQuestionTypeBridge } from '../bridge';
import type { QuestionTypeRegistryApi } from '../contract';
import { singleChoiceModule } from './single-choice';
import { multipleChoiceModule } from './multiple-choice';
import { trueFalseModule } from './true-false';
import { shortAnswerModule } from './short-answer';
import { fillBlankModule } from './fill-blank';
import { orderingModule } from './ordering';

/*
 * The six free types are the registry's first six consumers, so the seam is
 * exercised by the free build rather than by the first Pro renderer. They
 * register through exactly the calls a third party makes: nothing in the
 * shell, the runner or the workbench knows one of these keys.
 *
 * Registration happens at module scope of each entry, before any app mounts.
 */

export const FREE_QUESTION_TYPES = [
    'single_choice',
    'multiple_choice',
    'true_false',
    'short_answer',
    'fill_blank',
    'ordering',
] as const;

export type FreeQuestionType = (typeof FREE_QUESTION_TYPES)[number];

export function isFreeQuestionType(type: string): type is FreeQuestionType {
    return (FREE_QUESTION_TYPES as readonly string[]).includes(type);
}

/**
 * Idempotent, because both entries call it and a test file may call it many
 * times in one document. A key that is already registered is left alone: a
 * second free registration must never displace a Pro decoration that
 * registered first.
 */
export function registerFreeQuestionTypes(registry: QuestionTypeRegistryApi = installQuestionTypeBridge()): void {
    const modules = {
        single_choice: singleChoiceModule,
        multiple_choice: multipleChoiceModule,
        true_false: trueFalseModule,
        short_answer: shortAnswerModule,
        fill_blank: fillBlankModule,
        ordering: orderingModule,
    } as const;
    for (const [key, build] of Object.entries(modules)) {
        if (!registry.has(key)) {
            registry.register(key, build());
        }
    }
}
