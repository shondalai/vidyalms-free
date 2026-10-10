import { formatBytes } from './format';
import { sharedStrings } from './strings';

/*
 * Sending bytes to the host, which is deliberately not part of the API
 * contract. Bytes are a CMS concern (04-api-contract.md: "No bytes travel
 * through /v1/import. Multipart is a CMS concern"), so each adapter serves
 * its own multipart endpoint, hands the app its URL as urls.upload in the
 * mount config, and answers the one shape below. The apps never build that
 * URL and never learn anything else about the host's storage.
 *
 * Three pieces, kept apart on purpose:
 *
 * - UploadPolicy plus checkUpload: what this control offers to send. Pure,
 *   testable, and per call site, so the course image and a resource file do
 *   not share one hardcoded ceiling. It is a courtesy check that spares an
 *   obvious round trip, never an authority: the host validates again and its
 *   refusal is what the reader is shown.
 * - readUploadedFile and readRefusal: the anti-corruption boundary. Every
 *   assumption about the host's JSON lives in those two functions.
 * - uploadFile: the transport, and nothing else.
 */

/**
 * What the host answers for one stored file, which both adapters agree on:
 * {path, name, size_bytes, mime} always, and url only for a purpose that
 * stores publicly. A private file's url is null on purpose: a signed URL
 * expires in minutes and this answer goes into a draft that may not be saved
 * for an hour, so the API mints one at read time instead.
 */
export interface UploadedFile {
    /** The private storage path, which is what a stored payload records. */
    path: string;
    name: string;
    size_bytes: number;
    mime: string;
    /** The public address, or null where the purpose stores privately. */
    url: string | null;
}

/** What one picker offers to send. The host remains the authority. */
export interface UploadPolicy {
    /**
     * The registered upload purpose this control sends under. The host's
     * policy is a registry keyed on it ("resource", "image", and whatever a
     * site registered), and the endpoint refuses a purpose it does not hold,
     * so it travels with every request.
     */
    purpose: string;
    /** The largest file this control sends, in bytes. */
    maxBytes: number;
    /**
     * Lower-case extensions without the dot. Empty accepts any extension,
     * which is the honest default where the host's allowlist is the rule
     * and this app does not know it.
     */
    extensions: readonly string[];
    /** The accept attribute of the file dialog; a hint to the browser only. */
    accept?: string;
}

/**
 * Images a course page can show, matching the "image" purpose both adapters
 * register: raster only, 8 MB unless the site lowered it, stored publicly so
 * the answer carries a URL. SVG is absent for the reason the host's policy
 * gives: it is a document that executes script in the origin serving it.
 */
export const IMAGE_POLICY: UploadPolicy = {
    purpose: 'image',
    maxBytes: 8 * 1024 * 1024,
    extensions: ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    accept: 'image/jpeg,image/png,image/webp,image/gif',
};

/**
 * A file attached to a resource lesson: the "resource" purpose, private, 64
 * MB unless the site lowered it. No extension list, deliberately. Which types
 * a site accepts is the host's own allowlist and a site may extend it through
 * the upload policy event, so a copy of the free build's list here would
 * refuse files the site had chosen to take.
 */
export const RESOURCE_POLICY: UploadPolicy = {
    purpose: 'resource',
    maxBytes: 64 * 1024 * 1024,
    extensions: [],
};

/**
 * What a host says about one purpose in its mount config, from the upload
 * policy it already builds.
 */
export interface HostUploadPurpose {
    key: string;
    max_bytes: number;
    extensions: readonly string[];
}

/**
 * The policy for a purpose, taken from the host when it said anything about
 * it and from the constants above when it did not.
 *
 * The ceilings here are a copy of the free build's defaults, and a site that
 * raised either one could not use the extra capacity: the picker refused
 * before sending. A host that states its purposes is believed, because it is
 * the only thing that knows what its own settings and its own extension hooks
 * did.
 */
export function policyFor(
    fallback: UploadPolicy,
    purposes: readonly HostUploadPurpose[] | undefined,
): UploadPolicy {
    const stated = purposes?.find((purpose) => purpose.key === fallback.purpose);

    if (stated === undefined) {
        return fallback;
    }

    return {
        ...fallback,
        maxBytes: stated.max_bytes > 0 ? stated.max_bytes : fallback.maxBytes,
        // An empty list from the host means "no opinion", which is what the
        // resource purpose deliberately says.
        extensions: stated.extensions.length > 0 ? stated.extensions : fallback.extensions,
    };
}

/** A file the picker is asked to send; File satisfies it, so tests need no File. */
export interface UploadCandidate {
    name: string;
    size: number;
}

export function extensionOf(name: string): string {
    const dot = name.lastIndexOf('.');
    return dot <= 0 ? '' : name.slice(dot + 1).toLowerCase();
}

/**
 * Why this file cannot be sent, or null when it can. The message is the one
 * the reader sees, so it names the limit rather than restating the rule.
 */
export function checkUpload(file: UploadCandidate, policy: UploadPolicy): string | null {
    if (file.size <= 0) {
        return sharedStrings.upload.empty;
    }
    if (file.size > policy.maxBytes) {
        return sharedStrings.upload.tooLarge(formatBytes(policy.maxBytes));
    }
    if (policy.extensions.length > 0 && !policy.extensions.includes(extensionOf(file.name))) {
        return sharedStrings.upload.wrongType(policy.extensions.join(', '));
    }
    return null;
}

/** A refused or unreadable upload. The message is shown to the reader as is. */
export class UploadError extends Error {
    readonly status: number | null;

    constructor(message: string, status: number | null = null) {
        super(message);
        this.name = 'UploadError';
        this.status = status;
    }
}

function stringField(record: Record<string, unknown>, key: string): string | null {
    const value = record[key];
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

/**
 * The host's own words for a refusal, verbatim. RFC 9457 problems put them
 * in detail, WordPress puts them in message, a plain adapter may answer
 * text. An HTML body is never shown: a login page or a fatal error trace is
 * not a sentence anybody can act on, so the status line stands in for it.
 */
export function readRefusal(status: number, body: string, contentType: string): string {
    const text = body.trim();
    if (contentType.includes('json')) {
        try {
            const parsed: unknown = JSON.parse(text);
            if (typeof parsed === 'object' && parsed !== null) {
                const record = parsed as Record<string, unknown>;
                const message =
                    stringField(record, 'detail') ??
                    stringField(record, 'message') ??
                    stringField(record, 'error') ??
                    stringField(record, 'title');
                if (message !== null) {
                    return message;
                }
            }
        } catch {
            /* fall through to the status sentence */
        }
    } else if (text !== '' && !text.startsWith('<') && text.length <= 300) {
        return text;
    }
    return sharedStrings.upload.refused(status);
}

/**
 * The host's answer read into UploadedFile. Anything that is not the
 * documented shape is a malformed answer, not a half-filled form: a picker
 * that wrote an empty path into a lesson would lose the file silently.
 */
export function readUploadedFile(payload: unknown): UploadedFile {
    if (typeof payload !== 'object' || payload === null) {
        throw new UploadError(sharedStrings.upload.malformed);
    }
    const record = payload as Record<string, unknown>;
    const path = stringField(record, 'path');
    const name = stringField(record, 'name');
    if (path === null || name === null) {
        throw new UploadError(sharedStrings.upload.malformed);
    }
    const size = record.size_bytes;
    const mime = record.mime;
    return {
        path,
        /* Null for a purpose that stores privately, which is the documented
           answer, not a missing field. */
        url: stringField(record, 'url'),
        name,
        size_bytes: typeof size === 'number' && Number.isFinite(size) ? size : 0,
        mime: typeof mime === 'string' ? mime : '',
    };
}

export interface UploadRequest {
    /** urls.upload of the mount config, exactly as the host wrote it. */
    endpoint: string;
    file: File;
    /** The API headers of the mount config: the host's CSRF token or nonce. */
    headers?: Record<string, string>;
    /** The registered purpose, which the endpoint validates before storing. */
    purpose: string;
    /** Required for org_import; checked against this organisation's people permission. */
    organisationId?: number;
    /** The multipart field name; the adapters read "file". */
    fieldName?: string;
    onProgress?: (percent: number) => void;
    signal?: AbortSignal;
    /**
     * How long the request may stall before it is given up on. A request
     * that hangs rather than errors (a proxy holding the body, a server that
     * accepts and never answers) has no event of its own, so without this
     * the picker sits disabled under a progress bar with no way back except
     * reloading the page and losing the draft around it.
     */
    timeoutMs?: number;
}

/** Long enough for a large file on a slow line, short enough to be a way out. */
export const UPLOAD_TIMEOUT_MS = 120_000;

/**
 * One multipart POST to the host's endpoint.
 *
 * XMLHttpRequest rather than fetch, for the one thing fetch still cannot do:
 * report how much of the body has gone out. A picker with no progress on a
 * 40 MB file is a frozen dialog.
 *
 * Credentials are exactly what the SDK client sends (credentials:
 * "same-origin"): withCredentials is left false, which for a same-origin
 * request still carries the session cookie and for a cross-origin one
 * carries nothing. The host's own auth header rides along beside it.
 * Content-Type is never set here, because only the browser knows the
 * multipart boundary it generated.
 */
export function uploadFile({
    endpoint,
    file,
    purpose,
    organisationId,
    headers = {},
    fieldName = 'file',
    onProgress,
    signal,
    timeoutMs = UPLOAD_TIMEOUT_MS,
}: UploadRequest): Promise<UploadedFile> {
    return new Promise<UploadedFile>((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const body = new FormData();
        body.append(fieldName, file, file.name);
        body.append('purpose', purpose);
        if (organisationId !== undefined) body.append('organisation_id', String(organisationId));

        const abort = () => xhr.abort();
        const done = () => signal?.removeEventListener('abort', abort);

        xhr.open('POST', endpoint, true);
        for (const [name, value] of Object.entries(headers)) {
            if (name.toLowerCase() !== 'content-type') {
                xhr.setRequestHeader(name, value);
            }
        }
        xhr.responseType = 'text';
        // The timeout listener below can only fire because this is set. It
        // was registered without it, so a stalled request had no ending at
        // all.
        xhr.timeout = Math.max(0, timeoutMs);

        if (onProgress !== undefined) {
            xhr.upload.addEventListener('progress', (event: ProgressEvent) => {
                if (event.lengthComputable && event.total > 0) {
                    onProgress(Math.min(100, Math.round((event.loaded / event.total) * 100)));
                }
            });
        }

        xhr.addEventListener('load', () => {
            done();
            const contentType = xhr.getResponseHeader('content-type') ?? '';
            const text = typeof xhr.responseText === 'string' ? xhr.responseText : '';
            if (xhr.status < 200 || xhr.status >= 300) {
                reject(new UploadError(readRefusal(xhr.status, text, contentType), xhr.status));
                return;
            }
            try {
                resolve(readUploadedFile(JSON.parse(text === '' ? 'null' : text)));
            } catch (cause) {
                reject(cause instanceof UploadError ? cause : new UploadError(sharedStrings.upload.malformed));
            }
        });
        xhr.addEventListener('error', () => {
            done();
            reject(new UploadError(sharedStrings.upload.failed));
        });
        xhr.addEventListener('timeout', () => {
            done();
            reject(new UploadError(sharedStrings.upload.failed));
        });
        xhr.addEventListener('abort', () => {
            done();
            reject(new UploadError(sharedStrings.upload.cancelled));
        });

        if (signal !== undefined) {
            if (signal.aborted) {
                reject(new UploadError(sharedStrings.upload.cancelled));
                return;
            }
            signal.addEventListener('abort', abort, { once: true });
        }

        xhr.send(body);
    });
}
