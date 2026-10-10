import type { ComponentType } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { Announcer } from '@/shared/a11y';
import type {
    AnsweredStatus,
    AnsweredStatusContext,
    AuthoringContract,
    InteractionMode,
    QuestionContext,
    QuestionDefinition,
    QuestionError,
    QuestionInstance,
    QuestionShell,
    QuestionStatus,
    QuestionTypeClientModule,
} from './contract';
import { PUBLISHED_REACT_MAJOR, QUESTION_TYPE_CONTRACT } from './contract';

/*
 * The adapter that lets a React renderer satisfy the framework-neutral
 * mount(el, ctx) contract.
 *
 * The learner side of the contract is deliberately not React, so a
 * third-party renderer may be written in anything. The free six are React,
 * so they go through here: mount() opens a root inside the element the shell
 * handed it and never touches a node outside it, which is one of the
 * conformance kit checks.
 *
 * The renderer is rendered in its own root, so it has none of the app's
 * React context. Everything it needs arrives as props, which is what keeps
 * the seam honest: a renderer that reached for a provider would work in the
 * free bundle and fail in a third-party one.
 */

export interface LearnerRendererProps {
    definition: QuestionDefinition;
    response: unknown;
    /** Opaque, resumable UI state as the module last wrote it. */
    state: string | null;
    status: QuestionStatus;
    readOnly: boolean;
    locale: string;
    announce: Announcer;
    shell: QuestionShell;
    controlId: (local: string) => string;
    errors: readonly QuestionError[];
    onResponse: (response: unknown) => void;
    onState: (state: string) => void;
    /** The whole context, for a renderer that composes children. */
    ctx: QuestionContext;
}

export interface ReactQuestionModuleSpec {
    Renderer: ComponentType<LearnerRendererProps>;
    answeredStatus: (response: unknown, ctx: AnsweredStatusContext) => AnsweredStatus;
    describeResponse: (response: unknown, presented: QuestionDefinition) => string | null;
    /**
     * Shape validity, never correctness. Returned to the shell through
     * getErrors(); the module renders no error UI of its own.
     */
    validate?: (response: unknown, definition: QuestionDefinition, shell: QuestionShell) => QuestionError[];
    interactionModes?: readonly InteractionMode[];
    essentialDraggingJustification?: string;
    conformanceResult?: string;
    authoring?: AuthoringContract;
}

class ReactQuestionInstance implements QuestionInstance {
    private readonly root: Root;

    /* Its own child of el, never el itself: destroy() must be able to take
       the DOM away at once and let React finish unmounting afterwards, and
       a remount must get a container no root has claimed. */
    private readonly container: HTMLElement;

    private response: unknown;

    private state: string | null;

    private status: QuestionStatus;

    private destroyed = false;

    constructor(
        el: HTMLElement,
        private readonly ctx: QuestionContext,
        private readonly spec: ReactQuestionModuleSpec,
    ) {
        this.response = ctx.response;
        this.state = ctx.state;
        this.status = ctx.status;
        this.container = el.ownerDocument.createElement('div');
        el.appendChild(this.container);
        this.root = createRoot(this.container);
        this.render();
        ctx.onready(this);
    }

    getResponse(): unknown {
        return this.response;
    }

    setResponse(value: unknown): void {
        this.response = value;
        this.render();
    }

    getState(): string {
        return this.state ?? '';
    }

    setState(state: string): void {
        this.state = state === '' ? null : state;
        this.render();
    }

    getErrors(): ReadonlyArray<QuestionError> {
        return this.spec.validate?.(this.response, this.ctx.definition, this.ctx.shell) ?? [];
    }

    setStatus(status: QuestionStatus): void {
        this.status = status;
        this.render();
    }

    destroy(): void {
        if (this.destroyed) {
            return;
        }
        this.destroyed = true;
        const root = this.root;
        /* The DOM goes now so a remount in the same tick never sees two
           renderers; the root unmounts on the next microtask, because
           unmounting synchronously from inside a React commit is a race
           React warns about. */
        this.container.remove();
        queueMicrotask(() => root.unmount());
    }

    private render(): void {
        if (this.destroyed) {
            return;
        }
        const Renderer = this.spec.Renderer;
        const readOnly = this.ctx.readOnly || this.status === 'closed' || this.status === 'review'
            || this.status === 'solution';
        this.root.render(
                <Renderer
                    definition={this.ctx.definition}
                    response={this.response}
                    state={this.state}
                    status={this.status}
                    readOnly={readOnly}
                    locale={this.ctx.locale}
                    announce={this.ctx.announce}
                    shell={this.ctx.shell}
                    controlId={this.ctx.shell.controlId}
                    errors={this.getErrors()}
                    ctx={this.ctx}
                    onResponse={(value) => {
                        this.response = value;
                        this.ctx.onResponseChange(value);
                        this.render();
                    }}
                    onState={(next) => {
                        this.state = next;
                        this.ctx.onStateChange(next);
                    }}
            />,
        );
    }
}

/**
 * Builds the module object the registry takes. Everything optional has a
 * defined default, so a learner-only renderer is a legal module and its
 * authoring falls through to the placeholder that preserves the stored
 * definition.
 */
export function createReactQuestionModule(spec: ReactQuestionModuleSpec): QuestionTypeClientModule {
    const modes: readonly InteractionMode[] = spec.interactionModes ?? ['POINTER_NO_DRAG', 'KEYBOARD'];
    const module: QuestionTypeClientModule = {
        version: QUESTION_TYPE_CONTRACT,
        mount: (el, ctx) => new ReactQuestionInstance(el, ctx, spec),
        answeredStatus: spec.answeredStatus,
        describeResponse: spec.describeResponse,
        interactionModes: () => modes,
        authoring: spec.authoring,
    };
    if (spec.essentialDraggingJustification !== undefined) {
        module.essentialDraggingJustification = () => spec.essentialDraggingJustification as string;
    }
    if (spec.conformanceResult !== undefined) {
        module.conformanceResult = () => spec.conformanceResult as string;
    }
    if (spec.authoring !== undefined) {
        module.reactMajor = PUBLISHED_REACT_MAJOR;
    }
    return module;
}
