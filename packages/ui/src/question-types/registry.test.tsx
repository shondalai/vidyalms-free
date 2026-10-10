import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { createPortal, flushSync } from 'react-dom';
import { createRoot } from 'react-dom/client';
import { QuestionTypeRegistry } from './registry';
import { installQuestionTypeBridge, questionTypeRegistry } from './bridge';
import { createReactQuestionModule } from './react-module';
import { renderQuestion } from './testing';
import {
    PUBLISHED_REACT_MAJOR,
    QUESTION_TYPE_CONTRACT,
    QuestionTypeRegistrationError,
    type InteractionMode,
    type QuestionTypeClientModule,
} from './contract';

/*
 * Registration is a gate, not a form. These are the refusals: a renderer
 * that cannot say how it is operated without a mouse drag, one written
 * against a contract this bundle does not speak, one carrying a second
 * React, and a second package claiming a key another already owns.
 */

function stubModule(overrides: Partial<QuestionTypeClientModule> = {}): QuestionTypeClientModule {
    return {
        version: QUESTION_TYPE_CONTRACT,
        mount: (el) => {
            el.textContent = 'stub';
            return {
                getResponse: () => null,
                setResponse: () => {},
                getState: () => '',
                setState: () => {},
                getErrors: () => [],
                setStatus: () => {},
                destroy: () => {},
            };
        },
        answeredStatus: () => 'presented',
        describeResponse: () => null,
        interactionModes: () => ['POINTER_NO_DRAG', 'KEYBOARD'] as readonly InteractionMode[],
        ...overrides,
    };
}

describe('registration', () => {
    it('takes a renderer that declares the two required modes', () => {
        const registry = new QuestionTypeRegistry();
        registry.register('x_acme_molecule', stubModule());
        expect(registry.has('x_acme_molecule')).toBe(true);
        expect(registry.keys()).toEqual(['x_acme_molecule']);
    });

    it('refuses a second package claiming a key another already owns', () => {
        const registry = new QuestionTypeRegistry();
        registry.register('x_acme_molecule', stubModule());
        expect(() => registry.register('x_acme_molecule', stubModule())).toThrow(QuestionTypeRegistrationError);
    });

    it('replaces only what exists, and hands back the displaced module to delegate to', () => {
        const registry = new QuestionTypeRegistry();
        const first = stubModule();
        registry.register('single_choice', first);
        const displaced = registry.replace('single_choice', stubModule());
        expect(displaced).toBe(first);
        expect(() => registry.replace('never_registered', stubModule())).toThrow(QuestionTypeRegistrationError);
    });

    it('refuses a drag-only renderer, because keyboard support does not satisfy SC 2.5.7', () => {
        const registry = new QuestionTypeRegistry();
        expect(() =>
            registry.register(
                'x_acme_drag',
                stubModule({ interactionModes: () => ['POINTER_DRAG', 'KEYBOARD'] }),
            ),
        ).toThrow(/essentialDraggingJustification/);
    });

    it('takes a drag renderer that justifies the dragging as essential, and stores the justification', () => {
        const registry = new QuestionTypeRegistry();
        registry.register(
            'x_acme_drawing',
            stubModule({
                interactionModes: () => ['POINTER_DRAG', 'KEYBOARD'],
                essentialDraggingJustification: () => 'A freehand mark is the construct.',
            }),
        );
        expect(registry.entry('x_acme_drawing')?.essentialDraggingJustification).toBe(
            'A freehand mark is the construct.',
        );
    });

    it('refuses a renderer written against a newer contract instead of failing at render', () => {
        const registry = new QuestionTypeRegistry();
        expect(() =>
            registry.register('x_acme_future', stubModule({ version: QUESTION_TYPE_CONTRACT + 1 })),
        ).toThrow(/contract/);
    });

    it('refuses an authoring block built against another React major', () => {
        const registry = new QuestionTypeRegistry();
        expect(() =>
            registry.register(
                'x_acme_old',
                stubModule({
                    reactMajor: PUBLISHED_REACT_MAJOR - 1,
                    authoring: {
                        Block: () => null,
                        toFormValues: () => ({}),
                        fromFormValues: () => ({}),
                        ownedKeys: () => [],
                        blankFormValues: () => ({}),
                        formSchema: (base) => base,
                    },
                }),
            ),
        ).toThrow(/React/);
    });

    it('flags a renderer that declares no conformance kit result rather than refusing it', () => {
        const registry = new QuestionTypeRegistry();
        registry.register('x_acme_unverified', stubModule());
        registry.register('x_acme_verified', stubModule({ conformanceResult: () => 'acme.kit.1' }));
        expect(registry.entry('x_acme_unverified')?.flagged).toBe(true);
        expect(registry.entry('x_acme_verified')?.flagged).toBe(false);
    });

    it('notifies subscribers, so a deferred chunk that registers late still renders', () => {
        const registry = new QuestionTypeRegistry();
        const seen: string[] = [];
        const stop = registry.subscribe((event) => seen.push(`${event.kind}:${event.key}`));
        registry.register('x_acme_molecule', stubModule());
        registry.replace('x_acme_molecule', stubModule());
        stop();
        registry.register('x_acme_other', stubModule());
        expect(seen).toEqual(['register:x_acme_molecule', 'replace:x_acme_molecule']);
    });

    it('keeps registering when one subscriber throws', () => {
        const registry = new QuestionTypeRegistry();
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        registry.subscribe(() => {
            throw new Error('bad listener');
        });
        expect(() => registry.register('x_acme_molecule', stubModule())).not.toThrow();
        expect(registry.has('x_acme_molecule')).toBe(true);
        error.mockRestore();
    });
});

describe('the window bridge', () => {
    it('is the one sanctioned global, and is the same registry on a second install', () => {
        const registry = installQuestionTypeBridge();
        expect(window.vidyalms?.questionTypes).toBe(registry);
        expect(installQuestionTypeBridge()).toBe(registry);
        expect(questionTypeRegistry()).toBe(registry);
    });

    it('publishes the pinned React runtime a third-party build marks external', () => {
        installQuestionTypeBridge();
        expect(window.vidyalms?.react).toBeDefined();
        expect(window.vidyalms?.reactDom).toBeDefined();
        expect(window.vidyalms?.reactDom?.createPortal).toBe(createPortal);
        expect(window.vidyalms?.reactDom?.flushSync).toBe(flushSync);
        expect(window.vidyalms?.reactDom?.createRoot).toBe(createRoot);
        expect(window.vidyalms?.jsxRuntime).toBeDefined();
        expect(window.vidyalms?.reactMajor).toBe(PUBLISHED_REACT_MAJOR);
        expect(window.vidyalms?.questionTypeContract).toBe(QUESTION_TYPE_CONTRACT);
    });
});

describe('a renderer registered after the page has rendered', () => {
    it('replaces the placeholder without a reload', async () => {
        const registry = questionTypeRegistry();
        const harness = renderQuestion({
            typeId: 'x_late_chunk',
            definition: { prompt: 'Late' },
            response: 'stored value',
        });
        render(harness.ui);
        expect(screen.getAllByText(/is not available on this page/).length).toBeGreaterThan(0);

        registry.register(
            'x_late_chunk',
            createReactQuestionModule({
                Renderer: () => <p>the late renderer</p>,
                answeredStatus: () => 'answered',
                describeResponse: () => null,
            }),
        );

        await waitFor(() => {
            expect(screen.getAllByText('the late renderer').length).toBeGreaterThan(0);
        });
    });
});
