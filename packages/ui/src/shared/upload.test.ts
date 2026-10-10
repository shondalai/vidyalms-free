import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    checkUpload,
    extensionOf,
    IMAGE_POLICY,
    readRefusal,
    readUploadedFile,
    RESOURCE_POLICY,
    UploadError,
    policyFor,
    uploadFile,
    type UploadPolicy,
} from './upload';
import { FakeXhrServer } from './testing/fake-xhr';

const answer = {
    path: 'vidyalms/2026/09/audit.pdf',
    name: 'audit.pdf',
    size_bytes: 4096,
    mime: 'application/pdf',
    url: 'https://example.test/media/audit.pdf',
};

function file(name: string, size: number, type = 'application/pdf'): File {
    const blob = new File(['x'], name, { type });
    Object.defineProperty(blob, 'size', { value: size });
    return blob;
}

describe('upload policy', () => {
    const policy: UploadPolicy = { purpose: 'resource', maxBytes: 1000, extensions: ['pdf', 'zip'] };

    it('reads the extension of a name, and of a name that has none', () => {
        expect(extensionOf('audit.PDF')).toBe('pdf');
        expect(extensionOf('archive.tar.gz')).toBe('gz');
        expect(extensionOf('README')).toBe('');
        expect(extensionOf('.hidden')).toBe('');
    });

    it('accepts a file inside the limit and the list', () => {
        expect(checkUpload({ name: 'audit.pdf', size: 999 }, policy)).toBeNull();
    });

    it('names the limit when the file is over it, so the reader knows what to do', () => {
        expect(checkUpload({ name: 'audit.pdf', size: 1001 }, policy)).toBe(
            'That file is larger than 1000 bytes. Choose a smaller one.',
        );
    });

    it('names the accepted types when the extension is not one of them', () => {
        expect(checkUpload({ name: 'notes.exe', size: 10 }, policy)).toBe(
            'That file type is not accepted here. Accepted: pdf, zip.',
        );
    });

    it('refuses an empty file, which no host stores usefully', () => {
        expect(checkUpload({ name: 'audit.pdf', size: 0 }, policy)).toBe('That file is empty.');
    });

    it('accepts any extension where the policy names none, because the host owns that list', () => {
        expect(checkUpload({ name: 'anything.xyz', size: 10 }, RESOURCE_POLICY)).toBeNull();
    });

    it('keeps SVG out of the image policy, which is a script-carrying format', () => {
        expect(IMAGE_POLICY.extensions).not.toContain('svg');
        expect(checkUpload({ name: 'logo.svg', size: 10 }, IMAGE_POLICY)).toContain('not accepted');
    });

    it('names the purposes both adapters register, which the endpoint validates', () => {
        expect(IMAGE_POLICY.purpose).toBe('image');
        expect(RESOURCE_POLICY.purpose).toBe('resource');
    });
});

describe('reading the host answer', () => {
    it('reads the documented shape', () => {
        expect(readUploadedFile(answer)).toEqual(answer);
    });

    it('reads a private answer, whose url is null rather than a signed link', () => {
        expect(readUploadedFile({ ...answer, url: null }).url).toBeNull();
    });

    it('defaults the size and the type, which are metadata, not the file', () => {
        expect(readUploadedFile({ path: 'a', name: 'a', url: 'u' })).toEqual({
            path: 'a',
            name: 'a',
            url: 'u',
            size_bytes: 0,
            mime: '',
        });
    });

    it('refuses an answer with no path or no name instead of writing a half file into a form', () => {
        expect(() => readUploadedFile({ name: 'a', url: 'u' })).toThrow(UploadError);
        expect(() => readUploadedFile({ path: 'a', url: 'u' })).toThrow(UploadError);
        expect(() => readUploadedFile(null)).toThrow(UploadError);
    });
});

describe('reading a refusal', () => {
    it('takes the detail of an RFC 9457 problem, verbatim', () => {
        expect(
            readRefusal(
                422,
                JSON.stringify({ title: 'Validation failed', detail: 'PDF files are not accepted here.' }),
                'application/problem+json',
            ),
        ).toBe('PDF files are not accepted here.');
    });

    it('takes the message of a WordPress style error', () => {
        expect(readRefusal(403, JSON.stringify({ code: 'rest_forbidden', message: 'Sorry, you may not upload.' }), 'application/json')).toBe(
            'Sorry, you may not upload.',
        );
    });

    it('falls back to the title when a problem carries no detail', () => {
        expect(readRefusal(413, JSON.stringify({ title: 'Payload too large' }), 'application/json')).toBe(
            'Payload too large',
        );
    });

    it('takes a short plain text answer as it stands', () => {
        expect(readRefusal(400, 'Upload directory is not writable', 'text/plain')).toBe(
            'Upload directory is not writable',
        );
    });

    it('never shows an HTML page, which is a login screen or a stack trace', () => {
        expect(readRefusal(500, '<html><body>Fatal error</body></html>', 'text/html')).toBe(
            'The site refused the upload (status 500).',
        );
    });

    it('falls back to the status when the JSON says nothing a reader can act on', () => {
        expect(readRefusal(500, JSON.stringify({ ok: false }), 'application/json')).toBe(
            'The site refused the upload (status 500).',
        );
    });
});

describe('uploadFile', () => {
    const server = new FakeXhrServer();

    beforeEach(() => {
        server.install();
    });

    afterEach(() => {
        server.restore();
    });

    it('posts the file as multipart with the host headers and no content type of its own', async () => {
        server.answerWith({ status: 201, body: JSON.stringify(answer) });
        const result = await uploadFile({
            endpoint: '/host/upload',
            file: file('audit.pdf', 4096),
            purpose: 'resource',
            headers: { 'X-CSRF-Token': 'token', 'Content-Type': 'application/json' },
        });

        expect(result).toEqual(answer);
        const call = server.calls[0];
        expect(call?.method).toBe('POST');
        expect(call?.url).toBe('/host/upload');
        expect(call?.headers['x-csrf-token']).toBe('token');
        /* The host's policy is a registry keyed on the purpose, and an
           unknown one is a 422, so it travels with every request. */
        expect(call?.body?.get('purpose')).toBe('resource');
        /* The browser owns the multipart boundary; setting a type here would
           break the body it built. */
        expect(call?.headers['content-type']).toBeUndefined();
        expect(call?.body?.get('file')).toBeInstanceOf(File);
    });

    it('passes the organisation alongside a private roster upload', async () => {
        server.answerWith({ status: 201, body: JSON.stringify({ ...answer, path: 'imports/2/roster.csv', url: null }) });
        const uploaded = await uploadFile({ endpoint: '/host/upload', file: file('roster.csv', 100), purpose: 'org_import', organisationId: 7 });
        expect(uploaded.url).toBeNull();
        expect(server.calls[0]?.body?.get('organisation_id')).toBe('7');
        expect(server.calls[0]?.body?.get('purpose')).toBe('org_import');
    });

    it('reports progress while the body goes out', async () => {
        server.answerWith({ status: 200, body: JSON.stringify(answer), progress: [25, 80] });
        const seen: number[] = [];
        await uploadFile({
            endpoint: '/host/upload',
            file: file('audit.pdf', 4096),
            purpose: 'resource',
            onProgress: (percent) => seen.push(percent),
        });
        expect(seen).toEqual([25, 80]);
    });

    it('rejects with the host refusal, word for word', async () => {
        server.answerWith({
            status: 422,
            contentType: 'application/problem+json',
            body: JSON.stringify({ title: 'Validation failed', detail: 'The file extension zip is not allowed.' }),
        });
        await expect(
            uploadFile({ endpoint: '/host/upload', file: file('audit.zip', 10), purpose: 'resource' }),
        ).rejects.toThrow('The file extension zip is not allowed.');
    });

    it('rejects when the connection drops, saying nothing was stored', async () => {
        server.answerWith({ status: 0, body: '', fail: 'error' });
        await expect(
            uploadFile({ endpoint: '/host/upload', file: file('a.pdf', 10), purpose: 'resource' }),
        ).rejects.toThrow("The upload didn't finish. Nothing was stored.");
    });

    it('rejects an answer it cannot read rather than writing an empty path', async () => {
        server.answerWith({ status: 200, body: 'not json' });
        await expect(
            uploadFile({ endpoint: '/host/upload', file: file('a.pdf', 10), purpose: 'resource' }),
        ).rejects.toThrow('could not read');
    });

    it('gives up before opening a request when the caller already aborted', async () => {
        const controller = new AbortController();
        controller.abort();
        const onProgress = vi.fn();
        await expect(
            uploadFile({
                endpoint: '/host/upload',
                file: file('a.pdf', 10),
                purpose: 'resource',
                signal: controller.signal,
                onProgress,
            }),
        ).rejects.toThrow('cancelled');
        expect(server.calls).toEqual([]);
    });
});

/*
 * A request that hangs rather than errors used to have no ending at all: the
 * transport registered a timeout listener and never set xhr.timeout, so the
 * only recoveries were a dropped connection or a signal nothing passed.
 */
describe('an upload that stalls', () => {
    const server = new FakeXhrServer();

    beforeEach(() => server.install());
    afterEach(() => server.restore());

    it('sets a timeout on the request, which is what makes the timeout event possible', async () => {
        server.answerWith({ status: 201, body: JSON.stringify(answer) });

        await uploadFile({ endpoint: '/host/upload', file: new File(['x'], 'a.pdf'), purpose: 'resource' });

        expect(server.calls[0]?.timeout).toBeGreaterThan(0);
    });

    it('honours a timeout the caller lowered', async () => {
        server.answerWith({ status: 201, body: JSON.stringify(answer) });

        await uploadFile({
            endpoint: '/host/upload',
            file: new File(['x'], 'a.pdf'),
            purpose: 'resource',
            timeoutMs: 5_000,
        });

        expect(server.calls[0]?.timeout).toBe(5_000);
    });

    it('gives up with a message when the request times out', async () => {
        server.answerWith({ status: 0, body: '', fail: 'timeout' });

        await expect(
            uploadFile({ endpoint: '/host/upload', file: new File(['x'], 'a.pdf'), purpose: 'resource' }),
        ).rejects.toBeInstanceOf(UploadError);
    });

    it('is abandoned by the signal the caller passes, so a cancel button can exist', async () => {
        server.answerWith({ status: 201, body: JSON.stringify(answer), hold: true });
        const controller = new AbortController();

        const pending = uploadFile({
            endpoint: '/host/upload',
            file: new File(['x'], 'a.pdf'),
            purpose: 'resource',
            signal: controller.signal,
        });

        controller.abort();

        await expect(pending).rejects.toMatchObject({ message: expect.stringContaining('cancelled') });
    });
});

/*
 * The ceilings were a copy of the free build's defaults, so a site that
 * raised one could not use it: the picker refused before sending.
 */
describe('the policy a host states', () => {
    it('keeps the free build defaults when the host says nothing', () => {
        expect(policyFor(IMAGE_POLICY, undefined)).toEqual(IMAGE_POLICY);
        expect(policyFor(RESOURCE_POLICY, [])).toEqual(RESOURCE_POLICY);
    });

    it('takes the ceiling and the list the host actually enforces', () => {
        const policy = policyFor(IMAGE_POLICY, [
            { key: 'image', max_bytes: 32 * 1024 * 1024, extensions: ['png', 'avif'] },
            { key: 'resource', max_bytes: 1024, extensions: [] },
        ]);

        expect(policy.maxBytes).toBe(32 * 1024 * 1024);
        expect(policy.extensions).toEqual(['png', 'avif']);
        expect(policy.purpose).toBe('image');
    });

    it('keeps the fallback for a purpose the host did not mention', () => {
        expect(policyFor(IMAGE_POLICY, [{ key: 'firmware', max_bytes: 1, extensions: [] }])).toEqual(IMAGE_POLICY);
    });

    it('reads an empty extension list as no opinion, which is what a resource purpose says', () => {
        const policy = policyFor(RESOURCE_POLICY, [{ key: 'resource', max_bytes: 99, extensions: [] }]);

        expect(policy.maxBytes).toBe(99);
        expect(policy.extensions).toEqual([]);
    });

    it('accepts a file the site raised its ceiling for', () => {
        const raised = policyFor(IMAGE_POLICY, [{ key: 'image', max_bytes: 32 * 1024 * 1024, extensions: [] }]);

        expect(checkUpload({ name: 'big.png', size: 20 * 1024 * 1024 }, raised)).toBeNull();
        expect(checkUpload({ name: 'big.png', size: 20 * 1024 * 1024 }, IMAGE_POLICY)).not.toBeNull();
    });
});
