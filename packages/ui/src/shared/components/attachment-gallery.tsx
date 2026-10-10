import { translate as uiText } from '@/shared/i18n';
import { useEffect, useState } from 'react';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from './dialog';
import { practicalStrings as s } from '../practical-strings';

export interface Attachment { filename: string; url: string; mime: string; size_bytes: number }
const photo = (file: Attachment) => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.mime);

/** Private downloads deliberately use attachment headers. Display verified
 * raster bytes through a temporary object URL without weakening that policy. */
function Photo({ file, large = false }: { file: Attachment; large?: boolean }) {
    const [url, setUrl] = useState('');
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        const controller = new AbortController();
        let objectUrl = '';
        setUrl('');
        setFailed(false);
        void fetch(file.url, { credentials: 'same-origin', signal: controller.signal })
            .then(async (response) => {
                if (!response.ok) throw new Error(uiText("vidyalms.ui.7324ed169eae6ac0", "Photo unavailable"));
                const blob = await response.blob();
                if (controller.signal.aborted) return;
                objectUrl = URL.createObjectURL(new Blob([blob], { type: file.mime }));
                setUrl(objectUrl);
            }).catch(() => { if (!controller.signal.aborted) setFailed(true); });
        return () => { controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
    }, [file.url, file.mime]);
    if (failed) return <span className="text-xs text-fg-2">{s.previewFailed}</span>;
    return url ? <img src={url} alt={file.filename} className={large ? 'max-h-[70vh] w-full object-contain' : 'h-36 w-full rounded object-cover'} />
        : <span className="block h-36 w-full animate-pulse rounded bg-surface-2" aria-hidden="true" />;
}

export function AttachmentGallery({ files }: { files: Attachment[] }) {
    const [selected, setSelected] = useState<Attachment | null>(null);
    return <>
        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {files.map(file => <li key={file.url} className="min-w-0 rounded-lg border border-border bg-surface p-3">
                {photo(file) && <button type="button" className="block w-full cursor-zoom-in" onClick={() => setSelected(file)} aria-label={uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: s.enlarge, p1: file.filename })}><Photo file={file} /></button>}
                <p className="mt-2 break-words text-xs font-semibold">{file.filename}</p>
                <p className="mb-2 text-xs text-fg-2">{Math.ceil(file.size_bytes / 1024)} {uiText("vidyalms.ui.550fde8751fff8ef", " KB")}</p>
                <a href={file.url} target="_blank" rel="noreferrer" className="text-xs text-accent underline">{s.download}<span className="sr-only"> {file.filename}</span></a>
            </li>)}
        </ul>
        <Dialog open={selected !== null} onOpenChange={open => { if (!open) setSelected(null); }}>
            <DialogContent className="max-w-4xl">
                <DialogTitle>{selected?.filename}</DialogTitle>
                <DialogDescription>{s.evidence}</DialogDescription>
                {selected && <Photo file={selected} large />}
            </DialogContent>
        </Dialog>
    </>;
}
