/*
 * Test-only stand-in for XMLHttpRequest, which is what the file picker uses
 * for the one thing fetch cannot report: how much of the body has gone out.
 * happy-dom ships an XHR that would try to reach the network, so the suites
 * install this instead and drive it: the seam is the transport, exactly as
 * the SDK boundary is the seam everywhere else.
 */

export interface FakeResponse {
    status: number;
    body: string;
    contentType?: string;
    /** Percentages to report before the answer arrives. */
    progress?: number[];
    /** Ends the request the way a dropped connection or a timeout does. */
    fail?: 'error' | 'timeout';
    /** Keeps the request open until release(), so the in-flight UI can be read. */
    hold?: boolean;
}

export interface FakeCall {
    method: string;
    url: string;
    headers: Record<string, string>;
    body: FormData | null;
    /** What the transport set before sending. Zero means it set none. */
    timeout: number;
}

type Listener = (event: ProgressEvent) => void;

class Emitter {
    private readonly listeners = new Map<string, Listener[]>();

    addEventListener(type: string, listener: Listener): void {
        const existing = this.listeners.get(type) ?? [];
        existing.push(listener);
        this.listeners.set(type, existing);
    }

    emit(type: string, event: Partial<ProgressEvent> = {}): void {
        for (const listener of this.listeners.get(type) ?? []) {
            listener(event as ProgressEvent);
        }
    }
}

/**
 * The request class one server hands to the global. Built by a function so
 * the server is a parameter rather than an alias of `this`.
 */
function fakeXhrClass(server: FakeXhrServer): typeof XMLHttpRequest {
    class FakeXhr extends Emitter {
        readonly upload = new Emitter();

        responseType = '';

        status = 0;

        responseText = '';

        timeout = 0;

        private call: FakeCall = { method: '', url: '', headers: {}, body: null, timeout: 0 };

        open(method: string, url: string): void {
            this.call = { method, url, headers: {}, body: null, timeout: 0 };
        }

        setRequestHeader(name: string, value: string): void {
            this.call.headers[name.toLowerCase()] = value;
        }

        getResponseHeader(name: string): string | null {
            return name.toLowerCase() === 'content-type'
                ? (server.response.contentType ?? 'application/json')
                : null;
        }

        abort(): void {
            this.emit('abort');
        }

        send(body: FormData | null): void {
            this.call.body = body;
            this.call.timeout = this.timeout;
            server.calls.push(this.call);
            for (const percent of server.response.progress ?? []) {
                this.upload.emit('progress', { lengthComputable: true, loaded: percent, total: 100 });
            }
            const finish = () => {
                if (server.response.fail !== undefined) {
                    this.emit(server.response.fail);
                    return;
                }
                this.status = server.response.status;
                this.responseText = server.response.body;
                this.emit('load');
            };
            if (server.response.hold === true) {
                server.pendingFinish = finish;
                return;
            }
            finish();
        }
    }

    return FakeXhr as unknown as typeof XMLHttpRequest;
}

export class FakeXhrServer {
    readonly calls: FakeCall[] = [];

    /** Set by a held request; the request object reaches it through the server. */
    pendingFinish: (() => void) | null = null;

    /** What the next request answers. Public because the request class reads it. */
    response: FakeResponse = { status: 200, body: '{}' };

    private original: typeof XMLHttpRequest | undefined;

    answerWith(response: FakeResponse): void {
        this.response = response;
    }

    /** Delivers the answer of a held request. */
    release(): void {
        const finish = this.pendingFinish;
        this.pendingFinish = null;
        finish?.();
    }

    /** Installs the fake and forgets the previous test's calls and answer. */
    install(): void {
        this.calls.length = 0;
        this.response = { status: 200, body: '{}' };
        this.original = globalThis.XMLHttpRequest;
        globalThis.XMLHttpRequest = fakeXhrClass(this);
    }

    restore(): void {
        if (this.original !== undefined) {
            globalThis.XMLHttpRequest = this.original;
        }
    }
}
