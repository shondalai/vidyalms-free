import type {
    AvailabilityLeaf,
    AvailabilityNode,
    AvailabilityPresetInfo,
} from './flow-types';

/*
 * The restriction tree, as pure functions.
 *
 * Everything the authoring box does to a stored rule happens here and is
 * testable without React, for the same reason CohortScheduleResolver is a
 * pure function on the server: the interesting failures are structural, not
 * visual.
 *
 * Two structural rules are load-bearing and are enforced on every write
 * rather than checked at the end.
 *
 * `showc` is a boolean array EXACTLY as long as `c`, and applies under `and`
 * and `not_or`, where a failure identifies which child failed so hiding or
 * explaining is a per-child decision. `show` is a single boolean and applies
 * under `or` and `not_and`, where a failure means every child failed and
 * there is one combined message. The split falls out of the logic rather
 * than from taste, and a length mismatch is the single most reported
 * structural fault in the model this one is taken from, so every operation
 * that changes the length of `c` fixes `showc` in the same step.
 *
 * A path is the dotted address the server uses in its warnings and its
 * preview leaves ("c.0.c.2"), so an author's row and a server message point
 * at the same thing without a lookup table.
 */

export type TreeChild = AvailabilityNode | AvailabilityLeaf;

export const OPERATORS = ['and', 'or', 'not_and', 'not_or'] as const;
export type Operator = (typeof OPERATORS)[number];

export function isNode(child: TreeChild): child is AvailabilityNode {
    return typeof (child as AvailabilityNode).op === 'string' && Array.isArray((child as AvailabilityNode).c);
}

export function isLeaf(child: TreeChild): child is AvailabilityLeaf {
    return !isNode(child);
}

/** Whether this operator carries a per-child `showc` rather than one `show`. */
export function usesShowc(op: string): boolean {
    return op === 'and' || op === 'not_or';
}

export function emptyTree(op: Operator = 'and'): AvailabilityNode {
    return { op, c: [], showc: [] };
}

/**
 * The node with `show` and `showc` made consistent with its operator and its
 * children. Copied rather than mutated; every writer here funnels through it.
 */
export function normaliseNode(node: AvailabilityNode, defaultShown = true): AvailabilityNode {
    const children = node.c.map((child) => (isNode(child) ? normaliseNode(child, defaultShown) : child));
    if (usesShowc(node.op)) {
        const existing = node.showc ?? [];
        return {
            op: node.op,
            c: children,
            showc: children.map((_, index) => existing[index] ?? defaultShown),
        };
    }
    return {
        op: node.op,
        c: children,
        show: node.show ?? defaultShown,
    };
}

/** The dotted address of the child at `index` inside the node at `path`. */
export function childPath(path: string, index: number): string {
    return path === '' ? `c.${index}` : `${path}.c.${index}`;
}

/**
 * The indices a dotted path names, or null when it names nothing.
 *
 * Null and the empty list are different answers and the difference matters:
 * the empty list is the ROOT, so a malformed path that fell back to it would
 * silently address the whole tree, and an editor writing through it would
 * replace every restriction on the item with one child.
 */
function steps(path: string): number[] | null {
    if (path === '') {
        return [];
    }
    const parts = path.split('.');
    if (parts.length % 2 !== 0) {
        return null;
    }
    const indices: number[] = [];
    for (let i = 0; i < parts.length; i += 2) {
        if (parts[i] !== 'c') {
            return null;
        }
        const index = Number(parts[i + 1]);
        if (!Number.isInteger(index) || index < 0) {
            return null;
        }
        indices.push(index);
    }
    return indices;
}

/** The child at a dotted path, or null when the path names nothing. */
export function childAt(root: AvailabilityNode, path: string): TreeChild | null {
    if (path === '') {
        return root;
    }
    const indices = steps(path);
    if (indices === null) {
        return null;
    }
    let current: TreeChild = root;
    for (const index of indices) {
        if (!isNode(current)) {
            return null;
        }
        const next: TreeChild | undefined = current.c[index];
        if (next === undefined) {
            return null;
        }
        current = next;
    }
    return current;
}

function mapNodeAt(
    node: AvailabilityNode,
    indices: readonly number[],
    change: (node: AvailabilityNode) => AvailabilityNode,
): AvailabilityNode {
    const [head, ...rest] = indices;
    if (head === undefined) {
        return change(node);
    }
    const target = node.c[head];
    if (target === undefined || !isNode(target)) {
        return node;
    }
    const children = [...node.c];
    children[head] = mapNodeAt(target, rest, change);
    return { ...node, c: children };
}

/** The tree with the node at `path` replaced by `change(node)`. */
export function updateNodeAt(
    root: AvailabilityNode,
    path: string,
    change: (node: AvailabilityNode) => AvailabilityNode,
): AvailabilityNode {
    const indices = steps(path);
    /* A path that names nothing changes nothing. Falling back to the root
       would apply the edit to the whole tree instead. */
    return indices === null ? root : normaliseNode(mapNodeAt(root, indices, change));
}

/** The tree with one child appended to the node at `path`. */
export function appendChild(root: AvailabilityNode, path: string, child: TreeChild, shown = true): AvailabilityNode {
    return updateNodeAt(root, path, (node) => {
        const children = [...node.c, child];
        if (usesShowc(node.op)) {
            return { ...node, c: children, showc: [...(node.showc ?? []), shown] };
        }
        return { ...node, c: children };
    });
}

/** The tree with one child removed from the node at `path`. */
export function removeChild(root: AvailabilityNode, path: string, index: number): AvailabilityNode {
    return updateNodeAt(root, path, (node) => {
        const children = node.c.filter((_, position) => position !== index);
        if (usesShowc(node.op)) {
            return {
                ...node,
                c: children,
                showc: (node.showc ?? []).filter((_, position) => position !== index),
            };
        }
        return { ...node, c: children };
    });
}

/** The tree with one child replaced in place. */
export function replaceChild(
    root: AvailabilityNode,
    path: string,
    index: number,
    child: TreeChild,
): AvailabilityNode {
    return updateNodeAt(root, path, (node) => {
        const children = [...node.c];
        if (children[index] === undefined) {
            return node;
        }
        children[index] = child;
        return { ...node, c: children };
    });
}

/** The tree with the operator of the node at `path` changed. */
export function setOperator(root: AvailabilityNode, path: string, op: Operator, defaultShown = true): AvailabilityNode {
    const indices = steps(path);
    if (indices === null) {
        return root;
    }
    return normaliseNode(
        mapNodeAt(root, indices, (node) => ({ op, c: node.c, show: node.show, showc: node.showc })),
        defaultShown,
    );
}

/** The tree with one child's visibility flipped, wherever that flag lives. */
export function setChildShown(
    root: AvailabilityNode,
    path: string,
    index: number,
    shown: boolean,
): AvailabilityNode {
    return updateNodeAt(root, path, (node) => {
        if (!usesShowc(node.op)) {
            return { ...node, show: shown };
        }
        const showc = node.c.map((_, position) => (node.showc ?? [])[position] ?? true);
        showc[index] = shown;
        return { ...node, showc };
    });
}

/** Whether the child at `index` is shown with its reason or hidden. */
export function childShown(node: AvailabilityNode, index: number): boolean {
    return usesShowc(node.op) ? ((node.showc ?? [])[index] ?? true) : (node.show ?? true);
}

export function countLeaves(child: TreeChild): number {
    if (!isNode(child)) {
        return 1;
    }
    return child.c.reduce((total, entry) => total + countLeaves(entry), 0);
}

/**
 * A tree with no leaf anywhere is not a restriction, and the contract says a
 * null tree clears the rule. Saving an empty shell instead would store a
 * document that gates nothing and reads as a restriction on every screen.
 */
export function isEmptyTree(root: AvailabilityNode | null): boolean {
    return root === null || countLeaves(root) === 0;
}

/**
 * A preset compiled into a leaf.
 *
 * The preset layer is configuration over the same grammar, so this produces
 * an ordinary leaf and nothing a preset makes is unreachable from the raw
 * editor. The `preset` member is presentational only: it lets the form offer
 * the simple editor again rather than dropping the author into the general
 * one, and the stored tree is identical either way.
 */
export function leafFromPreset(preset: AvailabilityPresetInfo, values: Record<string, unknown>): AvailabilityLeaf {
    const payload: Record<string, unknown> = { ...values };
    if (typeof preset.anchor_key === 'string' && preset.anchor_key !== '') {
        payload.anchor = preset.anchor_key;
    }
    return { type: preset.condition_key, payload, preset: preset.key };
}
