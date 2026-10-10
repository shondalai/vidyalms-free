import { useSyncExternalStore } from 'react';

function subscribe(onChange: () => void): () => void {
    window.addEventListener('online', onChange);
    window.addEventListener('offline', onChange);
    return () => {
        window.removeEventListener('online', onChange);
        window.removeEventListener('offline', onChange);
    };
}

function getSnapshot(): boolean {
    return navigator.onLine;
}

/**
 * Live connectivity, straight from the browser. The runner blocks submit
 * and buffers answers while this is false; an attempt in progress is never
 * destroyed by a dropped connection.
 */
export function useOnline(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot);
}
