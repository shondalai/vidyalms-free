/*
 * The one TypeScript copy of the layout arithmetic, ported from
 * packages/core/src/Application/Credentials/CertificateLayout.php.
 *
 * The core owns position: an element implementation never manages its own,
 * which is mod_customcert's own 5.2.0 correction after the arithmetic in
 * nineteen element classes meant nineteen chances to get the centre
 * reference point wrong. A designer that draws the same elements has the
 * same obligation, and a designer that reimplements the arithmetic breaks it
 * in a way no server test can see: the author drags to what the canvas
 * paints, and the renderer puts the element somewhere else.
 *
 * So this file is a port and not a second implementation. It is the only
 * copy on the client, both the free form editor and any registered designer
 * read it, and layout.test.ts asserts every case against the values the PHP
 * itself produced. Changing the PHP means changing this and that table
 * together.
 *
 * Millimetres in the page's own coordinate space throughout, never pixels.
 * Nothing is clamped to the page: an element placed off the paper is an
 * authoring mistake a designer shows, and silently moving it would hide the
 * mistake while changing what a frozen snapshot renders.
 */

/** The page geometry the arithmetic reads. A full page satisfies it. */
export interface LayoutPage {
    width: number;
    height: number;
    margin_left: number;
    margin_right: number;
}

/** The element geometry the arithmetic reads. A full element satisfies it. */
export interface LayoutElement {
    posx: number;
    posy: number;
    width?: number | null;
    height?: number | null;
    refpoint: string;
    alignment?: string;
}

export type TextAlign = 'left' | 'center' | 'right' | 'justify';

/**
 * Where one element sits, in millimetres from the page's top left, after the
 * reference point has been applied. A null height means the element grows to
 * whatever its content needs.
 */
export interface ElementBox {
    left: number;
    top: number;
    width: number;
    height: number | null;
    textAlign: TextAlign;
}

type HorizontalAnchor = 'left' | 'centre' | 'right';
type VerticalAnchor = 'top' | 'middle' | 'bottom';

/**
 * The horizontal meaning of the stored posx. `top_center` is the legacy
 * American spelling kept readable for rows already stored; nothing offers it
 * for a new write.
 */
export function horizontalAnchorOf(refpoint: string): HorizontalAnchor {
    switch (refpoint) {
        case 'top_center':
        case 'top_centre':
        case 'centre':
        case 'bottom_centre':
            return 'centre';
        case 'top_right':
        case 'bottom_right':
            return 'right';
        default:
            return 'left';
    }
}

/** The vertical meaning of the stored posy, which only a declared height can honour. */
export function verticalAnchorOf(refpoint: string): VerticalAnchor {
    switch (refpoint) {
        case 'centre':
            return 'middle';
        case 'bottom_left':
        case 'bottom_centre':
        case 'bottom_right':
            return 'bottom';
        default:
            return 'top';
    }
}

function textAlignOf(alignment: string | undefined): TextAlign {
    switch (alignment) {
        case 'center':
        case 'centre':
            return 'center';
        case 'right':
            return 'right';
        case 'justify':
            return 'justify';
        default:
            return 'left';
    }
}

/**
 * A page's usable width, which is what an element with no declared width
 * fills from its own left edge.
 */
function defaultWidth(page: LayoutPage, element: LayoutElement): number {
    const usable = page.width - page.margin_right;
    const remaining =
        horizontalAnchorOf(element.refpoint) === 'left'
            ? usable - element.posx
            : page.width - page.margin_left - page.margin_right;

    return remaining > 0 ? remaining : 1;
}

/** The resolved width, which the reference point needs before it can place anything. */
export function elementWidth(page: LayoutPage, element: LayoutElement): number {
    return element.width !== null && element.width !== undefined && element.width > 0
        ? element.width
        : defaultWidth(page, element);
}

/** The one answer to "where is this element", for a designer and for the renderer alike. */
export function elementBox(page: LayoutPage, element: LayoutElement): ElementBox {
    const width = elementWidth(page, element);
    const height =
        element.height !== null && element.height !== undefined && element.height > 0
            ? element.height
            : null;

    const anchor = horizontalAnchorOf(element.refpoint);
    const left =
        anchor === 'centre' ? element.posx - width / 2 : anchor === 'right' ? element.posx - width : element.posx;

    return {
        left,
        top: topOf(element, height),
        width,
        height,
        textAlign: textAlignOf(element.alignment),
    };
}

/*
 * An element whose height the author left blank grows to whatever its
 * content needs, and the box is asked for before anything knows what that
 * is. So a bottom-anchored or centred element with no declared height is
 * placed from its top edge, exactly as the PHP states: guessing a height
 * would move an element a frozen snapshot has already rendered.
 */
function topOf(element: LayoutElement, height: number | null): number {
    if (height === null) {
        return element.posy;
    }

    switch (verticalAnchorOf(element.refpoint)) {
        case 'middle':
            return element.posy - height / 2;
        case 'bottom':
            return element.posy - height;
        default:
            return element.posy;
    }
}

/**
 * The inverse: the posx and posy that put this element's box at a given
 * point. A designer reports intent in the element's own stored coordinate
 * space and never in the box's, or the renderer reads the dropped left edge
 * as a centre and moves the element half its width every time.
 */
export function storedPositionFor(
    page: LayoutPage,
    element: LayoutElement,
    box: { left: number; top: number },
): { posx: number; posy: number } {
    const width = elementWidth(page, element);
    const anchor = horizontalAnchorOf(element.refpoint);
    const posx = anchor === 'centre' ? box.left + width / 2 : anchor === 'right' ? box.left + width : box.left;

    const height =
        element.height !== null && element.height !== undefined && element.height > 0
            ? element.height
            : null;

    if (height === null) {
        return { posx, posy: box.top };
    }

    switch (verticalAnchorOf(element.refpoint)) {
        case 'middle':
            return { posx, posy: box.top + height / 2 };
        case 'bottom':
            return { posx, posy: box.top + height };
        default:
            return { posx, posy: box.top };
    }
}
