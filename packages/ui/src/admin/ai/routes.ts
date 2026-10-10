/*
 * The screen's own hash grammar. One pure function, so the parse is a unit test
 * rather than something only a rendered tree can reach.
 *
 *   /                    enablement, the four levels and the stop
 *   /policy              the data-use policy versions
 *   /routes              the route table
 *   /runs                the run log
 *   /runs/{id}           one run, its attempts and its redaction manifest
 *   /drafts              the review queue
 *   /usage               the usage and oversight report
 */
export type AiRoute =
    | { kind: 'enablement' }
    | { kind: 'policy' }
    | { kind: 'routes' }
    | { kind: 'runs' }
    | { kind: 'run'; runId: number }
    | { kind: 'drafts' }
    | { kind: 'usage' }
    | { kind: 'invalid' };

function positiveInt(raw: string | undefined): number | null {
    if (raw === undefined || !/^[1-9][0-9]*$/.test(raw)) {
        return null;
    }
    const value = Number(raw);
    return Number.isSafeInteger(value) ? value : null;
}

export function parseAiRoute(hash: string): AiRoute {
    const path = hash.split('?')[0] ?? '';
    const segments = path.split('/').filter((segment) => segment !== '');

    if (segments.length === 0) {
        return { kind: 'enablement' };
    }
    if (segments.length === 1) {
        switch (segments[0]) {
            case 'policy':
                return { kind: 'policy' };
            case 'routes':
                return { kind: 'routes' };
            case 'runs':
                return { kind: 'runs' };
            case 'drafts':
                return { kind: 'drafts' };
            case 'usage':
                return { kind: 'usage' };
            default:
                return { kind: 'invalid' };
        }
    }
    if (segments.length === 2 && segments[0] === 'runs') {
        const runId = positiveInt(segments[1]);
        return runId === null ? { kind: 'invalid' } : { kind: 'run', runId };
    }
    return { kind: 'invalid' };
}

export const aiHash = {
    enablement: () => '/',
    policy: () => '/policy',
    routes: () => '/routes',
    runs: () => '/runs',
    run: (runId: number) => `/runs/${runId}`,
    drafts: () => '/drafts',
    usage: () => '/usage',
};

/** The tab order the screen draws, and the only keys its nav knows. */
export const aiTabs = [
    { kind: 'enablement', hash: aiHash.enablement() },
    { kind: 'policy', hash: aiHash.policy() },
    { kind: 'routes', hash: aiHash.routes() },
    { kind: 'runs', hash: aiHash.runs() },
    { kind: 'drafts', hash: aiHash.drafts() },
    { kind: 'usage', hash: aiHash.usage() },
] as const;

export type AiTabKind = (typeof aiTabs)[number]['kind'];

/** Which tab is highlighted for a route, so a run detail keeps Runs current. */
export function activeTab(route: AiRoute): AiTabKind | null {
    switch (route.kind) {
        case 'run':
            return 'runs';
        case 'invalid':
            return null;
        default:
            return route.kind;
    }
}
