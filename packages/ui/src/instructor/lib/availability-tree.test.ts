import { describe, expect, it } from 'vitest';
import {
    appendChild,
    childAt,
    childPath,
    childShown,
    countLeaves,
    emptyTree,
    isEmptyTree,
    isLeaf,
    isNode,
    leafFromPreset,
    normaliseNode,
    removeChild,
    replaceChild,
    setChildShown,
    setOperator,
    usesShowc,
} from './availability-tree';
import type { AvailabilityLeaf, AvailabilityNode, AvailabilityPresetInfo } from './flow-types';

/*
 * The tree, as pure functions.
 *
 * The invariant every test here is really about: `showc` is EXACTLY as long
 * as `c` after every operation that changes the length of `c`. A mismatch is
 * the single most reported structural fault in the model this grammar comes
 * from, and it is refused at save, so an editor that could produce one would
 * write a document the server rejects with no obvious cause.
 */

function leaf(type: string): AvailabilityLeaf {
    return { type, payload: {} };
}

function tree(): AvailabilityNode {
    return normaliseNode({ op: 'and', c: [leaf('date'), leaf('completion')] });
}

describe('shape', () => {
    it('tells a node from a leaf without looking at anything but the shape', () => {
        expect(isNode(tree())).toBe(true);
        expect(isLeaf(leaf('date'))).toBe(true);
        expect(isNode(leaf('date'))).toBe(false);
    });

    it('knows which operators carry a per-child visibility flag and which carry one', () => {
        expect(usesShowc('and')).toBe(true);
        expect(usesShowc('not_or')).toBe(true);
        expect(usesShowc('or')).toBe(false);
        expect(usesShowc('not_and')).toBe(false);
    });
});

describe('the showc invariant', () => {
    it('gives a fresh and-node one flag per child', () => {
        const node = tree();
        expect(node.showc).toEqual([true, true]);
        expect(node.show).toBeUndefined();
    });

    it('grows the flags with the children', () => {
        const node = appendChild(tree(), '', leaf('score'), false);
        expect(node.c).toHaveLength(3);
        expect(node.showc).toEqual([true, true, false]);
    });

    it('shrinks the flags with the children, and drops the right one', () => {
        const three = appendChild(tree(), '', leaf('score'), false);
        const node = removeChild(three, '', 0);
        expect(node.c).toHaveLength(2);
        expect(node.showc).toEqual([true, false]);
    });

    it('swaps the per-child array for one flag when the operator changes, and back again', () => {
        const combined = setOperator(tree(), '', 'or');
        expect(combined.showc).toBeUndefined();
        expect(combined.show).toBe(true);

        const perChild = setOperator(combined, '', 'and');
        expect(perChild.show).toBeUndefined();
        expect(perChild.showc).toEqual([true, true]);
    });

    it('keeps the flags right at depth, not only at the root', () => {
        const nested = appendChild(tree(), '', emptyTree('and'));
        const deep = appendChild(nested, childPath('', 2), leaf('role'));
        const inner = childAt(deep, 'c.2');
        expect(inner !== null && isNode(inner) ? inner.showc : null).toEqual([true]);
    });
});

describe('visibility', () => {
    it('writes a per-child flag under and, and one combined flag under or', () => {
        const perChild = setChildShown(tree(), '', 1, false);
        expect(childShown(perChild, 0)).toBe(true);
        expect(childShown(perChild, 1)).toBe(false);

        const combined = setChildShown(setOperator(tree(), '', 'or'), '', 1, false);
        expect(combined.show).toBe(false);
        /* Under or a failure means every child failed, so there is one
           message for the set and asking per child gives the same answer. */
        expect(childShown(combined, 0)).toBe(false);
    });
});

describe('addressing', () => {
    it('uses the dotted path the server puts on its warnings and preview leaves', () => {
        expect(childPath('', 2)).toBe('c.2');
        expect(childPath('c.0', 1)).toBe('c.0.c.1');
    });

    it('resolves a path, and answers null rather than throwing on one that names nothing', () => {
        const nested = appendChild(tree(), '', emptyTree('or'));
        expect(childAt(nested, 'c.0')).toEqual(leaf('date'));
        expect(childAt(nested, '')).toBe(nested);
        expect(childAt(nested, 'c.9')).toBeNull();
        expect(childAt(nested, 'c.0.c.0')).toBeNull();
        expect(childAt(nested, 'nonsense')).toBeNull();
    });

    it('replaces one child in place without touching its siblings', () => {
        const node = replaceChild(tree(), '', 1, leaf('role'));
        expect(node.c.map((child) => (isLeaf(child) ? child.type : 'set'))).toEqual(['date', 'role']);
    });
});

describe('emptiness', () => {
    it('counts leaves through nesting', () => {
        const nested = appendChild(tree(), '', appendChild(emptyTree('or'), '', leaf('role')));
        expect(countLeaves(nested)).toBe(3);
    });

    /* A shell with no leaf gates nothing, and the contract says a null tree
       clears the rule. Storing the shell instead would leave a document that
       reads as a restriction on every screen and restricts nobody. */
    it('treats a tree with no leaf anywhere as no rule at all', () => {
        expect(isEmptyTree(null)).toBe(true);
        expect(isEmptyTree(emptyTree())).toBe(true);
        expect(isEmptyTree(appendChild(emptyTree(), '', emptyTree('or')))).toBe(true);
        expect(isEmptyTree(tree())).toBe(false);
    });
});

describe('presets', () => {
    const preset: AvailabilityPresetInfo = {
        key: 'available_after_enrolment',
        label: 'Available N days after enrolment',
        condition_key: 'relative_date',
        anchor_key: 'enrolment.enrolled_at',
        fields: [],
    };

    /* A preset is configuration over the same grammar. What it produces is
       an ordinary leaf, so nothing a preset can make is unreachable from the
       general editor and a fifth preset is a data change, not code. */
    it('compiles into an ordinary leaf carrying the anchor it names', () => {
        const compiled = leafFromPreset(preset, { offset_seconds: 604800, offset_unit: 'days' });
        expect(compiled.type).toBe('relative_date');
        expect(compiled.payload).toEqual({
            offset_seconds: 604800,
            offset_unit: 'days',
            anchor: 'enrolment.enrolled_at',
        });
    });

    it('remembers which preset authored it, for the form and for nothing else', () => {
        expect(leafFromPreset(preset, {}).preset).toBe('available_after_enrolment');
    });
});
