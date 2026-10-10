import { cn } from '@/shared/cn';

/*
 * The shared look of every primitive, in one place so an audit has one
 * subject rather than eight.
 *
 * Every state indicator carries a non-colour channel as well as colour (SC
 * 1.4.1): a border weight change, a shape, a glyph or text. Selected rows go
 * from a 1px border to a 2px accent border and gain a filled marker, so a
 * monochrome rendering still reads. Interactive targets are at least 24 by
 * 24 CSS pixels (SC 2.5.8); the choice rows are 46px tall, which also gives
 * the 44px comfort target the artboards ask for.
 *
 * Nothing here sets a fixed height on a text-bearing box or positions a
 * label absolutely over an image, because both lose content at line height
 * 1.5 and letter spacing 0.12em (SC 1.4.12).
 */

/*
 * The focus ring a choice row wears when the visually hidden input inside it
 * takes focus (SC 2.4.7). It is written as plain utilities on purpose: a
 * Tailwind arbitrary value turns every underscore into a space, so
 * `[outline:var(--_vy-focus)]` compiles to `outline: var(-- vy-focus)`, an
 * invalid custom property and therefore an empty rule, with no build error
 * and no visible ring. focusRingIsCompilable below is the regression test's
 * subject.
 */
export const focusRing =
    'has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-accent has-[:focus-visible]:outline-offset-2';

/**
 * True when no Tailwind arbitrary value in `classes` carries an underscore.
 * Tailwind rewrites `_` to a space inside `[...]`, so such a class silently
 * compiles to an empty or wrong declaration.
 */
export function hasNoManglingArbitraryValue(classes: string): boolean {
    return !/\[[^\]]*_[^\]]*\]/.test(classes.replace(/\[:[a-z-]+\]/g, ''));
}

export const buttonTarget =
    'flex h-8 min-h-[24px] w-8 min-w-[24px] cursor-pointer items-center justify-center rounded border border-border bg-surface text-fg disabled:cursor-default disabled:opacity-40';

export function optionRowClass(selected: boolean): string {
    return cn(
        'flex min-h-[46px] cursor-pointer items-center gap-3 rounded border px-[15px] py-0.5 text-sm',
        focusRing,
        selected ? 'border-2 border-accent bg-accent-soft font-medium' : 'border-border bg-surface',
    );
}

export function radioDotClass(selected: boolean): string {
    return cn(
        'box-border h-4 w-4 shrink-0 rounded-full',
        selected ? 'border-[5px] border-accent bg-surface' : 'border-2 border-fg-2 bg-surface',
    );
}

export function checkBoxClass(selected: boolean): string {
    return cn(
        'box-border flex h-4 w-4 shrink-0 items-center justify-center rounded-sm text-[10px] font-bold',
        selected ? 'bg-accent text-accent-fg' : 'border-2 border-fg-2 bg-surface',
    );
}

export function bigChoiceClass(selected: boolean): string {
    return cn(
        'flex min-h-[56px] cursor-pointer items-center justify-center gap-2 rounded border text-center font-display text-[15px]',
        focusRing,
        selected
            ? 'border-2 border-accent bg-accent-soft font-bold text-accent'
            : 'border-border bg-surface font-semibold',
    );
}

/** A row of an ordered or associable list, with its held and placed states. */
export function movableRowClass(state: { held?: boolean; moved?: boolean; target?: boolean }): string {
    return cn(
        'flex min-h-[46px] items-center gap-2.5 rounded border py-0.5 pl-3.5 pr-2.5 text-[13.5px]',
        state.held
            ? 'border-2 border-dashed border-accent bg-accent-soft font-medium'
            : state.target
              ? 'border-2 border-accent bg-surface'
              : state.moved
                ? 'border-2 border-accent bg-accent-soft'
                : 'border-border bg-surface',
    );
}

export function textBoxClass(invalid: boolean): string {
    return cn(
        'w-full rounded border bg-surface px-3 py-2.5 text-[13.5px] leading-[1.55] text-fg',
        invalid ? 'border-2 border-danger' : 'border-border',
    );
}

/**
 * The parallel linear list that ships with every spatial primitive. It is
 * what makes a small hotspot qualify under SC 2.5.8 Equivalent and what
 * carries SC 1.1.1 for the image, so it is never optional and never hidden
 * behind a preference.
 */
export const linearPanelClass = 'mt-3 rounded border border-border bg-bg p-3';
