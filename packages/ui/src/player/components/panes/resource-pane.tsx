import { readPresentation } from '@/shared/lesson-presentation';
import { Download } from 'lucide-react';
import { EmptyState, Icon, buttonVariants } from '@/shared/components';
import type { LessonView } from '@/shared/api';
import { playerStrings } from '../../strings';
import { fileExt, formatBytes } from '../../lib';
import { presentationStrings as copy } from '../../presentation-strings';

/*
 * Resource lesson (artboard 2b): one card per file with the extension
 * chip, name, size and a Download action; downloading marks the lesson
 * complete, exactly as the artboard's meta line says ("completes when you
 * download the file"): the heartbeat carries the claim, the server
 * confirms. The downloaded strip renders once completion is real, and the
 * files stay available. File entries are the contract's {name, size_bytes,
 * url} shape, where url is a signed, expiring URL; the raw private storage
 * path never appears in a response. Without access there is no url and the
 * row says so instead of linking nowhere.
 */
interface ResourceFile {
    name: string;
    sizeBytes: number | null;
    url: string | null;
}

export interface ResourcePaneProps {
    lesson: LessonView;
    completed: boolean;
    /** A download happened: claim completion and flush a beat. */
    onDownloaded: () => void;
}

function readFiles(lesson: LessonView): ResourceFile[] {
    const raw = lesson.content?.files;
    if (!Array.isArray(raw)) {
        return [];
    }
    const files: ResourceFile[] = [];
    for (const entry of raw) {
        if (typeof entry !== 'object' || entry === null) {
            continue;
        }
        const record = entry as Record<string, unknown>;
        if (typeof record.name !== 'string') {
            continue;
        }
        files.push({
            name: record.name,
            sizeBytes: typeof record.size_bytes === 'number' ? record.size_bytes : null,
            url: typeof record.url === 'string' ? record.url : null,
        });
    }
    return files;
}

export function ResourcePane({ lesson, completed, onDownloaded }: ResourcePaneProps) {
    const files = readFiles(lesson);
    const presentation = readPresentation(lesson.content);
    if (files.length === 0) {
        return (
            <EmptyState
                title={playerStrings.resource.noFiles.title}
                description={playerStrings.resource.noFiles.body}
            />
        );
    }
    return (
        <div className="vy-resource-panel grid gap-3">
            <div className="vy-activity-heading"><span className="vy-study-eyebrow">{copy.resources}</span><h4>{copy.resourcesTitle}</h4></div>
            {lesson.summary !== null && lesson.summary !== '' && (
                <p className="max-w-[62ch] text-sm leading-relaxed text-fg">{lesson.summary}</p>
            )}
            {presentation.introduction && (
                <p className="whitespace-pre-wrap text-sm leading-relaxed">{presentation.introduction}</p>
            )}
            <ul className="grid gap-2.5">
                {files.map((file, index) => {
                    const size = presentation.show_file_size ? formatBytes(file.sizeBytes) : null;
                    return (
                        <li
                            key={`${file.name}-${index}`}
                            className="flex flex-wrap items-center gap-3.5 rounded-lg border border-border p-4"
                        >
                            <span
                                aria-hidden="true"
                                className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded bg-surface-2 font-mono text-[10px] font-bold text-fg-2"
                            >
                                {fileExt(file.name)}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[13.5px] font-semibold text-fg">
                                    {file.name}
                                </span>
                                {size !== null && (
                                    <span className="block text-[11.5px] text-fg-2">
                                        {playerStrings.resource.fileMeta(size)}
                                    </span>
                                )}
                            </span>
                            {file.url !== null ? (
                                <a
                                    className={buttonVariants({})}
                                    href={file.url}
                                    download={file.name}
                                    target={presentation.new_tab ? '_blank' : undefined}
                                    rel={presentation.new_tab ? 'noopener noreferrer' : undefined}
                                    onClick={onDownloaded}
                                >
                                    <Icon icon={Download} size={15} className="text-accent-fg" />
                                    {playerStrings.resource.download}
                                </a>
                            ) : (
                                <span className="text-xs text-fg-2">{playerStrings.resource.noAccess}</span>
                            )}
                        </li>
                    );
                })}
            </ul>
            {completed && (
                <p className="flex gap-2.5 rounded border border-border border-l-[3px] border-l-ok px-3.5 py-2.5 text-[12.5px] text-fg-2">
                    <span>
                        <strong className="font-semibold text-ok">
                            {playerStrings.resource.downloadedTitle}
                        </strong>{' '}
                        {playerStrings.resource.downloadedBody}
                    </span>
                </p>
            )}
        </div>
    );
}
