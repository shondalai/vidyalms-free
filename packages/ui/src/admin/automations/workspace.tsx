import { ArrowDown, Check, Clock3, Send, Sparkles, Workflow, Zap } from 'lucide-react';
import type { AutomationCatalogue } from './api';
import { readSentence, placeholderValues } from './sentences';
import { automationStrings as s } from './strings';

export function readable(value: string): string {
    const raw = value.startsWith('vidyalms.') ? (value.split('.').at(-1) ?? value) : value;
    return raw.replace(/[_-]/g, ' ').replace(/^./, (letter) => letter.toUpperCase());
}
export function providerLabel(provider: { key: string; label: string } | undefined, fallback = '') {
    return readable(provider?.label || fallback || provider?.key || '');
}
export function conditionSummary(value: unknown, catalogue?: AutomationCatalogue): string {
    if (!value || typeof value !== 'object') return s.workspace.anyConditions;
    const node = value as { type?: string; payload?: Record<string, unknown>; op?: string; c?: unknown[] };
    if (node.type) {
        const known = catalogue?.conditions.find((condition) => condition.key === node.type);
        return readable(
            readSentence(
                known?.sentence,
                providerLabel(known, node.type),
                placeholderValues(known?.sentence, node.payload),
            ),
        );
    }
    if (!node.c?.length) return s.workspace.anyConditions;
    const parts = node.c.map((child) => conditionSummary(child, catalogue));
    if (parts.length === 1 && node.op === 'and') return parts[0]!;
    const operator = s.workspace.operators[node.op as keyof typeof s.workspace.operators] ?? node.op;
    return `${operator}: ${parts.join('; ')}`;
}
export function RuleFlow({
    rule,
    catalogue,
}: {
    rule: {
        trigger_key: string;
        conditions?: unknown;
        actions: { action_key: string; params?: Record<string, unknown> | null }[];
    };
    catalogue?: AutomationCatalogue;
}) {
    const trigger = catalogue?.triggers.find((entry) => entry.key === rule.trigger_key);
    const lines = [
        {
            label: s.workspace.when,
            text: providerLabel(trigger, rule.trigger_key) || s.editor.triggerPick,
            Icon: Zap,
            tone: 'violet',
        },
        { label: s.workspace.if, text: conditionSummary(rule.conditions, catalogue), Icon: Check, tone: 'blue' },
        {
            label: s.workspace.then,
            text: rule.actions.length
                ? rule.actions
                      .map((action) => {
                          const descriptor = catalogue?.actions.find((entry) => entry.key === action.action_key);
                          return readable(
                              readSentence(
                                  descriptor?.sentence,
                                  providerLabel(descriptor, action.action_key),
                                  placeholderValues(descriptor?.sentence, action.params),
                              ),
                          );
                      })
                      .join(' → ')
                : s.editor.actionPick,
            Icon: Send,
            tone: 'peach',
        },
    ];
    return (
        <div className="vy-auto-flow">
            {lines.map(({ label, text, Icon, tone }, i) => (
                <div key={label}>
                    {i > 0 && <ArrowDown className="vy-auto-flow-arrow" size={13} aria-hidden="true" />}
                    <div className="vy-auto-flow-step">
                        <span className={`vy-auto-icon ${tone}`}>
                            <Icon size={16} />
                        </span>
                        <div>
                            <small>{label}</small>
                            <p>{text}</p>
                        </div>
                    </div>
                </div>
            ))}
        </div>
    );
}
export const starters = [
    {
        key: 'welcome',
        title: s.workspace.welcomeTitle,
        description: s.workspace.welcomeDescription,
        trigger: 'enrolled',
        Icon: Workflow,
        tone: 'peach',
    },
    {
        key: 'completion',
        title: s.workspace.completionTitle,
        description: s.workspace.completionDescription,
        trigger: 'course_completed',
        Icon: Sparkles,
        tone: 'violet',
    },
    {
        key: 'expiry',
        title: s.workspace.expiryTitle,
        description: s.workspace.expiryDescription,
        trigger: 'entitlement_expiring',
        Icon: Clock3,
        tone: 'blue',
    },
] as const;
export function starterFor(key: string | undefined, catalogue: AutomationCatalogue) {
    const starter = starters.find((item) => item.key === key);
    const trigger = starter && catalogue.triggers.find((item) => item.key.split('.').at(-1) === starter.trigger);
    const action = catalogue.actions.find((item) => item.key === 'notify' || item.key.split('.').at(-1) === 'notify');
    return starter && trigger && action ? { ...starter, triggerKey: trigger.key, actionKey: action.key } : null;
}
export function StarterLibrary({
    catalogue,
    navigate,
}: {
    catalogue?: AutomationCatalogue;
    navigate: (hash: string) => void;
}) {
    return (
        <section className="vy-auto-starters">
            <div className="vy-auto-section-heading">
                <div>
                    <h2>{s.workspace.starters}</h2>
                    <p>{s.workspace.starterHint}</p>
                </div>
                <Sparkles size={20} />
            </div>
            <div className="vy-auto-grid">
                {starters.map(({ key, title, description, Icon, tone }) => (
                    <button
                        key={key}
                        className="vy-auto-starter"
                        disabled={!catalogue || !starterFor(key, catalogue)}
                        onClick={() => navigate(`/rules/new?starter=${key}`)}
                    >
                        <span className={`vy-auto-icon ${tone}`}>
                            <Icon size={20} />
                        </span>
                        <h3>{title}</h3>
                        <p>{description}</p>
                        <span className="vy-auto-text-link">{s.workspace.useStarter} →</span>
                    </button>
                ))}
            </div>
        </section>
    );
}
