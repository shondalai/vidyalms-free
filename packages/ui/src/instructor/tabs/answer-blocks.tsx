import { useContext } from 'react';
import type { UseFormReturn } from 'react-hook-form';
import {
    questionTypeRegistry,
    resolveAuthoringModule,
    shellStrings,
    type QuestionDefinition,
} from '@/question-types';
import { instructorStrings as strings } from '../strings';
import type { QuestionFormValues } from '../lib/question-definitions';
import { InstructorConfigContext } from '../config';
import { PanelState } from '../components/states';

/*
 * The type-specific answer block of the question editor (artboard 2b).
 *
 * There is no switch here any more. The block comes from the module the
 * client renderer registry resolves, so adding a question type is a
 * registration in its own package and never an edit to this file. A type
 * with no registered authoring block renders the neutral placeholder and
 * its stored definition is written back untouched.
 *
 * **The version gate runs on this path too.** Resolving straight off the
 * registry meant supportedDefinitionVersions was never consulted on the
 * authoring side while the learner side gated correctly, which opens exactly
 * the case the gate exists for: Pro decorates the free six, so after the Pro
 * client bundle is removed the free module still resolves, reads a
 * schema_version 2 definition through a version 1 toFormValues, shows the
 * author an empty or wrong answer block, and hands fromFormValues a
 * definition stripped of everything the newer shape carried. The server
 * refuses the save, so it is a broken editor rather than data loss, but the
 * author edits a form that silently misread the question and then gets a
 * "newer format" error.
 */

export interface BlockProps {
    form: UseFormReturn<QuestionFormValues>;
}

export function AnswerBlock({
    type,
    form,
    definition = {},
}: { type: string; definition?: QuestionDefinition } & BlockProps) {
    // Null outside a mount (tests), which is the same fallback every other
    // consumer of the mount contract uses.
    const locale = useContext(InstructorConfigContext)?.locale ?? 'en-GB';
    const resolved = resolveAuthoringModule(questionTypeRegistry(), type, definition);
    const authoring = resolved.module?.authoring ?? null;

    if (authoring === null) {
        return (
            <PanelState
                glyph="◇"
                title={
                    resolved.unsupportedVersion === null
                        ? strings.question.unsupported.title(type)
                        : strings.question.newerFormat.title(type)
                }
                body={
                    resolved.unsupportedVersion === null
                        ? strings.question.unsupported.body
                        : strings.question.newerFormat.body
                }
                className="rounded border border-border bg-bg"
            />
        );
    }

    const Block = authoring.Block;

    return (
        <>
            {/* A type with no verified conformance kit result registers in a
                visibly flagged state; the site decides whether to publish
                with it, which it cannot do if nothing says so. */}
            {resolved.flagged && (
                <p className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-3 py-2 text-[12px] text-fg-2">
                    {shellStrings.flaggedRenderer}
                </p>
            )}
            <Block
                form={form}
                controlId={(local) => `vy-authoring-${type}-${local}`}
                locale={locale}
            />
        </>
    );
}
