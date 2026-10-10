import { useEffect, useMemo, useRef, useState } from 'react';
import { createAnnouncer, type Announcer } from '@/shared/a11y';
import { useMountEl } from '@/shared/context';
import { questionTypeRegistry } from './bridge';
import { resolveQuestionModule, type ResolvedModule } from './resolve';
import type {
    AccessibilityProfile,
    ChildContext,
    QuestionContext,
    QuestionDefinition,
    QuestionError,
    QuestionInstance,
    QuestionShell,
    QuestionStatus,
} from './contract';
import type { ResponseShape } from './shape';

/*
 * The shell half of the client renderer registry: the one place that mounts
 * a module, and therefore the one place that owns everything the design
 * says a module must never own.
 *
 * The live regions are the shell's, reached through ctx.announce. The error
 * summary is the shell's, built once from the errors modules contribute. The
 * containment a grab needs is the shell's: captureInteraction sets inert
 * outside the item body, because the header, timer, progress palette and
 * navigation are DOM the module does not own and must never mutate.
 *
 * The runner subscribes to the registry change event, so a deferred chunk
 * that registers a renderer after the page has rendered still renders
 * without a reload.
 */

/*
 * The shell's own surfaces are never contained. The four live region
 * containers and the error summary are siblings of the module host, so a
 * blanket inert over the siblings takes the announcement channels out of the
 * accessibility tree for the whole duration of a grab and silences the
 * grab-and-move vocabulary the design requires, plus role=alert, which is
 * the channel reserved for an attempt at risk.
 */
const SHELL_OWNED = ['data-vy-channel', 'data-vy-shell'];

function isShellOwned(el: HTMLElement): boolean {
    return SHELL_OWNED.some((attr) => el.hasAttribute(attr));
}

/** Sets inert on everything between `from` and `root`, and gives back the undo. */
function containOutside(from: HTMLElement, root: HTMLElement | null): () => void {
    const touched: HTMLElement[] = [];
    let node: HTMLElement | null = from;
    while (node !== null && node !== root && node.parentElement !== null) {
        const parent: HTMLElement = node.parentElement;
        for (const sibling of [...parent.children]) {
            if (
                sibling !== node &&
                sibling instanceof HTMLElement &&
                !sibling.hasAttribute('inert') &&
                !isShellOwned(sibling)
            ) {
                sibling.setAttribute('inert', '');
                touched.push(sibling);
            }
        }
        if (parent === root) {
            break;
        }
        node = parent;
    }
    return () => {
        for (const el of touched) {
            el.removeAttribute('inert');
        }
    };
}

export interface QuestionHostProps {
    typeId: string;
    /** Stable per item session; used for control ids and remount identity. */
    questionId: string | number;
    definition: QuestionDefinition;
    /** Wire/catalogue definition version. It is metadata, not a definition key. */
    definitionVersion?: number | null;
    response: unknown;
    onResponseChange: (response: unknown) => void;
    /** Opaque UI state, so a half-completed placement survives navigation. */
    state?: string | null;
    onStateChange?: (state: string) => void;
    status?: QuestionStatus;
    readOnly?: boolean;
    locale: string;
    accessibilityProfile?: AccessibilityProfile;
    responseShape?: ResponseShape | null;
    /** Reported after every change so the shell can build one error summary. */
    onErrors?: (errors: readonly QuestionError[]) => void;
    /** The prompt element naming this question, for the group label. */
    labelledBy?: string;
    className?: string;
}

const EMPTY_PROFILE: AccessibilityProfile = Object.freeze({});

export function QuestionHost({
    typeId,
    questionId,
    definition,
    definitionVersion,
    response,
    onResponseChange,
    state = null,
    onStateChange,
    status = 'interacting',
    readOnly = false,
    locale,
    accessibilityProfile = EMPTY_PROFILE,
    responseShape = null,
    onErrors,
    className,
}: QuestionHostProps) {
    const mountEl = useMountEl();
    const hostRef = useRef<HTMLDivElement>(null);
    const instanceRef = useRef<QuestionInstance | null>(null);
    const releaseRef = useRef<(() => void) | null>(null);
    const lastResponseRef = useRef<unknown>(response);
    const [registryTick, setRegistryTick] = useState(0);

    const registry = questionTypeRegistry();
    /* Resolution has to be stable across renders: the fallback module is a
       fresh object every call, and the mount effect keys on the module. */
    const resolved: ResolvedModule = useMemo(
        () => resolveQuestionModule(registry, typeId, definition, responseShape, definitionVersion),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [registry, typeId, definition, definitionVersion, responseShape, registryTick],
    );

    /* Live props the mounted instance reads without being torn down. */
    const liveRef = useRef({ response, onResponseChange, onStateChange, onErrors, definition, state });
    liveRef.current = { response, onResponseChange, onStateChange, onErrors, definition, state };

    useEffect(() => {
        const unsubscribe = registry.subscribe((event) => {
            if (event.key === typeId) {
                setRegistryTick((tick) => tick + 1);
            }
        });
        return unsubscribe;
    }, [registry, typeId]);

    useEffect(() => {
        const host = hostRef.current;
        if (host === null) {
            return;
        }
        const announce: Announcer = createAnnouncer(mountEl);
        const idBase = `vy-q-${String(questionId)}`;
        const shell: QuestionShell = {
            captureInteraction: () => {
                releaseRef.current?.();
                releaseRef.current = containOutside(host, mountEl);
            },
            releaseInteraction: () => {
                releaseRef.current?.();
                releaseRef.current = null;
            },
            controlId: (local: string) => `${idBase}-${local}`,
            questionId: String(questionId),
        };

        /* onready fires from inside mount(), before the instance reference
           exists here, so the reporter takes the instance rather than
           reading it back out of the ref. */
        const reportErrors = (instance?: QuestionInstance) => {
            const source = instance ?? instanceRef.current;
            liveRef.current.onErrors?.(source?.getErrors() ?? []);
        };

        const mountChild = (el: HTMLElement, childTypeId: string, childCtx: ChildContext): QuestionInstance => {
            const childShell: QuestionShell = {
                ...shell,
                controlId: (local: string) => `${idBase}-${childCtx.partId}-${local}`,
                partId: childCtx.partId,
            };
            const child = resolveQuestionModule(registry, childTypeId, childCtx.definition, null);
            return child.module.mount(el, {
                definition: childCtx.definition,
                response: childCtx.response ?? null,
                state: childCtx.state ?? null,
                status: childCtx.status ?? status,
                readOnly: childCtx.readOnly ?? readOnly,
                locale,
                accessibilityProfile,
                announce,
                shell: childShell,
                mountChild,
                onready: () => {},
                ondone: () => {},
                onResponseChange: (value) => childCtx.onResponseChange?.(value),
                onStateChange: (value) => childCtx.onStateChange?.(value),
            });
        };

        const ctx: QuestionContext = {
            definition,
            response: liveRef.current.response,
            state: liveRef.current.state,
            status,
            readOnly,
            locale,
            accessibilityProfile,
            announce,
            shell,
            mountChild,
            onready: (instance) => reportErrors(instance),
            ondone: () => reportErrors(),
            onResponseChange: (value) => {
                lastResponseRef.current = value;
                liveRef.current.onResponseChange(value);
                reportErrors();
            },
            onStateChange: (value) => liveRef.current.onStateChange?.(value),
        };

        const instance = resolved.module.mount(host, ctx);
        instanceRef.current = instance;
        lastResponseRef.current = liveRef.current.response;

        return () => {
            releaseRef.current?.();
            releaseRef.current = null;
            instanceRef.current = null;
            instance.destroy();
        };
        /* Remount identity: the item session, the resolved module and the
           served definition. A response change never remounts, or a
           half-completed placement would be lost on every keystroke. */
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typeId, questionId, definition, resolved.module, registryTick, mountEl, locale]);

    /* A response set from outside (a resume, a jump back to a screen) is
       pushed in; one the module itself just reported is not, or the module
       would be re-rendered under the learner's cursor. */
    useEffect(() => {
        if (instanceRef.current !== null && response !== lastResponseRef.current) {
            lastResponseRef.current = response;
            instanceRef.current.setResponse(response);
        }
    }, [response]);

    useEffect(() => {
        instanceRef.current?.setStatus(status);
    }, [status]);

    return <div ref={hostRef} className={className} data-vy-question-type={typeId} />;
}
