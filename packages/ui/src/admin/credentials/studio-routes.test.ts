import { describe, expect, it } from 'vitest';
import { certificateLibraryHash, certificateLibraryState, parseCertificateRoute } from './studio-routes';
import { pdfPreviewBytes } from './pdf-preview';

describe('certificate studio deep links', () => {
    it('parses only complete positive template identities', () => {
        expect(parseCertificateRoute('/templates/42/edit')).toEqual({ kind: 'editor', templateId: 42 });
        for (const hash of [
            '/templates/0/edit',
            '/templates/-2/edit',
            '/templates/1e3/edit',
            '/templates/1/edit?x=1',
            '/templates/9007199254740992/edit',
            '/templates/42',
            '/templates/%34%32/edit',
        ]) {
            expect(parseCertificateRoute(hash)).toEqual({ kind: 'not-found' });
        }
    });
    it('accepts only safe positive certificate record identities', () => {
        expect(parseCertificateRoute('/certificate/17')).toEqual({ kind: 'record', certificateId: 17 });
        for (const value of ['0', '-1', '1e3', '9007199254740992', '%31'])
            expect(parseCertificateRoute(`/certificate/${value}`)).toEqual({ kind: 'not-found' });
    });
    it('round-trips filters without exposing them to the host query string', () => {
        const state = {
            filter: 'published',
            page: 3,
            search: 'Leadership & growth',
            category: 'executive',
        } as const;
        expect(certificateLibraryState(certificateLibraryHash(state))).toEqual(state);
        expect(certificateLibraryHash({ filter: 'all', page: 1, search: '', category: '' })).toBe(
            '/templates',
        );
    });
    it('bounds unknown or malformed filters and page values', () => {
        for (const page of ['0', '-1', 'NaN', '1000001', '3.5']) {
            expect(certificateLibraryState(`/templates?filter=unsafe&page=${page}`).page).toBe(1);
        }
        expect(certificateLibraryState('/templates?filter=unsafe').filter).toBe('all');
        expect(certificateLibraryState(`/templates?search=${'x'.repeat(500)}`).search).toHaveLength(200);
    });
});

describe('certificate PDF preview boundary', () => {
    it('accepts PDF bytes but refuses remote, HTML, malformed and oversized sources', () => {
        expect(
            pdfPreviewBytes(`data:application/pdf;base64,${btoa('%PDF-1.7\nfixture')}`)?.slice(0, 5),
        ).toEqual(new Uint8Array([37, 80, 68, 70, 45]));
        for (const value of [
            null,
            'https://example.test/a.pdf',
            'javascript:alert(1)',
            'data:text/html;base64,AAAA',
            'data:application/pdf;base64,%%%bad',
            `data:application/pdf;base64,${btoa('<html>not pdf</html>')}`,
            `data:application/pdf;base64,${'A'.repeat(12 * 1024 * 1024)}`,
        ]) {
            expect(pdfPreviewBytes(value)).toBeNull();
        }
    });
});
