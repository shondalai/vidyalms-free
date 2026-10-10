import { translate as uiText } from '@/shared/i18n';
import type { ReactNode } from 'react';
import type { UseFormReturn } from 'react-hook-form';
import { questionTypeRegistry } from './bridge';
import type { QuestionDefinition } from './contract';
import { resolveAuthoringModule } from './resolve';
import { shellStrings } from './strings';
import type { QuestionFormValues } from './authoring-form';

export interface QuestionAuthoringBlockProps {
    type: string;
    form: UseFormReturn<QuestionFormValues>;
    definition?: QuestionDefinition;
    /** Wire/catalogue definition version. It is never written into the definition. */
    definitionVersion?: number | null;
    locale: string;
    /** Optional product copy for a provider that is absent or too new. */
    unavailable?: (reason: 'missing' | 'newer-version', type: string) => ReactNode;
}

/**
 * The registry-driven authoring interaction without an instructor or admin
 * mount dependency. Both editors can therefore render Pro and third-party
 * blocks through the same global provider bridge.
 */
export function QuestionAuthoringBlock({
    type,
    form,
    definition = {},
    definitionVersion,
    locale,
    unavailable,
}: QuestionAuthoringBlockProps) {
    const resolved = resolveAuthoringModule(questionTypeRegistry(), type, definition, definitionVersion);
    const authoring = resolved.module?.authoring ?? null;
    if (authoring === null) {
        const reason = resolved.unsupportedVersion === null ? 'missing' : 'newer-version';
        return (
            unavailable?.(reason, type) ?? (
                <div role="status" className="rounded border border-border bg-bg p-3 text-[12.5px] text-fg-2">
                    <p className="font-display font-semibold text-fg">
                        {reason === 'missing' ? uiText("vidyalms.ui.880a6a8032df7629", "Question type “{p0}” is unavailable.", { p0: type }) : uiText("vidyalms.ui.317149042dbd8721", "This {p0} question uses a newer format.", { p0: type })}
                    </p>
                    <p className="mt-1">{uiText("vidyalms.ui.517199d30d9171dd", "Its stored definition is kept unchanged.")}</p>
                </div>
            )
        );
    }

    const Block = authoring.Block;
    return (
        <>
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
