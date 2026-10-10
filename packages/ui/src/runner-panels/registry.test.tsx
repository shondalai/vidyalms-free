import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { RunnerPanelRegistry } from './registry';
import { installRunnerPanelBridge, runnerPanelRegistry } from './bridge';
import { RUNNER_PANEL_CONTRACT, RunnerPanelRegistrationError, isRunnerPanelSlot } from './contract';
import type { CertaintyPanelProps } from './contract';

function certaintyPanel(label: string) {
    return {
        version: RUNNER_PANEL_CONTRACT,
        Panel: (props: CertaintyPanelProps) => (
            <p>
                {label}: {props.scale.levels.length}
            </p>
        ),
    };
}

const scale = { levels: [{ value: 1, label: 'Low' }], required: false } as const;

describe('runner panel registry', () => {
    it('registers a panel for a slot the shell offers', () => {
        const registry = new RunnerPanelRegistry();
        registry.register('certainty', certaintyPanel('confidence'));

        expect(registry.has('certainty')).toBe(true);
        expect(registry.slots()).toEqual(['certainty']);
    });

    it('refuses a slot that is already taken, so two packages fail loudly rather than one winning', () => {
        const registry = new RunnerPanelRegistry();
        registry.register('certainty', certaintyPanel('first'));

        expect(() => registry.register('certainty', certaintyPanel('second'))).toThrow(
            RunnerPanelRegistrationError,
        );
    });

    it('hands the displaced module back from replace, so an override decorates', () => {
        const registry = new RunnerPanelRegistry();
        const first = certaintyPanel('first');
        registry.register('certainty', first);

        expect(registry.replace('certainty', certaintyPanel('second'))).toBe(first);
    });

    it('refuses replace on a slot nothing registered', () => {
        const registry = new RunnerPanelRegistry();

        expect(() => registry.replace('certainty', certaintyPanel('only'))).toThrow(
            RunnerPanelRegistrationError,
        );
    });

    it('refuses a slot this bundle does not offer rather than storing it unreachable', () => {
        const registry = new RunnerPanelRegistry();

        expect(() =>
            registry.register('x_acme_sidebar' as 'certainty', certaintyPanel('contributed')),
        ).toThrow(RunnerPanelRegistrationError);
    });

    it('refuses a panel from a future contract instead of letting it fail at render', () => {
        const registry = new RunnerPanelRegistry();

        expect(() =>
            registry.register('certainty', { ...certaintyPanel('future'), version: RUNNER_PANEL_CONTRACT + 1 }),
        ).toThrow(/newer than the VidyaLMS assets/);
    });

    it('refuses a panel built against another React major, because a second React breaks hooks silently', () => {
        const registry = new RunnerPanelRegistry();

        expect(() =>
            registry.register('certainty', { ...certaintyPanel('other'), reactMajor: 18 }),
        ).toThrow(/React 18/);
    });

    it('tells a subscriber about a panel that registered late', () => {
        const registry = new RunnerPanelRegistry();
        const seen: string[] = [];
        registry.subscribe((event) => seen.push(`${event.kind}:${event.slot}`));

        registry.register('certainty', certaintyPanel('late'));
        registry.replace('certainty', certaintyPanel('later'));

        expect(seen).toEqual(['register:certainty', 'replace:certainty']);
    });

    it('renders the registered panel with the props the shell hands it', () => {
        const registry = new RunnerPanelRegistry();
        registry.register('certainty', certaintyPanel('confidence'));
        const module = registry.get('certainty');
        if (module === null) {
            throw new Error('The registry answered null for a slot it was just given.');
        }
        const Panel = module.Panel;

        render(
            <Panel
                scale={scale}
                value={null}
                onChange={() => undefined}
                controlId={(local) => `q1-${local}`}
                readOnly={false}
                locale="en-GB"
                announce={() => undefined}
            />,
        );

        expect(screen.getByText('confidence: 1')).toBeInTheDocument();
    });

    it('answers null for a slot nothing registered, which is what makes the free build byte identical', () => {
        expect(new RunnerPanelRegistry().get('item_feedback')).toBeNull();
    });

    it('names the slots the shell offers', () => {
        expect(isRunnerPanelSlot('certainty')).toBe(true);
        expect(isRunnerPanelSlot('item_feedback')).toBe(true);
        expect(isRunnerPanelSlot('x_acme_sidebar')).toBe(false);
    });
});

describe('the published bridge', () => {
    it('installs one registry and hands the same one back', () => {
        const first = installRunnerPanelBridge();
        const second = runnerPanelRegistry();

        expect(second).toBe(first);
        expect(first.contract).toBe(RUNNER_PANEL_CONTRACT);
    });

    it('lives beside the question type registry on the one published global', () => {
        installRunnerPanelBridge();

        const global = window.vidyalms as Record<string, unknown> | undefined;
        expect(global?.questionTypes).toBeDefined();
        expect(global?.runnerPanels).toBe(runnerPanelRegistry());
    });
});
