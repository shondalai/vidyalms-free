import { useRef } from 'react';
import { X } from 'lucide-react';
import { Icon } from '@/shared/components';
import { primitiveStrings as strings } from '../strings';
import { InlineError, describedBy, type PrimitiveProps } from './common';
import { buttonTarget } from './styles';

/*
 * ArtifactCapture: the client counterpart of the artifact primitive, used by
 * file response, drawing response and recorded response for the part of
 * their interaction that is "a thing the learner produced is now attached".
 *
 * The control is a native file input behind a real button label, so the
 * accessible name, the keyboard operation and the platform picker are the
 * browser's. Nothing autoplays, nothing captures the microphone or camera
 * without an explicit activation, and the attached list is text, so a
 * screen reader user can tell what is attached without opening anything.
 */

export interface CapturedArtifact {
    id: string;
    name: string;
    sizeBytes?: number;
    mime?: string;
}

export interface ArtifactCaptureProps extends PrimitiveProps {
    value: readonly CapturedArtifact[];
    onChange: (artifacts: CapturedArtifact[]) => void;
    /** Handed the picked files; the caller uploads and returns descriptors. */
    onPick?: (files: readonly File[]) => void;
    accept?: string;
    multiple?: boolean;
    addLabel?: string;
    hint?: string;
    /**
     * The visible label the file input itself carries.
     *
     * An input of type file maps to no ARIA role, so a role-driven
     * accessible-name sweep never covered it and one caller shipped without
     * a name at all: the group around it was named and the control inside it
     * was not, and an id is not a name (SC 4.1.2, SC 3.3.2). Two other
     * callers each wrote their own label element beside this primitive, with
     * a comment saying so. The primitive writes it now, so every caller
     * inherits it and there is one place to audit.
     *
     * It defaults to the group's own label, which is what a caller naming
     * the group already meant.
     */
    fieldLabel?: string;
}

function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }
    return `${Math.round((bytes / (1024 * 1024)) * 10) / 10} MB`;
}

export function ArtifactCapture({
    value,
    onChange,
    onPick,
    accept,
    multiple = true,
    addLabel,
    hint,
    fieldLabel,
    controlId,
    labelledBy,
    label,
    announce,
    readOnly = false,
    error,
    className,
}: ArtifactCaptureProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const fieldId = controlId('field');
    const errorId = controlId('error');
    const hintId = controlId('hint');
    const fieldName = fieldLabel ?? label;

    return (
        <div
            className={className}
            role="group"
            aria-labelledby={labelledBy}
            aria-label={labelledBy === undefined ? label : undefined}
        >
            {hint !== undefined && (
                <p id={hintId} className="mb-2 text-[12px] text-fg-2">
                    {hint}
                </p>
            )}
            {fieldName !== undefined && (
                <label
                    htmlFor={fieldId}
                    className="mb-1 block font-display text-[12px] font-semibold text-fg"
                >
                    {fieldName}
                </label>
            )}
            <input
                ref={inputRef}
                id={fieldId}
                type="file"
                accept={accept}
                multiple={multiple}
                disabled={readOnly}
                aria-describedby={describedBy(hint !== undefined && hintId, error !== undefined && errorId)}
                aria-invalid={error !== undefined ? true : undefined}
                onChange={(event) => {
                    const files = [...(event.target.files ?? [])];
                    if (files.length === 0) {
                        return;
                    }
                    onPick?.(files);
                    for (const file of files) {
                        announce('status', strings.fileAdded(file.name));
                    }
                    event.target.value = '';
                }}
                className="block min-h-[44px] w-full max-w-[420px] cursor-pointer rounded border border-border bg-surface px-3 py-2 text-[13px] text-fg"
            />
            {addLabel !== undefined && (
                <p className="mt-1 text-[11.5px] text-fg-2">{addLabel}</p>
            )}
            <ul className="mt-2.5 grid max-w-[520px] gap-1.5">
                {value.length === 0 ? (
                    <li className="text-[12.5px] text-fg-2">{strings.noFiles}</li>
                ) : (
                    value.map((artifact) => (
                        <li
                            key={artifact.id}
                            className="flex min-h-[44px] items-center gap-2.5 rounded border border-border bg-surface px-3 text-[13px]"
                        >
                            <span className="min-w-0 flex-1 truncate">{artifact.name}</span>
                            {artifact.sizeBytes !== undefined && (
                                <span className="shrink-0 text-[11.5px] text-fg-2">
                                    {formatSize(artifact.sizeBytes)}
                                </span>
                            )}
                            <button
                                type="button"
                                aria-label={strings.removeFile(artifact.name)}
                                disabled={readOnly}
                                onClick={() => {
                                    onChange(value.filter((a) => a.id !== artifact.id));
                                    announce('status', strings.fileRemoved(artifact.name));
                                }}
                                className={buttonTarget}
                            >
                                <Icon icon={X} size={14} />
                            </button>
                        </li>
                    ))
                )}
            </ul>
            <InlineError id={errorId} message={error} />
        </div>
    );
}
