import { describe, expect, it } from 'vitest';
import { activeTab, aiHash, aiTabs, parseAiRoute } from './routes';

describe('the AI screen hash grammar', () => {
    it('lands on enablement for an empty hash', () => {
        expect(parseAiRoute('')).toEqual({ kind: 'enablement' });
        expect(parseAiRoute('/')).toEqual({ kind: 'enablement' });
    });

    it('reads each named section', () => {
        expect(parseAiRoute('/policy')).toEqual({ kind: 'policy' });
        expect(parseAiRoute('/routes')).toEqual({ kind: 'routes' });
        expect(parseAiRoute('/runs')).toEqual({ kind: 'runs' });
        expect(parseAiRoute('/drafts')).toEqual({ kind: 'drafts' });
        expect(parseAiRoute('/usage')).toEqual({ kind: 'usage' });
    });

    it('reads one run by id and refuses anything that is not one', () => {
        expect(parseAiRoute('/runs/42')).toEqual({ kind: 'run', runId: 42 });
        expect(parseAiRoute('/runs/0')).toEqual({ kind: 'invalid' });
        expect(parseAiRoute('/runs/007')).toEqual({ kind: 'invalid' });
        expect(parseAiRoute('/runs/x')).toEqual({ kind: 'invalid' });
        expect(parseAiRoute('/runs/1/2')).toEqual({ kind: 'invalid' });
    });

    it('ignores a query string, so a filter never changes the section', () => {
        expect(parseAiRoute('/runs?state=refused')).toEqual({ kind: 'runs' });
    });

    it('refuses a section it does not publish', () => {
        expect(parseAiRoute('/spend')).toEqual({ kind: 'invalid' });
    });

    it('keeps Runs current while a run detail is open', () => {
        expect(activeTab({ kind: 'run', runId: 3 })).toBe('runs');
        expect(activeTab({ kind: 'usage' })).toBe('usage');
        expect(activeTab({ kind: 'invalid' })).toBeNull();
    });

    it('builds every hash its tabs name, and every tab parses back', () => {
        for (const tab of aiTabs) {
            expect(activeTab(parseAiRoute(tab.hash))).toBe(tab.kind);
        }
        expect(aiHash.run(7)).toBe('/runs/7');
    });
});
