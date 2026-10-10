import { useEffect, useRef, useState } from 'react';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    SessionExpired,
} from '@/shared/components';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import { useSessionExpired } from '@/shared/session';
import type { AdminScreenRuntime } from '../screens/registry';
import { AutomationRuleEditor } from './editor';
import { AutomationRuleList } from './list';
import { AutomationActionLog, AutomationRunList } from './runs';
import { automationHash, parseAutomationRoute } from './routes';
import { automationStrings as strings } from './strings';

/*
 * The automation rule builder, registered under the host screen key
 * "automations". The notification destination keeps its own key,
 * "notifications", which is what both adapters already register: two screens
 * claiming one host key would be one screen nobody can reach.
 */
export function AutomationsScreen({ runtime }: { runtime: AdminScreenRuntime }) {
    const { hash: requestedHash, replace } = runtime;
    const sessionExpired = useSessionExpired();
    const [activeHash, setActiveHash] = useState(runtime.hash);
    const [pendingHash, setPendingHash] = useState<string | null>(null);
    const route = parseAutomationRoute(activeHash);
    const query = new URLSearchParams(activeHash.split('?')[1]);
    const path = activeHash.split('?')[0];
    useEffect(() => {
        if (requestedHash === activeHash) return;
        if (hasUnsavedWorkspaceChanges()) {
            setPendingHash(requestedHash);
            replace(activeHash);
        } else setActiveHash(requestedHash);
    }, [requestedHash, replace, activeHash]);
    const listOrigin = useRef('/');
    useEffect(() => {
        if (['list', 'activity', 'templates'].includes(route.kind)) listOrigin.current = activeHash;
    }, [route.kind, activeHash]);
    const navigateDetail = (destination: string) =>
        runtime.navigate(destination === automationHash.list() ? listOrigin.current : destination);
    useEffect(() => {
        const main = document.getElementById('vy-admin-main');
        if (main) main.scrollTop = 0;
    }, [path]);

    /* An expired administrator token is neither an error nor a permission
       problem: the only useful action is to sign in again. */
    if (sessionExpired) {
        return <SessionExpired />;
    }

    const content = () => {
        switch (route.kind) {
            case 'list':
                return <AutomationRuleList navigate={runtime.navigate} hash={activeHash} />;
            case 'activity':
                return <AutomationRuleList navigate={runtime.navigate} hash={activeHash} activity />;
            case 'templates':
                return <AutomationRuleList navigate={runtime.navigate} hash={activeHash} templates />;
            case 'new':
                return (
                    <AutomationRuleEditor
                        ruleId={null}
                        navigate={navigateDetail}
                        starter={query.get('starter') ?? undefined}
                    />
                );
            case 'rule':
                return (
                    <AutomationRuleEditor
                        key={route.ruleId}
                        ruleId={route.ruleId}
                        navigate={navigateDetail}
                        panel={query.get('panel') === 'rehearse' ? 'rehearse' : 'workflow'}
                    />
                );
            case 'runs':
                return <AutomationRunList ruleId={route.ruleId} navigate={navigateDetail} />;
            case 'actions':
                return <AutomationActionLog ruleId={route.ruleId} runId={route.runId} navigate={navigateDetail} />;
            default:
                return (
                    <section role="alert" className="grid gap-2 rounded-lg border border-border bg-surface p-5">
                        <h2 className="font-display text-lg font-semibold text-fg">{strings.editor.notFoundTitle}</h2>
                        <p className="text-sm text-fg-2">{strings.editor.notFoundBody}</p>
                        <button
                            type="button"
                            className="justify-self-start cursor-pointer text-sm font-semibold text-accent underline-offset-2 hover:underline"
                            onClick={() => runtime.navigate(automationHash.list())}
                        >
                            {strings.editor.back}
                        </button>
                    </section>
                );
        }
    };
    return (
        <div className="vy-automation-workspace">
            {content()}
            <Dialog
                open={pendingHash !== null}
                onOpenChange={(open) => {
                    if (!open) setPendingHash(null);
                }}
            >
                <DialogContent className="vy-auto-dialog">
                    <DialogTitle>{strings.workspace.discardTitle}</DialogTitle>
                    <DialogDescription>{strings.workspace.discardHint}</DialogDescription>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setPendingHash(null)}>
                            {strings.workspace.keepEditing}
                        </Button>
                        <Button
                            onClick={() => {
                                if (pendingHash !== null) {
                                    setActiveHash(pendingHash);
                                    runtime.navigate(pendingHash);
                                    setPendingHash(null);
                                }
                            }}
                        >
                            {strings.workspace.discard}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export function renderAutomationsAdminScreen(runtime: AdminScreenRuntime) {
    return <AutomationsScreen runtime={runtime} />;
}
