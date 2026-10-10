import { useEffect, useRef, useState } from 'react';
import { Button, Dialog, DialogContent, DialogTitle, DialogDescription, DialogFooter } from '@/shared/components';
import type { AdminScreenRuntime } from '../screens/registry';
import { EmailTemplateEditor } from './editor';
import { EmailTemplateGallery } from './gallery';
import { parseEmailTemplateRoute } from './routes';
import { emailTemplateStrings as strings } from './strings';

export function EmailTemplatesScreen({ runtime }: { runtime: AdminScreenRuntime }) {
    const [activeHash, setActiveHash] = useState(runtime.hash);
    const [dirty, setDirty] = useState(false);
    const [pendingHash, setPendingHash] = useState<string | null>(null);
    const lastListHash = useRef('/');
    const route = parseEmailTemplateRoute(activeHash);
    useEffect(() => {
        if (runtime.hash === activeHash) return;
        if (dirty) {
            setPendingHash(runtime.hash);
            runtime.replace(activeHash);
        } else {
            setActiveHash(runtime.hash);
        }
    }, [runtime.hash, runtime.replace, activeHash, dirty, runtime]);
    useEffect(() => {
        if (parseEmailTemplateRoute(activeHash).kind === 'list') lastListHash.current = activeHash;
    }, [activeHash]);
    const routePath = activeHash.split('?')[0];
    useEffect(() => {
        const main = document.getElementById('vy-admin-main');
        if (main) main.scrollTop = 0;
    }, [routePath]);
    const navigate = (hash: string) => {
        const next = hash === '/' ? lastListHash.current : hash;
        if (dirty) setPendingHash(next);
        else runtime.navigate(next);
    };
    return (
        <div className="vy-email-workspace">
            {route.kind === 'list' ? (
                <EmailTemplateGallery navigate={navigate} />
            ) : route.kind === 'detail' ? (
                <EmailTemplateEditor
                    key={activeHash}
                    identity={route.identity}
                    navigate={navigate}
                    onDirtyChange={setDirty}
                />
            ) : (
                <section role="alert" className="rounded-lg border border-border bg-surface p-5">
                    <h2 className="font-display text-xl font-semibold text-fg">{strings.editor.invalidTitle}</h2>
                    <p className="mt-1.5 text-sm text-fg-2">{strings.editor.invalidBody}</p>
                    <Button className="mt-4" variant="outline" size="sm" onClick={() => navigate('/')}>
                        {strings.editor.back}
                    </Button>
                </section>
            )}
            <Dialog
                open={pendingHash !== null}
                onOpenChange={(open) => {
                    if (!open) setPendingHash(null);
                }}
            >
                <DialogContent className="vy-email-dialog">
                    <DialogTitle>{strings.editor.leaveTitle}</DialogTitle>
                    <DialogDescription>{strings.editor.leaveBody}</DialogDescription>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setPendingHash(null)}>
                            {strings.editor.stay}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                const next = pendingHash;
                                setPendingHash(null);
                                setDirty(false);
                                if (next !== null) {
                                    setActiveHash(next);
                                    runtime.navigate(next);
                                }
                            }}
                        >
                            {strings.editor.leave}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export function renderEmailTemplatesAdminScreen(runtime: AdminScreenRuntime) {
    return <EmailTemplatesScreen runtime={runtime} />;
}
