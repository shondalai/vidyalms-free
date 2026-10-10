import { useEffect } from 'react';

const CHECK_DRAFTS = 'vidyalms:check-drafts';

/** Used by page editors as well as dialogs; scoped SPA navigation asks before unmounting. */
export function useNavigationGuard(dirty: boolean): void {
    useEffect(() => {
        if (!dirty) return;
        const block = (event: Event) => event.preventDefault();
        const beforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener(CHECK_DRAFTS, block);
        window.addEventListener('beforeunload', beforeUnload);
        return () => {
            window.removeEventListener(CHECK_DRAFTS, block);
            window.removeEventListener('beforeunload', beforeUnload);
        };
    }, [dirty]);
}

export function hasUnsavedWorkspaceChanges(): boolean {
    return !window.dispatchEvent(new Event(CHECK_DRAFTS, { cancelable: true }));
}
