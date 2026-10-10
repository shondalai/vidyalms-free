import { useState, type ReactElement } from 'react';
import { render, type RenderResult } from '@testing-library/react';
import { MountElContext } from '@/shared/context';
import { QuestionHost } from './host';
import type { QuestionDefinition, QuestionError, QuestionStatus } from './contract';
import type { ResponseShape } from './shape';

/*
 * The harness a renderer is exercised through: the real shell, the real
 * registry, the real live region channels. Nothing here shortcuts the
 * mount(el, ctx) seam, because a test that mounted a React component
 * directly would pass for a renderer that could never be loaded from
 * another bundle.
 *
 * Exported so a third-party renderer can drive its own tests through the
 * same shell the runner uses (see the conformance kit next to it).
 */

export interface QuestionHarness {
    /** Every response the module has reported, newest last. */
    responses: unknown[];
    /** Every error list the module has contributed. */
    errors: QuestionError[][];
    /** Every UI state the module has written. */
    states: string[];
    mountEl: HTMLElement;
    ui: ReactElement;
}

export interface HarnessOptions {
    typeId: string;
    definition: QuestionDefinition;
    response?: unknown;
    state?: string | null;
    status?: QuestionStatus;
    readOnly?: boolean;
    locale?: string;
    responseShape?: ResponseShape | null;
    questionId?: number;
}

export function questionHarness(options: HarnessOptions): QuestionHarness {
    const responses: unknown[] = [];
    const errors: QuestionError[][] = [];
    const states: string[] = [];
    /* A previous harness leaves an empty mount behind after cleanup;
       there is only ever one mount on a page, so clear it. */
    for (const stale of document.querySelectorAll('[data-vy-test-mount]')) {
        stale.remove();
    }
    const mountEl = document.createElement('div');
    mountEl.id = 'vidyalms-quiz-runner';
    mountEl.setAttribute('data-vy-test-mount', '');
    document.body.appendChild(mountEl);

    function Harness() {
        const [response, setResponse] = useState<unknown>(options.response ?? null);
        const [state, setState] = useState<string | null>(options.state ?? null);
        return (
            <MountElContext.Provider value={mountEl}>
                <QuestionHost
                    typeId={options.typeId}
                    questionId={options.questionId ?? 1}
                    definition={options.definition}
                    response={response}
                    onResponseChange={(value) => {
                        responses.push(value);
                        setResponse(value);
                    }}
                    state={state}
                    onStateChange={(value) => {
                        states.push(value);
                        setState(value);
                    }}
                    status={options.status}
                    readOnly={options.readOnly}
                    locale={options.locale ?? 'en-GB'}
                    responseShape={options.responseShape ?? null}
                    onErrors={(list) => errors.push([...list])}
                />
            </MountElContext.Provider>
        );
    }

    return { responses, errors, states, mountEl, ui: <Harness /> };
}

export function renderQuestion(options: HarnessOptions): QuestionHarness & { result: RenderResult } {
    const harness = questionHarness(options);
    /* Rendered INSIDE the mount element, as the apps do: the live region
       channels, the inert boundary and the scoped styles all hang off it. */
    return { ...harness, result: render(harness.ui, { container: harness.mountEl }) };
}

/** The text of one live region channel of the harness mount. */
export function channelText(mountEl: HTMLElement, channel: 'status' | 'alert' | 'log' | 'timer'): string {
    return mountEl.querySelector(`[data-vy-channel='${channel}']`)?.textContent ?? '';
}
