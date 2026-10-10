import { describe, expect, it } from 'vitest';
import { elementBox, horizontalAnchorOf, storedPositionFor, verticalAnchorOf } from './layout';
import type { LayoutElement, LayoutPage } from './layout';

/*
 * The layout port against the layout service.
 *
 * PHP_BOXES is not hand-written. Every row is what
 * CertificateLayout::boxFor() answered for that case on an A4 landscape page
 * with 10mm margins, dumped from the PHP itself over the full cross of the
 * seven reference points the contract declares, the legacy American spelling
 * a stored row may still carry, both width states and both height states.
 * Regenerate it by running boxFor() over the same cross when the PHP
 * changes; a port that drifts is worse than no port, because the canvas then
 * paints one position and the certificate renders another.
 */

const PAGE: LayoutPage = { width: 297, height: 210, margin_left: 10, margin_right: 10 };

type Row = readonly [
    refpoint: string,
    posx: number,
    posy: number,
    width: number | null,
    height: number | null,
    left: number,
    top: number,
    boxWidth: number,
    boxHeight: number | null,
];

const PHP_BOXES: readonly Row[] = [
    ['top_left', 20, 40, null, null, 20, 40, 267, null],
    ['top_left', 148, 105, null, null, 148, 105, 139, null],
    ['top_left', 280, 190, null, null, 280, 190, 7, null],
    ['top_left', 20, 40, 80, null, 20, 40, 80, null],
    ['top_left', 148, 105, 80, null, 148, 105, 80, null],
    ['top_left', 280, 190, 80, null, 280, 190, 80, null],
    ['top_left', 20, 40, 80, 20, 20, 40, 80, 20],
    ['top_left', 148, 105, 80, 20, 148, 105, 80, 20],
    ['top_left', 280, 190, 80, 20, 280, 190, 80, 20],
    ['top_left', 20, 40, null, 20, 20, 40, 267, 20],
    ['top_left', 148, 105, null, 20, 148, 105, 139, 20],
    ['top_left', 280, 190, null, 20, 280, 190, 7, 20],
    ['top_centre', 20, 40, null, null, -118.5, 40, 277, null],
    ['top_centre', 148, 105, null, null, 9.5, 105, 277, null],
    ['top_centre', 280, 190, null, null, 141.5, 190, 277, null],
    ['top_centre', 20, 40, 80, null, -20, 40, 80, null],
    ['top_centre', 148, 105, 80, null, 108, 105, 80, null],
    ['top_centre', 280, 190, 80, null, 240, 190, 80, null],
    ['top_centre', 20, 40, 80, 20, -20, 40, 80, 20],
    ['top_centre', 148, 105, 80, 20, 108, 105, 80, 20],
    ['top_centre', 280, 190, 80, 20, 240, 190, 80, 20],
    ['top_centre', 20, 40, null, 20, -118.5, 40, 277, 20],
    ['top_centre', 148, 105, null, 20, 9.5, 105, 277, 20],
    ['top_centre', 280, 190, null, 20, 141.5, 190, 277, 20],
    ['top_center', 20, 40, null, null, -118.5, 40, 277, null],
    ['top_center', 148, 105, null, null, 9.5, 105, 277, null],
    ['top_center', 280, 190, null, null, 141.5, 190, 277, null],
    ['top_center', 20, 40, 80, null, -20, 40, 80, null],
    ['top_center', 148, 105, 80, null, 108, 105, 80, null],
    ['top_center', 280, 190, 80, null, 240, 190, 80, null],
    ['top_center', 20, 40, 80, 20, -20, 40, 80, 20],
    ['top_center', 148, 105, 80, 20, 108, 105, 80, 20],
    ['top_center', 280, 190, 80, 20, 240, 190, 80, 20],
    ['top_center', 20, 40, null, 20, -118.5, 40, 277, 20],
    ['top_center', 148, 105, null, 20, 9.5, 105, 277, 20],
    ['top_center', 280, 190, null, 20, 141.5, 190, 277, 20],
    ['top_right', 20, 40, null, null, -257, 40, 277, null],
    ['top_right', 148, 105, null, null, -129, 105, 277, null],
    ['top_right', 280, 190, null, null, 3, 190, 277, null],
    ['top_right', 20, 40, 80, null, -60, 40, 80, null],
    ['top_right', 148, 105, 80, null, 68, 105, 80, null],
    ['top_right', 280, 190, 80, null, 200, 190, 80, null],
    ['top_right', 20, 40, 80, 20, -60, 40, 80, 20],
    ['top_right', 148, 105, 80, 20, 68, 105, 80, 20],
    ['top_right', 280, 190, 80, 20, 200, 190, 80, 20],
    ['top_right', 20, 40, null, 20, -257, 40, 277, 20],
    ['top_right', 148, 105, null, 20, -129, 105, 277, 20],
    ['top_right', 280, 190, null, 20, 3, 190, 277, 20],
    ['centre', 20, 40, null, null, -118.5, 40, 277, null],
    ['centre', 148, 105, null, null, 9.5, 105, 277, null],
    ['centre', 280, 190, null, null, 141.5, 190, 277, null],
    ['centre', 20, 40, 80, null, -20, 40, 80, null],
    ['centre', 148, 105, 80, null, 108, 105, 80, null],
    ['centre', 280, 190, 80, null, 240, 190, 80, null],
    ['centre', 20, 40, 80, 20, -20, 30, 80, 20],
    ['centre', 148, 105, 80, 20, 108, 95, 80, 20],
    ['centre', 280, 190, 80, 20, 240, 180, 80, 20],
    ['centre', 20, 40, null, 20, -118.5, 30, 277, 20],
    ['centre', 148, 105, null, 20, 9.5, 95, 277, 20],
    ['centre', 280, 190, null, 20, 141.5, 180, 277, 20],
    ['bottom_left', 20, 40, null, null, 20, 40, 267, null],
    ['bottom_left', 148, 105, null, null, 148, 105, 139, null],
    ['bottom_left', 280, 190, null, null, 280, 190, 7, null],
    ['bottom_left', 20, 40, 80, null, 20, 40, 80, null],
    ['bottom_left', 148, 105, 80, null, 148, 105, 80, null],
    ['bottom_left', 280, 190, 80, null, 280, 190, 80, null],
    ['bottom_left', 20, 40, 80, 20, 20, 20, 80, 20],
    ['bottom_left', 148, 105, 80, 20, 148, 85, 80, 20],
    ['bottom_left', 280, 190, 80, 20, 280, 170, 80, 20],
    ['bottom_left', 20, 40, null, 20, 20, 20, 267, 20],
    ['bottom_left', 148, 105, null, 20, 148, 85, 139, 20],
    ['bottom_left', 280, 190, null, 20, 280, 170, 7, 20],
    ['bottom_centre', 20, 40, null, null, -118.5, 40, 277, null],
    ['bottom_centre', 148, 105, null, null, 9.5, 105, 277, null],
    ['bottom_centre', 280, 190, null, null, 141.5, 190, 277, null],
    ['bottom_centre', 20, 40, 80, null, -20, 40, 80, null],
    ['bottom_centre', 148, 105, 80, null, 108, 105, 80, null],
    ['bottom_centre', 280, 190, 80, null, 240, 190, 80, null],
    ['bottom_centre', 20, 40, 80, 20, -20, 20, 80, 20],
    ['bottom_centre', 148, 105, 80, 20, 108, 85, 80, 20],
    ['bottom_centre', 280, 190, 80, 20, 240, 170, 80, 20],
    ['bottom_centre', 20, 40, null, 20, -118.5, 20, 277, 20],
    ['bottom_centre', 148, 105, null, 20, 9.5, 85, 277, 20],
    ['bottom_centre', 280, 190, null, 20, 141.5, 170, 277, 20],
    ['bottom_right', 20, 40, null, null, -257, 40, 277, null],
    ['bottom_right', 148, 105, null, null, -129, 105, 277, null],
    ['bottom_right', 280, 190, null, null, 3, 190, 277, null],
    ['bottom_right', 20, 40, 80, null, -60, 40, 80, null],
    ['bottom_right', 148, 105, 80, null, 68, 105, 80, null],
    ['bottom_right', 280, 190, 80, null, 200, 190, 80, null],
    ['bottom_right', 20, 40, 80, 20, -60, 20, 80, 20],
    ['bottom_right', 148, 105, 80, 20, 68, 85, 80, 20],
    ['bottom_right', 280, 190, 80, 20, 200, 170, 80, 20],
    ['bottom_right', 20, 40, null, 20, -257, 20, 277, 20],
    ['bottom_right', 148, 105, null, 20, -129, 85, 277, 20],
    ['bottom_right', 280, 190, null, 20, 3, 170, 277, 20],
];

function elementOf(row: Row, alignment = 'centre'): LayoutElement {
    return { posx: row[1], posy: row[2], width: row[3], height: row[4], refpoint: row[0], alignment };
}

describe('the shared certificate layout arithmetic', () => {
    it.each(PHP_BOXES)(
        'places a %s element at %s, %s with width %s and height %s exactly as the layout service does',
        (...row: Row) => {
            const box = elementBox(PAGE, elementOf(row));

            expect(box.left).toBeCloseTo(row[5], 6);
            expect(box.top).toBeCloseTo(row[6], 6);
            expect(box.width).toBeCloseTo(row[7], 6);
            expect(box.height).toBe(row[8]);
        },
    );

    it('reads the same alignment vocabulary the layout service does, including the two spellings', () => {
        const cases: Record<string, string> = {
            left: 'left',
            center: 'center',
            centre: 'center',
            right: 'right',
            justify: 'justify',
            nonsense: 'left',
        };

        for (const [alignment, expected] of Object.entries(cases)) {
            const element: LayoutElement = { posx: 20, posy: 40, width: 60, refpoint: 'top_left', alignment };
            expect(elementBox(PAGE, element).textAlign).toBe(expected);
        }
    });

    it('reads an unknown reference point as the top left rather than refusing to place the element', () => {
        expect(horizontalAnchorOf('acme.custom')).toBe('left');
        expect(verticalAnchorOf('acme.custom')).toBe('top');
    });

    it('does not clamp an element to the page, because moving it would hide the mistake', () => {
        const element: LayoutElement = { posx: 10, posy: 40, width: 80, refpoint: 'top_centre' };

        expect(elementBox(PAGE, element).left).toBe(-30);
    });
});

describe('the inverse, which is what a designer writes back', () => {
    it.each(PHP_BOXES)(
        'turns the box of a %s element at %s, %s back into the position that produced it',
        (...row: Row) => {
            const element = elementOf(row);
            const box = elementBox(PAGE, element);

            const stored = storedPositionFor(PAGE, element, box);

            expect(stored.posx).toBeCloseTo(row[1], 6);
            expect(stored.posy).toBeCloseTo(row[2], 6);
        },
    );

    it('answers the position that puts a centre-anchored box where a pointer dropped it', () => {
        const element: LayoutElement = { posx: 148, posy: 105, width: 80, height: 20, refpoint: 'centre' };

        const stored = storedPositionFor(PAGE, element, { left: 40, top: 60 });

        expect(stored).toEqual({ posx: 80, posy: 70 });
        expect(elementBox(PAGE, { ...element, ...stored }).left).toBe(40);
        expect(elementBox(PAGE, { ...element, ...stored }).top).toBe(60);
    });

    it('answers the position that puts a right-anchored box where a pointer dropped it', () => {
        const element: LayoutElement = { posx: 280, posy: 190, width: 60, refpoint: 'top_right' };

        const stored = storedPositionFor(PAGE, element, { left: 100, top: 50 });

        expect(stored).toEqual({ posx: 160, posy: 50 });
        expect(elementBox(PAGE, { ...element, ...stored }).left).toBe(100);
    });
});
