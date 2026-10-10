import { useEffect, useRef, useState } from 'react';
import displayFont from '@fontsource-variable/bricolage-grotesque/files/bricolage-grotesque-latin-wght-normal.woff2?inline';

/** Current source artwork, sandboxed and scaled without starting a PDF worker. */
export function CertificateArtwork({
    html,
    width = 297,
    height = 210,
    title,
}: {
    html: string;
    width?: number;
    height?: number;
    title: string;
}) {
    const container = useRef<HTMLDivElement>(null);
    const [availableWidth, setAvailableWidth] = useState(300);
    useEffect(() => {
        const element = container.current;
        if (element === null || typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(([entry]) => {
            if (entry !== undefined && entry.contentRect.width > 0)
                setAvailableWidth(entry.contentRect.width);
        });
        observer.observe(element);
        return () => observer.disconnect();
    }, []);
    const paperWidth = (Math.max(1, width) * 96) / 25.4;
    const paperHeight = (Math.max(1, height) * 96) / 25.4;
    const document =
        `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; font-src data:; img-src data:; base-uri 'none'; form-action 'none'">` +
        `<style>@font-face{font-family:'Bricolage Grotesque';font-weight:100 900;src:url('${displayFont}') format('woff2')}html,body{margin:0;padding:0;background:white}a{color:inherit}</style>` +
        html;
    return (
        <div
            ref={container}
            className="vy-cert-artwork"
            style={{ aspectRatio: `${paperWidth} / ${paperHeight}` }}
        >
            <iframe
                title={title}
                tabIndex={-1}
                sandbox=""
                srcDoc={document}
                loading="lazy"
                style={{
                    width: paperWidth,
                    height: paperHeight,
                    transform: `scale(${availableWidth / paperWidth})`,
                }}
            />
        </div>
    );
}
