import { StrictMode } from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CertificatePdfPreview } from './pdf-preview';

const source = (text: string) => `data:application/pdf;base64,${btoa(`%PDF-1.7\n${text}`)}`;

describe('certificate PDF object URL ownership', () => {
    const live = new Map<string, Blob>();
    let sequence = 0;

    beforeEach(() => {
        live.clear(); sequence = 0;
        vi.spyOn(URL, 'createObjectURL').mockImplementation((blob) => {
            const url = `blob:http://localhost/preview-${++sequence}`;
            live.set(url, blob as Blob);
            return url;
        });
        vi.spyOn(URL, 'revokeObjectURL').mockImplementation((url) => { live.delete(url); });
    });
    afterEach(() => { cleanup(); vi.restoreAllMocks(); });

    it('keeps the displayed link alive through unchanged renders and PDF viewer load events', () => {
        const view = render(<CertificatePdfPreview source={source('first')} />);
        const link = screen.getByRole('link', { name: 'Download sample PDF' });
        const url = link.getAttribute('href')!;
        expect(live.get(url)?.type).toBe('application/pdf');
        fireEvent.load(screen.getByLabelText('Certificate PDF preview'));
        view.rerender(<CertificatePdfPreview source={source('first')} />);
        expect(screen.getByRole('link', { name: 'Download sample PDF' })).toBe(link);
        expect(live.has(url)).toBe(true);
        expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
        expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        view.unmount();
        expect(live.size).toBe(0);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith(url);
    });

    it('replaces the visible URL before retiring its old source and cleans invalidation', () => {
        const view = render(<CertificatePdfPreview source={source('first')} />);
        const oldUrl = screen.getByRole('link', { name: 'Download sample PDF' }).getAttribute('href')!;
        view.rerender(<CertificatePdfPreview source={source('second')} />);
        const nextUrl = screen.getByRole('link', { name: 'Download sample PDF' }).getAttribute('href')!;
        expect(nextUrl).not.toBe(oldUrl);
        expect(live.has(oldUrl)).toBe(false);
        expect(live.has(nextUrl)).toBe(true);
        expect(screen.getByLabelText('Certificate PDF preview')).toHaveAttribute('data', nextUrl);
        view.rerender(<CertificatePdfPreview source={null} />);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(live.size).toBe(0);
    });

    it('does not publish a revoked link when React replays mount effects in StrictMode', () => {
        const view = render(<StrictMode><CertificatePdfPreview source={source('strict')} /></StrictMode>);
        const url = screen.getByRole('link', { name: 'Download sample PDF' }).getAttribute('href')!;
        expect(live.size).toBe(1);
        expect(live.has(url)).toBe(true);
        expect(screen.getByLabelText('Certificate PDF preview')).toHaveAttribute('data', url);
        view.unmount();
        expect(live.size).toBe(0);
        expect(URL.revokeObjectURL).toHaveBeenCalledTimes(sequence);
    });
});
