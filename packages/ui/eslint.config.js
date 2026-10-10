import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';

/**
 * Line length and statement density, the ESLint counterpart of the phpcs
 * limits the PHP side has always had (lineLimit 140, absoluteLineLimit 160).
 *
 * Strings, template literals, URLs and regular expressions are exempt from the
 * count because a translated sentence cannot be wrapped without changing it,
 * and the failure this gate exists to stop is a line of packed code, not a long
 * piece of copy.
 */
const HARD_LENGTH = {
    code: 160,
    tabWidth: 4,
    ignoreUrls: true,
    ignoreRegExpLiterals: true,
    ignoreStrings: true,
    ignoreTemplateLiterals: true,
};
const SOFT_LENGTH = { ...HARD_LENGTH, code: 140 };

/**
 * The soft limit, under a second rule name.
 *
 * ESLint carries one severity per rule name, so the 140 warning and the 160
 * error cannot both be `max-len`. Configuring them as one rule is what went
 * wrong before: the soft entry was scoped to the legacy list, and because a
 * later flat-config block replaces a rule rather than adding to it, those
 * files ended up the only ones with no hard limit at all, while a newly
 * written clean file at 155 characters drew neither. The gate was off exactly
 * where the debt was.
 *
 * It follows max-len's own exemption shape: a line any string, template or
 * regular expression token touches is skipped whole, as is a line carrying a
 * URL, because a translated sentence cannot be wrapped without changing it.
 * It exempts one thing max-len does not, a quoted JSX attribute value, which
 * the TypeScript parser tokenises as JSXText: a long className is a string
 * that cannot be wrapped either, and counting them turned a 51 line advisory
 * into a 294 line one that buried the fifty three real ones. The soft limit is
 * therefore the more lenient of the two on a JSX-heavy file, and the hard
 * error is the rule that binds there.
 */
const softLength = {
    rules: {
        'max-len': {
            meta: {
                type: 'layout',
                docs: { description: 'Warns above the soft line length, beside the hard max-len error.' },
                schema: [{ type: 'object', additionalProperties: true }],
                messages: { tooLong: 'This line has a length of {{width}}. The soft limit is {{limit}}.' },
            },
            create(context) {
                const options = context.options[0] ?? {};
                const limit = options.code ?? 140;
                const tabWidth = options.tabWidth ?? 4;
                const source = context.sourceCode ?? context.getSourceCode();
                const url = /[^:/?#]:\/\/[^?#]/;

                return {
                    Program(node) {
                        const exempt = new Set();

                        for (const token of source.ast.tokens ?? []) {
                            // JSXText is where the TypeScript parser puts a
                            // quoted JSX attribute value, which is a string
                            // like any other and is exempt like any other.
                            if (!['String', 'Template', 'RegularExpression', 'JSXText'].includes(token.type)) {
                                continue;
                            }
                            for (let line = token.loc.start.line; line <= token.loc.end.line; line++) {
                                exempt.add(line);
                            }
                        }

                        source.lines.forEach((text, index) => {
                            const line = index + 1;
                            if (exempt.has(line) || url.test(text)) {
                                return;
                            }
                            const width = text.replace(/\t/g, ' '.repeat(tabWidth)).length;
                            if (width <= limit) {
                                return;
                            }
                            context.report({
                                node,
                                loc: { start: { line, column: 0 }, end: { line, column: text.length } },
                                messageId: 'tooLong',
                                data: { width: String(width), limit: String(limit) },
                            });
                        });
                    },
                };
            },
        },
    },
};

/**
 * Files written before the statement density rule existed.
 *
 * `max-statements-per-line` is the one rule these cannot satisfy without being
 * rewritten, so it alone is switched off here. **This list only ever
 * shrinks.** A new file is never added to it; a file leaves it by being
 * reformatted, which is what happened to the three organisation and operations
 * screens that used to run to 825 characters on one line.
 *
 * The hard length limit is no longer switched off with it. It used to be, and
 * that made these forty four files the only ones in the package with no line
 * length error at all.
 */
const LEGACY_DENSE_FILES = [
    'scripts/check-app-entry-imports.mjs',
    'src/admin/credentials/blueprint-gallery.tsx',
    'src/admin/credentials/page-elements.tsx',
    'src/admin/credentials/pdf-preview.test.tsx',
    'src/admin/credentials/pdf-preview.tsx',
    'src/admin/credentials/studio-drafts.tsx',
    'src/admin/credentials/studio-workflow.test.tsx',
    'src/admin/credentials/template-editor.tsx',
    'src/admin/credentials/templates-tab.tsx',
    'src/admin/credentials/templates.test.tsx',
    'src/admin/email-templates/editor.tsx',
    'src/admin/email-templates/list-state.ts',
    'src/admin/email-templates/screen.tsx',
    'src/admin/operations/api.ts',
    'src/admin/operations/screens.test.tsx',
    'src/admin/organisations/api.ts',
    'src/admin/organisations/governance.tsx',
    'src/admin/organisations/learning-api.ts',
    'src/admin/organisations/learning.tsx',
    'src/admin/organisations/mutation-refresh.test.tsx',
    'src/admin/organisations/people.tsx',
    'src/admin/organisations/pickers.tsx',
    'src/admin/organisations/relationship-erasure.test.tsx',
    'src/admin/organisations/relationship-erasure.tsx',
    'src/admin/organisations/screen.test.tsx',
    'src/admin/organisations/unit-api.ts',
    'src/admin/organisations/units.test.tsx',
    'src/admin/organisations/units.tsx',
    'src/admin/question-banks/bank-form.tsx',
    'src/admin/question-banks/folder-dialogs.tsx',
    'src/admin/question-banks/objective-picker.tsx',
    'src/admin/question-banks/owner-picker.tsx',
    'src/admin/question-banks/question-editor.tsx',
    'src/admin/question-banks/question-list.tsx',
    'src/admin/question-banks/screen.tsx',
    'src/admin/screens/certificates.tsx',
    'src/credentials/my-credentials.tsx',
    'src/credentials/organisations.test.tsx',
    'src/credentials/organisations.tsx',
    'src/instructor/app.tsx',
    'src/instructor/course-certificate.test.tsx',
    'src/instructor/tabs/course-certificate-panel.tsx',
    'src/shared/app-entry-imports.test.ts',
    'src/shared/native-css-isolation.test.ts',
];

/**
 * The files that still carry a line over the hard limit, warned at 160 rather
 * than exempt from it.
 *
 * Fifty three lines across these seventeen files exceed 160 once max-len's own
 * string and template exemptions are applied. They are reported every run and
 * they are not errors, which is the difference between a debt that is visible
 * and a rule that is off. **This list only ever shrinks**, a file leaves it by
 * having its long lines wrapped, and nothing new is added: a file not named
 * here fails the build at 161 characters.
 */
const LEGACY_LONG_LINE_FILES = [
    'src/admin/credentials/blueprint-gallery.tsx',
    'src/admin/credentials/templates-tab.tsx',
    'src/admin/email-templates/editor.tsx',
    'src/admin/organisations/api.ts',
    'src/admin/organisations/governance.tsx',
    'src/admin/organisations/learning-api.ts',
    'src/admin/organisations/learning.tsx',
    'src/admin/organisations/people.tsx',
    'src/admin/organisations/pickers.tsx',
    'src/admin/organisations/relationship-erasure.test.tsx',
    'src/admin/organisations/units.test.tsx',
    'src/admin/organisations/units.tsx',
    'src/admin/question-banks/bank-form.tsx',
    'src/admin/question-banks/question-editor.tsx',
    'src/admin/question-banks/question-list.tsx',
    'src/admin/question-banks/screen.tsx',
    'src/credentials/my-credentials.tsx',
];

export default tseslint.config(
    {
        ignores: ['dist/', 'node_modules/', 'npm-install.log'],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['src/**/*.{ts,tsx}'],
        languageOptions: {
            globals: globals.browser,
        },
        plugins: {
            'react-hooks': reactHooks,
        },
        rules: {
            ...reactHooks.configs.recommended.rules,
        },
    },
    {
        files: [
            'vite.config.ts',
            'tailwind.config.ts',
            'eslint.config.js',
            'postcss.config.js',
            'vitest.setup.ts',
            'scripts/**/*.mjs',
        ],
        languageOptions: {
            globals: globals.node,
        },
    },
    {
        files: ['src/**/*.{ts,tsx}', 'scripts/**/*.mjs'],
        plugins: {
            soft: softLength,
        },
        rules: {
            'max-len': ['error', HARD_LENGTH],
            'soft/max-len': ['warn', SOFT_LENGTH],
            'max-statements-per-line': ['error', { max: 1 }],
        },
    },
    {
        files: LEGACY_DENSE_FILES,
        rules: {
            // The one rule these files cannot satisfy without being rewritten.
            // Turning it back on is what graduating a file off the list means.
            'max-statements-per-line': 'off',
        },
    },
    {
        files: LEGACY_LONG_LINE_FILES,
        rules: {
            // Reported every run, and not fatal. Never 'off': a limit that is
            // switched off where the long lines are is not a limit.
            'max-len': ['warn', HARD_LENGTH],
        },
    },
);
