import type { ComponentType } from 'react';
import type { components } from '@vidyalms/api-sdk';

/*
 * The certificate page designer registry contract, the fifth published
 * registry on window.vidyalms.
 *
 * The free build owns the whole template aggregate: the pages, the elements,
 * the element palette from the server's own registry, the settings form each
 * element declares, the preview and the publication findings. What it does
 * NOT own is dragging: 13-learning-flow-and-credentials.md puts "the
 * drag-and-drop designer" in the Pro column and "the page and element
 * tables, the layout service, the elements renderer" in the free one.
 *
 * So the free build ships a complete FORM editor over the same aggregate,
 * and this registry is how a package contributes a canvas that moves the
 * same elements with a pointer. A miss is a normal path: with nothing
 * registered the form editor is what an author uses, and every element is
 * still addable, positionable, editable and removable. That is the direction
 * the core's registry-miss table publishes for an authoring surface.
 *
 * The slot set is closed and the renderers are open, exactly as the runner
 * panels are. A slot is a place in the admin's own DOM with its own props,
 * so a new slot is an admin change whatever registry it went through; a new
 * DESIGNER for the one slot, which is what a package actually needs, is a
 * registration and nothing else.
 *
 * A designer never writes. It reports intent through the callbacks below and
 * the free screen owns every request, so a package cannot invent a payload
 * shape, skip the optimistic bookkeeping or bypass the idempotency key.
 */

/** Additive only after 1: a new optional member never raises this number. */
export const CERTIFICATE_DESIGNER_CONTRACT = 1;

export type CertificateTemplateDetail = components['schemas']['CertificateTemplateDetail'];
export type CertificateTemplatePage = components['schemas']['CertificateTemplatePage'];
export type CertificateTemplateElement = components['schemas']['CertificateTemplateElement'];
export type CertificateElementType = components['schemas']['CertificateElementType'];
export type TokenSource = components['schemas']['TokenSource'];

/** Where an element sits on its page, in millimetres from the page origin. */
export interface ElementPlacement {
    posx: number;
    posy: number;
    width?: number | null;
    height?: number | null;
}

export interface CertificatePageDesignerProps {
    /** The whole aggregate, so a designer can read the template's format and locale. */
    template: CertificateTemplateDetail;
    /** The page being drawn, with its elements in draw order. */
    page: CertificateTemplatePage;
    /**
     * The palette as the server declared it for THIS template, including the
     * types it refuses to offer and why. A designer renders what arrives and
     * never a list of its own: a package adding an element is a registration.
     */
    elementTypes: readonly CertificateElementType[];
    /** The token vocabulary, for an autocomplete a canvas may offer. */
    tokens: readonly TokenSource[];
    selectedElementId: number | null;
    onSelect: (elementId: number | null) => void;
    /** Move or resize one element. The screen sends the PATCH. */
    onPlace: (elementId: number, placement: ElementPlacement) => void;
    /** The whole ordered list of element ids for this page, never a swap. */
    onReorder: (elementIds: readonly number[]) => void;
    /** Add an element of this type, optionally at a point on the page. */
    onAdd: (elementKey: string, at?: { posx: number; posy: number }) => void;
    onRemove: (elementId: number) => void;
    /** True while a write is in flight; a designer disables its own controls. */
    busy: boolean;
    /** True when the template is locked, so nothing may be moved. */
    readOnly: boolean;
    locale: string;
    /** A DOM id unique to this designer, for labels and error links. */
    controlId: (local: string) => string;
    /** The shell's live region; no designer creates a region of its own. */
    announce: (message: string) => void;
}

/** The slots the admin offers, with the props each one is handed. */
export interface CertificateDesignerPropsBySlot {
    page_designer: CertificatePageDesignerProps;
}

export type CertificateDesignerSlot = keyof CertificateDesignerPropsBySlot;

export const CERTIFICATE_DESIGNER_SLOTS = ['page_designer'] as const;

export function isCertificateDesignerSlot(slot: string): slot is CertificateDesignerSlot {
    return (CERTIFICATE_DESIGNER_SLOTS as readonly string[]).includes(slot);
}

export interface CertificateDesignerModule<S extends CertificateDesignerSlot = CertificateDesignerSlot> {
    /** The contract version the module was written against. */
    version: number;
    /**
     * The React major the designer was built against. A second React breaks
     * hooks silently, so the pairing fails at registration rather than at
     * render, exactly as an authoring block's does.
     */
    reactMajor?: number;
    Designer: ComponentType<CertificateDesignerPropsBySlot[S]>;
    /**
     * What the tab that offers the canvas is called, so the free screen does
     * not have to name a package's feature in its own copy.
     */
    label?: string;
}

export type CertificateDesignerListener = (event: {
    kind: 'register' | 'replace';
    slot: CertificateDesignerSlot;
}) => void;

export interface CertificateDesignerRegistryApi {
    readonly contract: number;
    register<S extends CertificateDesignerSlot>(slot: S, module: CertificateDesignerModule<S>): void;
    /** Decorates: the displaced module is returned so an override can delegate. */
    replace<S extends CertificateDesignerSlot>(
        slot: S,
        module: CertificateDesignerModule<S>,
    ): CertificateDesignerModule<S>;
    has(slot: string): boolean;
    get<S extends CertificateDesignerSlot>(slot: S): CertificateDesignerModule<S> | null;
    slots(): readonly CertificateDesignerSlot[];
    /** A deferred chunk that registers late still renders without a reload. */
    subscribe(listener: CertificateDesignerListener): () => void;
}

export class CertificateDesignerRegistrationError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'CertificateDesignerRegistrationError';
    }
}
