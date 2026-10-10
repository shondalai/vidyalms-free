import { translate as uiText } from '@/shared/i18n';
import { useId, useRef, useState, type ChangeEvent } from 'react';
import { cn } from '../cn';
import { useAnnounce } from '../a11y';
import { sharedStrings } from '../strings';
import { formatBytes } from '../format';
import {
    checkUpload,
    uploadFile,
    UploadError,
    type UploadedFile,
    type UploadPolicy,
} from '../upload';
import { ProgressBar } from './progress-bar';

/*
 * The one file picker of the bundle: a real <input type="file"> with a real
 * <label>, so the browser's own keyboard and screen reader behaviour is
 * what the reader gets and nothing here reimplements a button that opens a
 * file dialog.
 *
 * It knows nothing about what the file is for. Where the answer goes is the
 * call site's business (a resource lesson stores the private path, a course
 * image stores the public URL), so the call site names which member its field
 * holds and gets that string first, with the whole answer beside it. A host
 * that answered without the named member is refused here rather than half
 * written into a form.
 *
 * Absent an endpoint there is no picker: the host is older than the upload
 * route, the typed path beside this control is the way in, and offering a
 * picker that cannot reach anything would be a lie. Rendering nothing is
 * the caller's job, not a disabled control here.
 */

export interface FilePickerProps {
    /** urls.upload from the mount config. */
    endpoint: string;
    /** The API headers of the mount config: the host's CSRF token or nonce. */
    headers?: Record<string, string>;
    /** The control's own label; it names the file dialog for everyone. */
    label: string;
    /** Extra sentence under the control, before the size limit. */
    hint?: string;
    policy: UploadPolicy;
    /**
     * Which member of the answer the form field holds. "path" is the private
     * storage path a resource lesson records; "url" is the public address a
     * public purpose answers with, and is null for a private one.
     */
    requires?: 'path' | 'url';
    onUploaded: (value: string, file: UploadedFile) => void;
    disabled?: boolean;
    className?: string;
}

type PickerState =
    | { kind: 'idle' }
    | { kind: 'uploading'; percent: number }
    | { kind: 'done'; name: string }
    | { kind: 'failed'; message: string };

function limitText(policy: UploadPolicy): string {
    const size = formatBytes(policy.maxBytes);
    return policy.extensions.length === 0
        ? sharedStrings.upload.limit(size)
        : sharedStrings.upload.limitTyped(size, policy.extensions.join(', '));
}

export function FilePicker({
    endpoint,
    headers,
    label,
    hint,
    policy,
    requires = 'path',
    onUploaded,
    disabled = false,
    className,
}: FilePickerProps) {
    const id = useId();
    const announce = useAnnounce();
    const [state, setState] = useState<PickerState>({ kind: 'idle' });
    /* One upload at a time per picker: a second pick abandons the first,
       whose answer would otherwise land in the form after it. */
    const runId = useRef(0);
    /* The way out of a request that hangs rather than errors. The transport
       has a timeout of its own, but two minutes is a long time to sit under
       a progress bar with no control that does anything. */
    const abortRef = useRef<AbortController | null>(null);

    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const failed = state.kind === 'failed';
    const busy = state.kind === 'uploading';

    const onPick = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        /* Picking the same file twice must fire again, so the input keeps no
           value; the pending upload holds everything that matters. */
        event.target.value = '';
        if (file === undefined) {
            return;
        }

        const refusal = checkUpload(file, policy);
        if (refusal !== null) {
            setState({ kind: 'failed', message: refusal });
            announce(refusal, 'assertive');
            return;
        }

        const run = runId.current + 1;
        runId.current = run;
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;
        setState({ kind: 'uploading', percent: 0 });

        void uploadFile({
            endpoint,
            file,
            purpose: policy.purpose,
            headers,
            signal: controller.signal,
            onProgress: (percent) => {
                if (runId.current === run) {
                    setState({ kind: 'uploading', percent });
                }
            },
        })
            .then((uploaded) => {
                if (runId.current !== run) {
                    return;
                }
                const value = requires === 'url' ? uploaded.url : uploaded.path;
                if (value === null) {
                    /* A public purpose that answered no address stored
                       something this field cannot point at; saying so beats
                       leaving the field empty under a green line. */
                    setState({ kind: 'failed', message: sharedStrings.upload.noAddress });
                    announce(sharedStrings.upload.noAddress, 'assertive');
                    return;
                }
                setState({ kind: 'done', name: uploaded.name });
                announce(sharedStrings.upload.uploaded(uploaded.name));
                onUploaded(value, uploaded);
            })
            .catch((cause: unknown) => {
                if (runId.current !== run) {
                    return;
                }
                /* The host's own sentence, verbatim: it is the only place
                   that knows why it said no. */
                const message = cause instanceof UploadError ? cause.message : sharedStrings.upload.failed;
                setState({ kind: 'failed', message });
                announce(message, 'assertive');
            });
    };

    return (
        <div className={cn('grid gap-1.5', className)}>
            <label htmlFor={id} className="font-display text-xs font-semibold text-fg">
                {label}
            </label>
            <input
                id={id}
                type="file"
                accept={policy.accept}
                disabled={disabled || busy}
                aria-invalid={failed ? true : undefined}
                aria-describedby={failed ? errorId : hintId}
                onChange={onPick}
                className={cn(
                    'w-full cursor-pointer rounded border border-border bg-surface p-1.5 text-[12.5px] text-fg-2',
                    'file:mr-2.5 file:cursor-pointer file:rounded file:border file:border-border file:bg-surface-2',
                    'file:px-2.5 file:py-1 file:font-display file:text-[11.5px] file:font-semibold file:text-fg',
                    'disabled:cursor-default disabled:opacity-60',
                    failed && 'border-danger',
                )}
            />
            {busy && (
                <div className="grid gap-1.5">
                    <ProgressBar
                        value={state.percent}
                        label={sharedStrings.upload.progress}
                        detail={sharedStrings.upload.uploading}
                        showValue
                    />
                    <button
                        type="button"
                        onClick={() => abortRef.current?.abort()}
                        className={cn(
                            'justify-self-start rounded border border-border bg-surface px-2.5 py-1',
                            'font-display text-[11.5px] font-semibold text-fg hover:bg-surface-2',
                        )}
                    >
                        {sharedStrings.upload.cancel}
                    </button>
                </div>
            )}
            {failed ? (
                <p id={errorId} role="alert" className="text-[11.5px] font-medium text-danger">
                    {state.message}
                </p>
            ) : (
                <p id={hintId} className="text-[11.5px] text-fg-2">
                    {hint === undefined ? limitText(policy) : uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: hint, p1: limitText(policy) })}
                </p>
            )}
            {state.kind === 'done' && (
                <p role="status" className="text-[11.5px] text-ok-strong">
                    {sharedStrings.upload.uploaded(state.name)}
                </p>
            )}
        </div>
    );
}
