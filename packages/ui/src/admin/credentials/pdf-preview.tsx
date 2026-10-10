import { useEffect, useState } from 'react';
import { certificateStudioStrings as strings } from './studio-strings';

const PREFIX = 'data:application/pdf;base64,';
const MAX_BYTES = 8 * 1024 * 1024;

/** Never navigate an arbitrary provider URL or reinterpret returned HTML as PDF. */
export function pdfPreviewBytes(source: string | null): Uint8Array | null {
    if (source === null || !source.startsWith(PREFIX) || source.length > PREFIX.length + Math.ceil(MAX_BYTES / 3) * 4) return null;
    try {
        const decoded = atob(source.slice(PREFIX.length));
        if (decoded.length > MAX_BYTES || !decoded.startsWith('%PDF-')) return null;
        return Uint8Array.from(decoded, (character) => character.charCodeAt(0));
    } catch { return null; }
}

export function CertificatePdfPreview({ source }: { source: string | null }) {
    const [preview, setPreview] = useState<{ source: string; url: string } | null>(null);
    useEffect(() => {
        const bytes = pdfPreviewBytes(source);
        if (bytes === null || source === null) { setPreview(null); return; }
        const blob = new Blob([new Uint8Array(bytes)], { type: 'application/pdf' });
        const next = URL.createObjectURL(blob);
        setPreview({ source, url: next });
        return () => URL.revokeObjectURL(next);
    }, [source]);
    const url = preview?.source === source ? preview?.url : null;
    if (url === null || url === undefined) return <p role="status" className="text-sm text-fg-2">{strings.previewInvalid}</p>;
    return <div className="grid min-w-0 gap-3">
        <a href={url} download="certificate-preview.pdf" className="justify-self-start text-sm font-medium text-accent underline underline-offset-4">
            {strings.previewDownload}
        </a>
        <object data={url} type="application/pdf" aria-label={strings.previewLabel}
            className="h-[640px] w-full rounded border border-border bg-white">
            <p className="p-4 text-sm">{strings.previewNote}</p>
        </object>
    </div>;
}
