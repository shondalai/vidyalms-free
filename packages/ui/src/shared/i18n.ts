/** Host-resolved messages: Joomla language overrides or WordPress gettext. */
let source: Element | null = null;
let messages: Record<string, string> = {};
/** Outside a document there is no catalogue; one shared empty value keeps localizedStrings from rebuilding on every read. */
const NO_DOCUMENT: Record<string, string> = {};

function catalogue(): Record<string, string> {
    if (typeof document === 'undefined') return NO_DOCUMENT;
    const mount = document.querySelector('[data-vy-config]');
    if (source !== mount) {
        source = mount;
        messages = {};
        try {
            const value: unknown = JSON.parse(mount?.getAttribute('data-vy-config') ?? '{}');
            if (value && typeof value === 'object' && 'messages' in value && value.messages && typeof value.messages === 'object') {
                for (const [key, text] of Object.entries(value.messages)) if (typeof text === 'string') messages[key] = text;
            }
        } catch { /* The mount validator reports invalid configuration. English remains readable. */ }
    }
    return messages;
}

export function translate(key: string, fallback: string, parameters: Record<string, unknown> = {}): string {
    const message = catalogue()[key] ?? fallback;
    return message.replace(/\{(p\d+)\}/g, (token, name: string) => Object.hasOwn(parameters, name) ? String(parameters[name]) : token);
}

/**
 * A copy table that follows the page's catalogue instead of freezing the
 * one that happened to be there when its module was evaluated.
 *
 * A table written as a plain object calls translate() once, at module scope,
 * and keeps those strings for the life of the document. That is only right
 * when the module first runs on the page it renders for. Learner SPA
 * navigation breaks the assumption for everything in the shared chunk:
 * learner-navigation.js evaluates shared on the first page, and a catalogue,
 * course or My learning page carries no data-vy-config at all, so a shared
 * table built there stayed in English after swup swapped in a player whose
 * mount carries the host's translations (2026-09-30).
 *
 * The returned object reads like the table build() returns. Its first read
 * after the catalogue changes (another mount, or the first one) builds the
 * table again; every other read returns the table already built. Take
 * nested values at the moment of use: a value copied out at module scope
 * is frozen again. check-app-entry-imports.mjs fails the build when a module
 * evaluated before the first page swap calls translate() at module scope.
 */
export function localizedStrings<T extends object>(build: () => T): T {
    let built: T | null = null;
    let builtFor: Record<string, string> | null = null;
    const current = (): T => {
        const now = catalogue();
        if (built === null || builtFor !== now) {
            built = build();
            builtFor = now;
        }
        return built;
    };
    return new Proxy({} as T, {
        get: (_target, key) => Reflect.get(current(), key),
        has: (_target, key) => Reflect.has(current(), key),
        ownKeys: () => Reflect.ownKeys(current()),
        getOwnPropertyDescriptor: (_target, key) => {
            const descriptor = Reflect.getOwnPropertyDescriptor(current(), key);
            // The empty proxy target owns nothing, so a reported property has to be configurable.
            return descriptor === undefined ? undefined : { ...descriptor, configurable: true };
        },
        set: (_target, key, value) => Reflect.set(current(), key, value),
        deleteProperty: (_target, key) => Reflect.deleteProperty(current(), key),
    });
}
