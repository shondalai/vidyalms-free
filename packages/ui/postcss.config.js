import postcssImport from 'postcss-import';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';

/*
 * The mount ids are the only selectors allowed to reach the host page.
 * Tailwind's `important` selector scopes every utility, but the base layer
 * still emits a few global rules (the *,::before,::after custom-property
 * defaults and ::backdrop). This plugin rewrites any selector that escaped
 * the scope so nothing in the built CSS can touch the host page; the
 * scripts/check-css-scope.mjs build gate proves it on dist output.
 */
const MOUNTS = [
    '#vidyalms-player',
    '#vidyalms-quiz-runner',
    '#vidyalms-instructor',
    '#vidyalms-admin',
    '#vidyalms-notifications',
    '#vidyalms-credentials',
];
const SCOPE = `:is(${MOUNTS.join(', ')})`;

const scopeLeftovers = {
    postcssPlugin: 'vidyalms-scope-leftovers',
    OnceExit(root) {
        root.walkRules((rule) => {
            const parent = rule.parent;
            if (parent !== undefined && parent.type === 'atrule' && /keyframes$/i.test(parent.name)) {
                return;
            }
            rule.selectors = rule.selectors.map((selector) => {
                if (MOUNTS.some((mount) => selector.includes(mount))
                    || /(^|\s)\.vidyalms(?=[:\s.[#>+~]|$)/.test(selector)) {
                    return selector;
                }
                /* "SCOPE *", "SCOPE ::backdrop" etc: the descendant
                   combinator keeps the rule inside the mounts. */
                return `${SCOPE} ${selector}`;
            });
        });
    },
};

/*
 * Native admin frameworks ship .border/.border-0 as important shorthands.
 * Selector scoping alone cannot beat those declarations. Elevate only the
 * border colour/style/width longhands in our scoped border utilities, never
 * all utilities or the preflight. Semantic tokens and hover/invalid variants
 * keep their normal ordering; inline dimensions/positions and border-radius
 * remain untouched. A bare .border gets its default colour in tokens.css.
 */
export const nativeBorderIsolation = {
    postcssPlugin: 'vidyalms-native-border-isolation',
    OnceExit(root) {
        root.walkRules((rule) => {
            if (!rule.selectors.every((selector) =>
                MOUNTS.some((mount) => selector.includes(mount))
                && /(?:\.|\\:)border(?:[-\s.:#[\]\\]|$)/.test(selector))) {
                return;
            }
            rule.walkDecls((declaration) => {
                if (/^border(?:-(?:top|right|bottom|left|inline(?:-start|-end)?|block(?:-start|-end)?))?-(?:color|style|width)$/.test(declaration.prop)) {
                    declaration.important = true;
                }
            });
        });
    },
};

export default {
    plugins: [postcssImport, tailwindcss, scopeLeftovers, nativeBorderIsolation, autoprefixer],
};
