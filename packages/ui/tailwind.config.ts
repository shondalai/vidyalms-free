import type { Config } from 'tailwindcss';

/**
 * The semantic layer over the --vy-* design tokens (see src/shared/tokens.css).
 * Utilities never carry a raw hex that has a token; they reference the
 * internal indirection (--_vy-*) which resolves host overrides first and the
 * bundled defaults second.
 *
 * `important` is a selector, not a boolean: every utility is emitted as
 * `:is(#vidyalms-player, #vidyalms-quiz-runner, #vidyalms-instructor, #vidyalms-admin)
 * .utility`, which outranks the host page's component classes without a
 * single !important, and cannot style anything outside the mounts. Preflight
 * is disabled; the scoped reset in tokens.css replaces it inside the mounts
 * only.
 *
 * Note: because the scope uses a descendant combinator, utilities do not
 * apply to the mount element itself. The mount element is styled by
 * tokens.css; app roots are children of the mount, where utilities work.
 */
/**
 * The class surface a renderer written outside this package may use.
 *
 * Without it the free stylesheet is an undeclared contract: it happens to
 * carry whatever packages/ui/src happens to reference, so a Pro or
 * third-party renderer composing the same primitives is styled by accident
 * and breaks silently the day a free component stops using a utility. Adding
 * an external package to `content` is not the answer either, because it would
 * make the free CSS depend on whether Pro is installed, which the edition
 * rule forbids: the free bytes are identical with or without Pro.
 *
 * So the surface is declared here instead. It is the vocabulary the eight
 * primitives and the shell already use, stated once so it stays emitted
 * whatever the free components do next.
 */
const RENDERER_SAFELIST = [
    /* Layout and rhythm of an item body. */
    'flex',
    'grid',
    'block',
    'inline-flex',
    'hidden',
    'items-center',
    'items-start',
    'justify-between',
    'flex-wrap',
    'flex-col',
    'w-full',
    'max-w-[62ch]',
    /* The renderer spacing scale. */
    ...['0.5', '1', '1.5', '2', '2.5', '3', '3.5', '4', '5'].flatMap((step) => [
        `gap-${step}`,
        `mb-${step}`,
        `mt-${step}`,
        `p-${step}`,
        `px-${step}`,
        `py-${step}`,
    ]),
    /* Surfaces, borders and the four semantic states. */
    'rounded',
    'border',
    'border-border',
    'border-l-[3px]',
    'bg-bg',
    'bg-surface',
    'bg-surface-2',
    'text-fg',
    'text-fg-2',
    'border-l-accent',
    'border-l-ok',
    'border-l-warn',
    'border-l-danger',
    'text-danger',
    'text-warn',
    'text-ok',
    /* Type scale a renderer states its prompt, kicker and help text in. */
    'font-display',
    'font-semibold',
    'text-sm',
    'text-[11px]',
    'text-[12px]',
    'text-[13px]',
    'text-[17px]',
    'leading-[1.45]',
    /* The two-tone stroke over author media: a halo in the opposite tone
       under the outline, so one of the two always reads whatever the
       backdrop is (SC 1.4.11). */
    'ring-2',
    'ring-surface',
    'border-2',
    'border-dashed',
    'border-fg',
    /* Focus and disabled affordances the primitives rely on. */
    'focus-visible:outline',
    'focus-visible:outline-2',
    'focus-visible:outline-offset-2',
    'disabled:opacity-60',
    'cursor-pointer',
    'sr-only',
    /*
     * Positioning, for a renderer that places things on a surface rather
     * than in a flow: the certificate page designer draws elements at
     * millimetre coordinates over a page, and a third-party canvas of any
     * kind needs the same handful. Every one of these is already emitted
     * because a free component happens to use it, which is exactly the
     * accident this list exists to stop being an accident.
     */
    'relative',
    'absolute',
    'inset-0',
    'overflow-hidden',
    'pointer-events-none',
    'whitespace-nowrap',
    'text-center',
    'ml-auto',
    'border-accent',
    'bg-accent-soft',
    'text-[11.5px]',
    'hover:text-fg',
];

export default {
    content: ['./src/**/*.{ts,tsx}'],
    safelist: RENDERER_SAFELIST,
    important:
        ':is(#vidyalms-player, #vidyalms-quiz-runner, #vidyalms-instructor, #vidyalms-admin,'
        + ' #vidyalms-notifications, #vidyalms-credentials)',
    corePlugins: {
        preflight: false,
        /* .container is emitted outside the important scope and would leak
           into the host page; the apps never use it. */
        container: false,
    },
    theme: {
        colors: {
            transparent: 'transparent',
            current: 'currentColor',
            inherit: 'inherit',
            bg: 'var(--_vy-bg)',
            surface: {
                DEFAULT: 'var(--_vy-surface)',
                2: 'var(--_vy-surface-2)',
            },
            fg: {
                DEFAULT: 'var(--_vy-fg)',
                2: 'var(--_vy-fg-2)',
            },
            border: 'var(--_vy-border)',
            accent: {
                DEFAULT: 'var(--_vy-accent)',
                fg: 'var(--_vy-accent-fg)',
                soft: 'var(--_vy-accent-soft)',
            },
            ok: {
                DEFAULT: 'var(--_vy-ok)',
                soft: 'color-mix(in srgb, var(--_vy-ok) 10%, transparent)',
                strong: 'var(--_vy-ok-strong)',
            },
            warn: {
                DEFAULT: 'var(--_vy-warn)',
                soft: 'color-mix(in srgb, var(--_vy-warn) 12%, transparent)',
                strong: 'var(--_vy-warn-strong)',
            },
            danger: {
                DEFAULT: 'var(--_vy-danger)',
                soft: 'color-mix(in srgb, var(--_vy-danger) 10%, transparent)',
                strong: 'var(--_vy-danger-strong)',
            },
            scrim: 'color-mix(in srgb, var(--_vy-fg) 45%, transparent)',
        },
        fontFamily: {
            body: 'var(--_vy-font-body)',
            display: 'var(--_vy-font-display)',
            mono: 'var(--_vy-font-mono)',
        },
        borderRadius: {
            none: '0',
            sm: '4px',
            DEFAULT: 'var(--_vy-radius)',
            lg: 'var(--_vy-radius-lg)',
            btn: 'var(--_vy-btn-radius)',
            full: '9999px',
        },
        extend: {
            borderColor: {
                DEFAULT: 'var(--_vy-border)',
            },
            transitionDuration: {
                '1': 'var(--_vy-dur-1)',
                '2': 'var(--_vy-dur-2)',
            },
        },
    },
} satisfies Config;
