import type { ReactNode } from 'react';
import { EmptyState } from '@/shared/components';
import { questionTypeStrings as strings } from '../strings';

/*
 * The question shell of the artboard, shared by the six free renderers: a
 * small type kicker and the prompt. Everything below it is one of the eight
 * primitives, which is the whole point of the primitive layer: a renderer is
 * a prompt plus a composition, never its own DOM and ARIA.
 */

export function Kicker({ children }: { children: ReactNode }) {
    return <div className="mb-1 font-display text-[11px] font-semibold text-fg-2">{children}</div>;
}

export function QuestionPrompt({ id, children }: { id: string; children: ReactNode }) {
    return (
        <div id={id} className="mb-4 max-w-[62ch] font-display text-[17px] font-semibold leading-[1.45]">
            {children}
        </div>
    );
}

/**
 * A definition this renderer cannot read. The server is trusted but the wire
 * shape is unknown to the compiler, so every parser narrows defensively and
 * this is where a malformed one lands: the learner can still navigate and
 * submit the rest of the attempt.
 */
export function UnsupportedQuestion() {
    return <EmptyState title={strings.unsupportedTitle} description={strings.unsupportedBody} />;
}
