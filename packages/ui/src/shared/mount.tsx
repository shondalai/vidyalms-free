import { Component, StrictMode, type Context, type ErrorInfo, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { createVidyaLmsClient } from '@vidyalms/api-sdk';
import { readMountConfig, type MountConfigBase, type MountConfigSchema } from './config';
import { ApiClientContext, MountElContext } from './context';
import { hostSessionProbe, SessionTracker, SessionTrackerContext, withSessionTracking } from './session';
import { createAppQueryClient } from './query';
import { Button } from './components/button';
import { ErrorState } from './components/error-state';
import { sharedStrings } from './strings';
import { observeLearnerColorScheme } from './learner-color-scheme';

interface BoundaryState {
    caught: { error: unknown } | null;
}

/**
 * Last-resort catch. Anything a screen did not handle renders as the
 * token-styled error banner with a retry that remounts the tree below.
 */
class AppErrorBoundary extends Component<{ children: ReactNode }, BoundaryState> {
    state: BoundaryState = { caught: null };

    static getDerivedStateFromError(error: unknown): BoundaryState {
        return { caught: { error } };
    }

    componentDidCatch(error: unknown, info: ErrorInfo): void {
        console.error('[vidyalms]', error, info.componentStack);
    }

    render() {
        if (this.state.caught !== null) {
            return (
                <div className="grid gap-3 p-4">
                    <ErrorState error={this.state.caught.error} title={sharedStrings.boundary.title} />
                    <div>
                        <Button variant="outline" size="sm" onClick={() => this.setState({ caught: null })}>
                            {sharedStrings.retry}
                        </Button>
                    </div>
                </div>
            );
        }
        return this.props.children;
    }
}

export interface MountAppOptions<T extends MountConfigBase> {
    /**
     * "vidyalms-player", "vidyalms-quiz-runner", "vidyalms-instructor" or
     * "vidyalms-admin".
     */
    mountId: string;
    /**
     * The app's own mount contract. Only the api, user and locale part is
     * shared, so each app validates the rest itself and nothing here has to
     * know the union of what every app needs.
     */
    schema: MountConfigSchema<T>;
    /** Where the validated config is provided; typed by the app that owns it. */
    context: Context<T | null>;
    render: (config: T) => ReactNode;
    /** Recreate this public app with the fresh CMS contract after a learner page transition. */
    learnerNavigation?: boolean;
}

function start<T extends MountConfigBase>(
    { mountId, schema, context, render, learnerNavigation }: MountAppOptions<T>,
): (() => void) | undefined {
    const el = document.getElementById(mountId);
    if (el === null) {
        /* Not an error state to render: there is nowhere to render it. */
        console.error(`[vidyalms] mount element #${mountId} not found`);
        return;
    }

    let config: T;
    try {
        config = readMountConfig(el, schema);
    } catch {
        /* readMountConfig already rendered the in-place error state. */
        return;
    }
    const MountConfigProvider = context.Provider;

    /* Every response feeds the session tracker, so an expired host token
       (401/403 after a working session) renders as session-expired instead
       of offline or not-enrolled messaging. A 403 is confirmed with the
       host first, because the core refuses a signed-in caller with 403 too. */
    const sessionTracker = new SessionTracker(config.user.id > 0, hostSessionProbe(config.api));
    const client = createVidyaLmsClient(config.api, withSessionTracking(sessionTracker));
    const queryClient = createAppQueryClient();

    const stopTheme = learnerNavigation ? observeLearnerColorScheme(el) : undefined;
    const root = createRoot(el);
    root.render(
        <StrictMode>
            <MountElContext.Provider value={el}>
                <MountConfigProvider value={config}>
                    <SessionTrackerContext.Provider value={sessionTracker}>
                        <ApiClientContext.Provider value={client}>
                            <AppErrorBoundary>
                                <QueryClientProvider client={queryClient}>{render(config)}</QueryClientProvider>
                            </AppErrorBoundary>
                        </ApiClientContext.Provider>
                    </SessionTrackerContext.Provider>
                </MountConfigProvider>
            </MountElContext.Provider>
        </StrictMode>,
    );
    return () => {
        root.unmount();
        queryClient.clear();
        stopTheme?.();
    };
}

/**
 * The one entry ritual every app shares: find the mount, validate the
 * contract with the app's own schema, build the API client, and render
 * inside the providers. Waits for DOM readiness so the adapters may inject
 * the script anywhere.
 */
export function mountApp<T extends MountConfigBase>(options: MountAppOptions<T>): void {
    let dispose: (() => void) | undefined;
    const mount = () => {
        if (options.learnerNavigation && document.querySelector('[data-vy-mount-pending]')) return;
        if (dispose || !document.getElementById(options.mountId)) return;
        dispose = start(options);
    };
    if (options.learnerNavigation) {
        window.addEventListener('vidyalms:learner:unmount', () => {
            dispose?.();
            dispose = undefined;
        });
        window.addEventListener('vidyalms:learner:mount', mount);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount, { once: true });
        return;
    }
    mount();
}
