import { useCallback, useRef, useState, type KeyboardEvent } from 'react';
import type { Announcer } from '@/shared/a11y';
import type { QuestionShell } from '../contract';
import { primitiveStrings as strings } from '../strings';

/*
 * Select then place, built once and reused by ordering, matching, gap match,
 * hotspot placement and graphic gap match.
 *
 * Two single-pointer activations: activate the thing to move, which enters a
 * visibly and programmatically indicated selected-to-move state, then
 * activate the destination. No drag, no path gesture, which is what SC 2.5.7
 * requires and what keyboard support alone does not satisfy. The alternative
 * must never itself be a path-based gesture such as swipe to reorder, which
 * would fail SC 2.5.1 at Level A.
 *
 * It is the same state machine as the keyboard grab and move model, so the
 * two are one implementation rather than two that drift.
 *
 * Grabbed state is aria-pressed on a toggle button plus a live-region
 * announcement. aria-grabbed and aria-dropeffect are never used: both were
 * deprecated in WAI-ARIA 1.1 with poor support and a confusing result.
 *
 * Escape always releases, so no grab mode can trap focus (SC 2.1.2).
 */

export interface HeldItem {
    id: string;
    label: string;
}

/**
 * The attribute that marks a destination a held item may be dropped on.
 *
 * The conformance kit reads it: "during a drag, Tab visits only drop targets
 * that accept the held item and everything else is hidden from assistive
 * technology" is a rule about the DOM, and a rule nothing asserts is a rule
 * that regresses on the next widget.
 */
export const DROP_TARGET_ATTR = 'data-vy-drop-target';

/** The same for the item being held, which keeps focus during a grab. */
export const HELD_ATTR = 'data-vy-held';

export interface SelectThenPlaceOptions {
    announce: Announcer;
    shell?: Pick<QuestionShell, 'captureInteraction' | 'releaseInteraction'> | null;
    /** Returns false to reject the placement and keep the item held. */
    onPlace: (heldId: string, targetId: string) => boolean | void;
    /** Called when a grab is abandoned, so a caller can restore an order. */
    onCancel?: (heldId: string) => void;
}

export interface SelectThenPlace {
    held: HeldItem | null;
    isHeld: (id: string) => boolean;
    grab: (id: string, label: string) => void;
    toggle: (id: string, label: string) => void;
    place: (targetId: string, targetLabel: string) => void;
    cancel: () => void;
    /** Wire onto the widget container: Escape releases from anywhere inside. */
    onKeyDown: (event: KeyboardEvent<HTMLElement>) => void;

    /**
     * Props for a control or a row that is neither a destination nor the
     * held item: inert while a grab is live.
     *
     * The shell's captureInteraction contains everything outside the item
     * body, which left every control inside the widget in the tab sequence,
     * so Tab during a grab walked the move buttons, the remove buttons and
     * the other rows before it reached a destination. The design states the
     * opposite: Tab visits only drop targets that accept the held item, and
     * everything else is hidden from assistive technology.
     *
     * Pass the row's own id to keep the held row operable, which is where
     * focus stays; pass nothing for a control that is never a destination.
     */
    contained: (id?: string) => { inert?: true };

    /**
     * Props marking a destination, so the containment above has something to
     * leave reachable and the conformance kit has something to assert.
     */
    target: () => Record<string, string>;

    /** The same for the held item itself, which never leaves the sequence. */
    heldProps: (id: string) => Record<string, string>;
}

export function useSelectThenPlace(options: SelectThenPlaceOptions): SelectThenPlace {
    const [held, setHeld] = useState<HeldItem | null>(null);
    const optionsRef = useRef(options);
    optionsRef.current = options;
    /*
     * The held item, readable without closing over it, so cancel() can be a
     * stable callback without doing its work inside a state updater. It used
     * to read the current value by passing a function to setHeld and calling
     * onCancel from inside it, and a state updater must be pure: onCancel
     * restores the caller's order, so a cancelled grab set a parent's state
     * from inside a child's render and React said so.
     */
    const heldRef = useRef<HeldItem | null>(null);
    heldRef.current = held;

    const release = useCallback(() => {
        optionsRef.current.shell?.releaseInteraction();
    }, []);

    const grab = useCallback(
        (id: string, label: string) => {
            setHeld({ id, label });
            optionsRef.current.shell?.captureInteraction();
            optionsRef.current.announce('status', strings.grabbed(label));
        },
        [],
    );

    const cancel = useCallback(() => {
        const current = heldRef.current;
        if (current === null) {
            return;
        }
        heldRef.current = null;
        setHeld(null);
        optionsRef.current.onCancel?.(current.id);
        optionsRef.current.announce('status', strings.cancelled(current.label));
        release();
    }, [release]);

    const place = useCallback(
        (targetId: string, targetLabel: string) => {
            const current = held;
            if (current === null) {
                return;
            }
            const accepted = optionsRef.current.onPlace(current.id, targetId);
            if (accepted === false) {
                return;
            }
            optionsRef.current.announce('status', strings.placed(current.label, targetLabel));
            setHeld(null);
            release();
        },
        [held, release],
    );

    const toggle = useCallback(
        (id: string, label: string) => {
            if (held !== null && held.id === id) {
                cancel();
                return;
            }
            grab(id, label);
        },
        [held, cancel, grab],
    );

    const onKeyDown = useCallback(
        (event: KeyboardEvent<HTMLElement>) => {
            if (event.key === 'Escape' && held !== null) {
                event.preventDefault();
                event.stopPropagation();
                cancel();
            }
        },
        [held, cancel],
    );

    return {
        held,
        isHeld: (id: string) => held !== null && held.id === id,
        grab,
        toggle,
        place,
        cancel,
        onKeyDown,
        contained: (id?: string) =>
            held !== null && (id === undefined || held.id !== id) ? { inert: true } : {},
        target: () => ({ [DROP_TARGET_ATTR]: '' }),
        heldProps: (id: string): Record<string, string> =>
            held !== null && held.id === id ? { [HELD_ATTR]: '' } : {},
    };
}
