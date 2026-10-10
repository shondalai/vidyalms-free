import { createReactQuestionModule, type LearnerRendererProps } from './react-module';
import { describeByShape, isNonEmptyResponse, readResponseShape, type ResponseShape } from './shape';
import { shellStrings as strings } from './strings';
import type { AnsweredStatus, QuestionTypeClientModule } from './contract';

/*
 * Registry miss is a normal path, and client side it does not fall through
 * to a blunt unsupported panel. It falls through to a generic renderer keyed
 * on the RESPONSE SHAPE, which core knows because it is a real column.
 *
 * The placeholder names the question type, states that the stored response
 * is preserved, and renders that response as readable text, so review and
 * manual grading still work. An empty or purely visual placeholder would
 * make the removal path itself an accessibility regression.
 *
 * The other half of the miss is answeredStatus. A boolean isAnswered that
 * returns false for an unknown type shows a Pro question with a working
 * renderer as permanently unanswered in the progress palette and trips the
 * submit warning, so the generic status is derived from the response itself.
 */

export interface PlaceholderProps extends LearnerRendererProps {
    typeKey: string;
    shape: ResponseShape | null;
}

function PlaceholderBody({ typeKey, shape, definition, response, controlId }: PlaceholderProps) {
    const lines = describeByShape(response, shape, definition);
    const titleId = controlId('placeholder-title');
    return (
        <section
            aria-labelledby={titleId}
            className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-4 py-3.5"
        >
            <h4 id={titleId} className="font-display text-[13.5px] font-semibold text-fg">
                {strings.placeholderTitle(typeKey)}
            </h4>
            <p className="mt-1 max-w-[62ch] text-[12.5px] leading-[1.6] text-fg-2">{strings.placeholderBody}</p>
            <div className="mt-3">
                <h5 className="font-display text-[11px] font-semibold uppercase tracking-[0.04em] text-fg-2">
                    {strings.placeholderAnswerTitle}
                </h5>
                {lines.length === 0 ? (
                    <p className="mt-1 text-[13px] text-fg-2">{strings.placeholderNoAnswer}</p>
                ) : (
                    <ol className="mt-1 grid gap-1 text-[13px] text-fg">
                        {lines.map((line, index) => (
                            <li key={`${index}:${line}`} className="flex gap-2">
                                {lines.length > 1 && (
                                    <span aria-hidden="true" className="font-mono text-[11px] text-fg-2">
                                        {index + 1}
                                    </span>
                                )}
                                <span>{line}</span>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </section>
    );
}

/**
 * The module the shell mounts when no renderer is registered for a key, or
 * when the registered one cannot read the served definition version. It is a
 * real registry module rather than a special case in the shell, so the miss
 * path exercises exactly the code path a registered type does.
 */
export function fallbackModule(typeKey: string, shape: ResponseShape | null): QuestionTypeClientModule {
    return createReactQuestionModule({
        Renderer: (props) => <PlaceholderBody {...props} typeKey={typeKey} shape={shape} />,
        answeredStatus: (response, ctx): AnsweredStatus => {
            if (isNonEmptyResponse(response)) {
                return 'answered';
            }
            return ctx.presented ? 'presented' : 'notpresented';
        },
        describeResponse: (response, presented) => {
            const lines = describeByShape(response, shape, presented);
            return lines.length === 0 ? null : lines.join(', ');
        },
        interactionModes: ['POINTER_NO_DRAG', 'KEYBOARD', 'LINEAR_TEXT'],
        conformanceResult: 'vidyalms.kit.placeholder.1',
    });
}

/** The shape of a served question, from the column when there is one. */
export function shapeOfServed(question: {
    response_shape?: unknown;
    definition?: Record<string, unknown>;
    answer?: unknown;
}): ResponseShape | null {
    return readResponseShape(question.response_shape ?? question.definition?.response_shape ?? null);
}
