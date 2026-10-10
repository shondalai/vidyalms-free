import { describe, expect, it } from 'vitest';
import { automationHash, parseAutomationRoute } from './routes';

describe('the automations hash grammar', () => {
    it('supports the activity and starter workflow destinations', () => {
        expect(parseAutomationRoute('/activity?state=enabled')).toEqual({ kind: 'activity' });
        expect(parseAutomationRoute('/templates')).toEqual({ kind: 'templates' });
    });
    it('reads the list at the root, with or without a query', () => {
        expect(parseAutomationRoute('')).toEqual({ kind: 'list' });
        expect(parseAutomationRoute('/')).toEqual({ kind: 'list' });
        expect(parseAutomationRoute('/?page=3')).toEqual({ kind: 'list' });
    });

    it('reads the new-rule editor', () => {
        expect(parseAutomationRoute('/rules/new')).toEqual({ kind: 'new' });
        expect(automationHash.newRule()).toBe('/rules/new');
    });

    it('reads one rule, its runs and one run action log', () => {
        expect(parseAutomationRoute('/rules/12')).toEqual({ kind: 'rule', ruleId: 12 });
        expect(parseAutomationRoute('/rules/12/runs')).toEqual({ kind: 'runs', ruleId: 12 });
        expect(parseAutomationRoute('/rules/12/runs/44')).toEqual({
            kind: 'actions',
            ruleId: 12,
            runId: 44,
        });
    });

    it('round-trips every hash the screen builds', () => {
        expect(parseAutomationRoute(automationHash.rule(7))).toEqual({ kind: 'rule', ruleId: 7 });
        expect(parseAutomationRoute(automationHash.runs(7))).toEqual({ kind: 'runs', ruleId: 7 });
        expect(parseAutomationRoute(automationHash.actions(7, 9))).toEqual({
            kind: 'actions',
            ruleId: 7,
            runId: 9,
        });
        expect(parseAutomationRoute(automationHash.list())).toEqual({ kind: 'list' });
    });

    it('refuses an id that is not a positive integer rather than coercing it', () => {
        for (const hash of ['/rules/0', '/rules/-1', '/rules/abc', '/rules/1.5', '/rules/12/runs/0']) {
            expect(parseAutomationRoute(hash)).toEqual({ kind: 'invalid' });
        }
    });

    it('refuses a shape it does not serve', () => {
        for (const hash of ['/queue', '/rules/12/queue', '/rules/12/runs/44/steps']) {
            expect(parseAutomationRoute(hash)).toEqual({ kind: 'invalid' });
        }
    });
});
