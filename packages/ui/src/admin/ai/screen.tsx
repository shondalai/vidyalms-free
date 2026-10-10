import { translate as uiText } from '@/shared/i18n';
import { useEffect, useState } from 'react';
import { Sparkles, ShieldCheck, Route, History, ClipboardCheck, ChartNoAxesCombined } from 'lucide-react';
import { Button, SessionExpired } from '@/shared/components';
import { useSessionExpired } from '@/shared/session';
import type { AdminScreenRuntime } from '../screens/registry';
import { WorkspacePage, WorkspaceTabs } from '../components/workspace-page';
import { AiEnablementPanel } from './enablement';
import { AiDraftsPanel, AiPolicyPanel, AiRoutesPanel, AiRunDetail, AiRunsPanel, AiUsagePanel } from './panels';
import { activeTab, aiHash, aiTabs, parseAiRoute, type AiTabKind } from './routes';
import { aiStrings as strings } from './strings';

const icons = {
    enablement: Sparkles,
    policy: ShieldCheck,
    routes: Route,
    runs: History,
    drafts: ClipboardCheck,
    usage: ChartNoAxesCombined,
};

export function AiScreen({ runtime }: { runtime: AdminScreenRuntime }) {
    const sessionExpired = useSessionExpired();
    const route = parseAiRoute(runtime.hash);
    const current = activeTab(route);
    const [visited, setVisited] = useState<AiTabKind[]>(current ? [current] : []);
    useEffect(() => {
        if (current) setVisited((items) => (items.includes(current) ? items : [...items, current]));
    }, [current]);
    if (sessionExpired) return <SessionExpired />;
    const panels = {
        enablement: <AiEnablementPanel />,
        policy: <AiPolicyPanel />,
        routes: <AiRoutesPanel />,
        runs: <AiRunsPanel navigate={runtime.navigate} />,
        drafts: <AiDraftsPanel />,
        usage: <AiUsagePanel />,
    };
    return (
        <WorkspacePage
            title={uiText("vidyalms.ui.7e047b6fca08683f", "AI workspace")}
            description={uiText("vidyalms.ui.7624bde0f6a3a51d", "Support your team with AI. Set clear boundaries, review the work, and understand its impact.")}
            className="vy-ai-workspace"
        >
            <div role="region" aria-label={strings.region} className="grid min-w-0 gap-6">
                <WorkspaceTabs
                    label={strings.tabs.label}
                    tabs={aiTabs.map((tab) => ({
                        key: tab.kind,
                        label: strings.tabs[tab.kind],
                        icon: icons[tab.kind],
                    }))}
                    current={current ?? ''}
                    onChange={(key) => {
                        const tab = aiTabs.find((item) => item.kind === key);
                        if (tab) runtime.navigate(tab.hash);
                    }}
                />
                {route.kind === 'invalid' ? (
                    <section role="alert" className="vy-workspace-panel">
                        <h2>{strings.notFound.title}</h2>
                        <p>{strings.notFound.body}</p>
                        <Button variant="outline" onClick={() => runtime.navigate(aiHash.enablement())}>
                            {strings.notFound.back}
                        </Button>
                    </section>
                ) : (
                    <>
                        {[...new Set([...visited, ...(current ? [current] : [])])].map((key) => (
                            <div
                                key={key}
                                role="tabpanel"
                                aria-label={strings.tabs[key]}
                                hidden={key !== current || route.kind === 'run'}
                            >
                                {panels[key]}
                            </div>
                        ))}
                        {route.kind === 'run' && <AiRunDetail runId={route.runId} navigate={runtime.navigate} />}
                    </>
                )}
            </div>
        </WorkspacePage>
    );
}

export function renderAiAdminScreen(runtime: AdminScreenRuntime) {
    return <AiScreen runtime={runtime} />;
}
