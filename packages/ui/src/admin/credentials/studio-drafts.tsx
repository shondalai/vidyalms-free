import { useNavigationGuard } from '@/shared/navigation-guard';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Button, Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/shared/components';
import { certificateStudioStrings as strings } from './studio-strings';

interface DraftContextValue {
    dirty: boolean;
    setDirty: (key: string, dirty: boolean) => void;
    requestLeave: (action: () => void) => void;
}

const DraftContext = createContext<DraftContextValue>({
    dirty: false,
    setDirty: () => undefined,
    requestLeave: (action) => action(),
});

/** A route stays mounted until the author explicitly resolves unsaved forms. */
export function StudioDraftProvider({ children, onDirtyChange, labels }: {
    children: ReactNode;
    onDirtyChange?: (dirty: boolean) => void;
    labels?: { title: string; body: string; stay: string; leave: string };
}) {
    const [dirtyForms, setDirtyForms] = useState<ReadonlySet<string>>(() => new Set());
    const [asking, setAsking] = useState(false);
    const pending = useRef<(() => void) | null>(null);
    const dirty = dirtyForms.size > 0;
    useNavigationGuard(dirty);
    const setDirty = useCallback((key: string, value: boolean) => {
        setDirtyForms((previous) => {
            if (previous.has(key) === value) return previous;
            const next = new Set(previous);
            if (value) next.add(key); else next.delete(key);
            return next;
        });
    }, []);
    const requestLeave = useCallback((action: () => void) => {
        if (!dirty) { action(); return; }
        pending.current = action;
        setAsking(true);
    }, [dirty]);
    useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);
    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);
    const context = useMemo(() => ({ dirty, setDirty, requestLeave }), [dirty, setDirty, requestLeave]);
    return <DraftContext.Provider value={context}>
        {children}
        <Dialog open={asking} onOpenChange={(open) => { setAsking(open); if (!open) pending.current = null; }}>
            <DialogContent>
                <DialogTitle>{labels?.title ?? strings.leaveTitle}</DialogTitle>
                <DialogDescription>{labels?.body ?? strings.leaveBody}</DialogDescription>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => { pending.current = null; setAsking(false); }}>{labels?.stay ?? strings.stay}</Button>
                    <Button variant="destructive" onClick={() => {
                        const action = pending.current;
                        pending.current = null;
                        setAsking(false);
                        // Do not mark forms clean here: reload may fail. Their
                        // successful save or actual unmount owns that transition.
                        action?.();
                    }}>{labels?.leave ?? strings.leave}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </DraftContext.Provider>;
}

export function useStudioDraft(key: string) {
    const { setDirty } = useContext(DraftContext);
    useEffect(() => () => setDirty(key, false), [key, setDirty]);
    return useCallback((dirty: boolean) => setDirty(key, dirty), [key, setDirty]);
}

export function useStudioNavigation() { return useContext(DraftContext); }
