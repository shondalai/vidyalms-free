/*
 * Explicit order is first-class in this app (artboard 1a): every list of
 * modules, lessons and questions is reordered by sending the WHOLE list in
 * the wanted order, which is exactly what the contract's order routes take.
 * These helpers produce that list; nothing computes a position by hand.
 */

/** The list with the item at `from` moved to `to`, or the same list when the move is a no-op. */
export function moveItem<T>(items: readonly T[], from: number, to: number): T[] {
    if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
        return [...items];
    }
    const next = [...items];
    const [moved] = next.splice(from, 1);
    if (moved === undefined) {
        return [...items];
    }
    next.splice(to, 0, moved);
    return next;
}

/**
 * A move made among the rows the reader can see, expressed over the whole
 * list the server holds.
 *
 * An archived module or lesson is out of the ordering controls but still a
 * row of its course, so the order write has to carry it: the body lists
 * every module of the course exactly once. Moving the visible item to the
 * full-list position of the visible item it displaces keeps every hidden
 * row on the side of the move it was already on.
 *
 * @param full    every row the server answered with, in its order
 * @param visible the subset shown, in the same relative order
 */
export function orderAfterVisibleMove<T>(
    full: readonly T[],
    visible: readonly T[],
    from: number,
    to: number,
): T[] {
    const source = visible[from];
    const target = visible[to];
    if (source === undefined || target === undefined) {
        return [...full];
    }
    const fullFrom = full.indexOf(source);
    const fullTo = full.indexOf(target);
    if (fullFrom < 0 || fullTo < 0) {
        return [...full];
    }
    return moveItem(full, fullFrom, fullTo);
}

/**
 * The keys of a drag: where the pointer started and where it was dropped.
 * Both come from the list's own indexes, so a drop outside the list (index
 * null) leaves the order alone.
 */
export function applyDrop<T>(items: readonly T[], from: number | null, to: number | null): T[] {
    if (from === null || to === null) {
        return [...items];
    }
    return moveItem(items, from, to);
}
