/** Extract explicit interface copy into update-safe host catalogues. Idempotent. */
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import ts from 'typescript';
const root = path.resolve(import.meta.dirname, '..');
const output = path.resolve(root, '../core/src/I18n/ui.php');
// Rebuild/prune the catalogue from existing calls without rewriting source files.
const catalogueOnly = process.argv.includes('--catalogue-only');
const strings = new Map();
const keyFor = value => `vidyalms.ui.${createHash('sha256').update(value).digest('hex').slice(0, 16)}`;
const props = new Set(['label', 'title', 'description', 'placeholder', 'message', 'hint', 'emptyLabel', 'alt', 'aria-label', 'aria-description']);
function files(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry =>
        entry.isDirectory() ? files(path.join(dir, entry.name)) : [path.join(dir, entry.name)]);
}
for (const file of files(path.join(root, 'src')).filter(file => /\.tsx?$/.test(file) && !/\.(test|spec)\.|[\\/](testing|test-utils)[\\/.]|[\\/](i18n|fixtures)\.ts$/.test(file))) {
    const text = fs.readFileSync(file, 'utf8');
    const ast = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true, file.endsWith('x') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
    // Walk every call first: JSX attribute shortcuts in the conversion walk
    // must not hide an already-localised shared label from the catalogue.
    function collectExisting(node) {
        if (ts.isCallExpression(node) && node.expression.getText(ast) === 'uiText') {
            const [key, value] = node.arguments;
            if (key && value && ts.isStringLiteral(key) && ts.isStringLiteral(value)) strings.set(key.text, value.text);
        }
        ts.forEachChild(node, collectExisting);
    }
    collectExisting(ast);
    if (catalogueOnly) continue;
    const stringTable = /(?:^|[-\\/])strings\.ts$/.test(file);
    const edits = [];
    function inCopyObject(node) {
        let current = node.parent;
        while (current && !ts.isSourceFile(current)) {
            if (ts.isVariableDeclaration(current) && /(Strings|Labels|Messages)$/.test(current.name.getText(ast))) return true;
            current = current.parent;
        }
        return false;
    }
    function call(node, raw) {
        let value;
        const values = [];
        if (raw !== undefined) value = raw;
        else if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) value = node.text;
        else if (ts.isTemplateExpression(node)) {
            value = node.head.text;
            node.templateSpans.forEach((span, i) => {
                value += `{p${i}}${span.literal.text}`;
                values.push(`p${i}: ${span.expression.getText(ast)}`);
            });
        } else return;
        if (!/[A-Za-z]/.test(value) || /^(https?:|[.#/])/.test(value)) return;
        const key = keyFor(value);
        strings.set(key, value);
        const code = `uiText(${JSON.stringify(key)}, ${JSON.stringify(value)}${values.length ? `, { ${values.join(', ')} }` : ''})`;
        edits.push({
            start: node.getStart(ast), end: node.end,
            value: ts.isJsxText(node) || ts.isJsxAttribute(node.parent) ? `{${code}}` : code,
        });
    }
    function visible(node) {
        if (ts.isConditionalExpression(node)) {
            visible(node.whenTrue);
            visible(node.whenFalse);
        }
        else if (ts.isBinaryExpression(node)
            && [ts.SyntaxKind.AmpersandAmpersandToken, ts.SyntaxKind.QuestionQuestionToken].includes(node.operatorToken.kind)) {
            visible(node.right);
        }
        else call(node);
    }
    function walk(node) {
        if (ts.isCallExpression(node) && node.expression.getText(ast) === 'uiText') {
            const [key, value] = node.arguments;
            if (key && value && ts.isStringLiteral(key) && ts.isStringLiteral(value)) strings.set(key.text, value.text);
            return;
        }
        if (ts.isJsxText(node) && node.text.trim()) {
            const lines = node.text.split(/\r?\n/);
            const value = lines.map((line, i) =>
                (i ? line.trimStart() : line).replace(i < lines.length - 1 ? /\s+$/ : /$^/, '')).filter(Boolean).join(' ');
            // JSX whitespace indentation is not text, but intentional inline spaces are.
            call(node, value);
            return;
        }
        if (ts.isJsxAttribute(node) && props.has(node.name.getText(ast)) && node.initializer) {
            if (ts.isJsxExpression(node.initializer) && node.initializer.expression) visible(node.initializer.expression);
            else call(node.initializer);
            return;
        }
        if (ts.isJsxExpression(node) && !ts.isJsxAttribute(node.parent) && node.expression) visible(node.expression);
        if (ts.isPropertyAssignment(node) && props.has(node.name.getText(ast).replace(/^['"]|['"]$/g, ''))) visible(node.initializer);
        if ((stringTable || inCopyObject(node))
            && (ts.isStringLiteral(node) || ts.isTemplateExpression(node) || ts.isNoSubstitutionTemplateLiteral(node))) {
            if (!(ts.isPropertyAssignment(node.parent) && node.parent.name === node)
                && !ts.isImportDeclaration(node.parent) && !ts.isLiteralTypeNode(node.parent)) {
                call(node);
                return;
            }
        }
        // Literal diagnostics and validation copy; never translate protocol values, keys or comparisons.
        if ((ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node) || ts.isTemplateExpression(node))
            && (ts.isReturnStatement(node.parent) || ts.isNewExpression(node.parent)
                || (ts.isCallExpression(node.parent) && /\.(min|max|url|refine|regex)$/.test(node.parent.expression.getText(ast))))
            && /^[A-Z][a-zA-Z]+\s/.test(ts.isTemplateExpression(node) ? node.head.text : node.text)) {
            call(node);
            return;
        }
        ts.forEachChild(node, walk);
    }
    walk(ast);
    const unique = [...new Map(edits.map(edit => [edit.start, edit])).values()].sort((a,b) => b.start-a.start);
    let result = text;
    let last = Infinity;
    for (const edit of unique) {
        if (edit.end > last) throw Error(`Overlapping localization in ${file}`);
        result = result.slice(0, edit.start) + edit.value + result.slice(edit.end);
        last = edit.start;
    }
    if (unique.length) {
        if (!text.includes("import { translate as uiText }")) result = "import { translate as uiText } from '@/shared/i18n';\n" + result;
        fs.writeFileSync(file,result);
    }
}
const quote = text => `'${text.replaceAll('\\','\\\\').replaceAll("'","\\'")}'`;
fs.writeFileSync(output, `<?php\n\ndeclare(strict_types=1);\n\n// Generated by packages/ui/scripts/localize-ui.mjs. Translate in host language files/overrides.\nreturn [\n${[...strings].sort(([a],[b])=>a.localeCompare(b)).map(([key,value])=>`    ${quote(key)} => ${quote(value)},`).join('\n')}\n];\n`);
console.log(`${strings.size} React interface messages exported.`);
