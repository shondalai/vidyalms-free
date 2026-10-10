import { useEffect } from 'react';
import type { AttemptQuestion } from '@/shared/api';
import { useMountConfig } from '@/shared/context';
import {
    QuestionHost,
    questionTypeRegistry,
    shapeOfServed,
    unsupportedVersionOf,
    type QuestionError,
    type QuestionStatus,
} from '@/question-types';
import { quizRunnerStrings as strings } from '../strings';

/*
 * The runner's question slot. There is no switch here and there never will
 * be one again: the type key is resolved by the client renderer registry,
 * and an unregistered key falls through to the shape-keyed generic renderer
 * rather than to a blunt unsupported panel.
 *
 * One of the two misses is reported to the server, and the difference
 * matters. An unregistered key is the honest removal case: the item is read
 * only because nobody can render it and the server says the same. A
 * REGISTERED renderer that cannot read the served schema_version is not: the
 * server side resolves perfectly and would have marked the answer, so a
 * runner reports the rendering failure for diagnostics. The server counts
 * this as unanswered; a client claim cannot remove an item from the score.
 */

export function QuestionBody({
    question,
    value,
    onChange,
    onErrors,
    uiState,
    onUiState,
    status,
    readOnly,
}: {
    question: AttemptQuestion;
    value: unknown;
    onChange: (value: unknown) => void;
    onErrors?: (errors: readonly QuestionError[]) => void;
    uiState?: string | null;
    onUiState?: (state: string) => void;
    status?: QuestionStatus;
    readOnly?: boolean;
}) {
    const config = useMountConfig();
    const unsupportedVersion = unsupportedVersionOf(
        questionTypeRegistry(),
        question.type,
        question.definition,
    );

    useEffect(() => {
        if (unsupportedVersion === null || readOnly === true) {
            return;
        }
        onChange({ response: null, presentation_form: 'not_rendered' });
        // Reported once per item session; the value it writes is stable, so a
        // repeat would be an autosave with nothing new in it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [question.id, unsupportedVersion, readOnly]);

    return (
        <>
            {unsupportedVersion !== null && (
                <p className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-3 py-2 text-[12px] text-fg-2">
                    {strings.attempt.newerFormat}
                </p>
            )}
            <QuestionHost
                typeId={question.type}
                questionId={question.id}
                definition={question.definition}
                response={value}
                onResponseChange={onChange}
                state={uiState ?? null}
                onStateChange={onUiState}
                status={status}
                readOnly={readOnly}
                locale={config.locale}
                responseShape={shapeOfServed(question)}
                onErrors={onErrors}
            />
        </>
    );
}
