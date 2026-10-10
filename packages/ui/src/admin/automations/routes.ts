/*
 * The screen's own hash grammar. One pure function, so the parse is a unit
 * test rather than something only a rendered tree can reach.
 *
 *   /                          the rule list
 *   /rules/new                 the editor, on an unsaved rule
 *   /rules/{id}                the editor
 *   /rules/{id}/runs           the run log
 *   /rules/{id}/runs/{runId}   one run's action log
 */
export type AutomationRoute =
    | { kind: 'list' }
    | { kind: 'activity' }
    | { kind: 'templates' }
    | { kind: 'new' }
    | { kind: 'rule'; ruleId: number }
    | { kind: 'runs'; ruleId: number }
    | { kind: 'actions'; ruleId: number; runId: number }
    | { kind: 'invalid' };

function positiveInt(raw: string | undefined): number | null {
    if (raw === undefined || !/^[1-9][0-9]*$/.test(raw)) {
        return null;
    }
    const value = Number(raw);
    return Number.isSafeInteger(value) ? value : null;
}

export function parseAutomationRoute(hash: string): AutomationRoute {
    const path = hash.split('?')[0] ?? '';
    const segments = path.split('/').filter((segment) => segment !== '');

    if (segments.length === 0) {
        return { kind: 'list' };
    }
    if (segments.length === 1 && (segments[0] === 'activity' || segments[0] === 'templates'))
        return { kind: segments[0] };
    if (segments[0] !== 'rules') {
        return { kind: 'invalid' };
    }
    if (segments.length === 2 && segments[1] === 'new') {
        return { kind: 'new' };
    }

    const ruleId = positiveInt(segments[1]);
    if (ruleId === null) {
        return { kind: 'invalid' };
    }
    if (segments.length === 2) {
        return { kind: 'rule', ruleId };
    }
    if (segments[2] !== 'runs') {
        return { kind: 'invalid' };
    }
    if (segments.length === 3) {
        return { kind: 'runs', ruleId };
    }
    if (segments.length === 4) {
        const runId = positiveInt(segments[3]);
        return runId === null ? { kind: 'invalid' } : { kind: 'actions', ruleId, runId };
    }
    return { kind: 'invalid' };
}

export const automationHash = {
    list: () => '/',
    newRule: () => '/rules/new',
    rule: (ruleId: number) => `/rules/${ruleId}`,
    runs: (ruleId: number) => `/rules/${ruleId}/runs`,
    actions: (ruleId: number, runId: number) => `/rules/${ruleId}/runs/${runId}`,
};
