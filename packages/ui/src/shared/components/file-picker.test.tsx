import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FilePicker } from './file-picker';
import { FakeXhrServer } from '../testing/fake-xhr';
import { IMAGE_POLICY, RESOURCE_POLICY } from '../upload';

/*
 * The picker's states: refused before sending, uploading with progress,
 * uploaded, and refused by the host. The transport is the seam (the fake
 * XHR), so nothing here reaches a network.
 */

const answer = {
    path: 'vidyalms/2026/09/cover.png',
    name: 'cover.png',
    size_bytes: 2048,
    mime: 'image/png',
    url: 'https://example.test/media/cover.png',
};

function imageFile(name = 'cover.png', size = 2048): File {
    const file = new File(['x'], name, { type: 'image/png' });
    Object.defineProperty(file, 'size', { value: size });
    return file;
}

describe('FilePicker', () => {
    const server = new FakeXhrServer();

    beforeEach(() => {
        server.install();
    });

    afterEach(() => {
        server.restore();
    });

    it('states its size limit and its accepted types under the control', () => {
        render(
            <FilePicker
                endpoint="/host/upload"
                label="Upload an image"
                policy={IMAGE_POLICY}
                onUploaded={() => undefined}
            />,
        );
        expect(screen.getByText(/Up to 8 MB\. Accepted: jpg, jpeg, png, webp, gif\./)).toBeInTheDocument();
    });

    it('uploads the chosen file and hands the whole answer to the caller', async () => {
        server.answerWith({ status: 201, body: JSON.stringify(answer) });
        const onUploaded = vi.fn();
        render(
            <FilePicker
                endpoint="/host/upload"
                headers={{ 'X-CSRF-Token': 'token' }}
                label="Upload an image"
                policy={IMAGE_POLICY}
                onUploaded={onUploaded}
            />,
        );

        await userEvent.upload(screen.getByLabelText('Upload an image'), imageFile());

        await waitFor(() => expect(onUploaded).toHaveBeenCalledWith(answer.path, answer));
        expect(await screen.findByText('cover.png uploaded.')).toBeInTheDocument();
        expect(server.calls[0]?.headers['x-csrf-token']).toBe('token');
    });

    it('refuses a file over the limit without sending a byte', async () => {
        render(
            <FilePicker
                endpoint="/host/upload"
                label="Upload an image"
                policy={IMAGE_POLICY}
                onUploaded={() => undefined}
            />,
        );

        await userEvent.upload(
            screen.getByLabelText('Upload an image'),
            imageFile('huge.png', 9 * 1024 * 1024),
        );

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('That file is larger than 8 MB. Choose a smaller one.');
        expect(server.calls).toEqual([]);
        expect(screen.getByLabelText('Upload an image')).toHaveAttribute('aria-invalid', 'true');
    });

    it('refuses a type the policy does not list without sending a byte', async () => {
        render(
            <FilePicker
                endpoint="/host/upload"
                label="Upload an image"
                policy={IMAGE_POLICY}
                onUploaded={() => undefined}
            />,
        );

        await userEvent.upload(screen.getByLabelText('Upload an image'), imageFile('logo.svg'));

        expect(await screen.findByRole('alert')).toHaveTextContent('That file type is not accepted here.');
        expect(server.calls).toEqual([]);
    });

    it('shows how far the upload has gone, on a bar with a name and a value', async () => {
        server.answerWith({ status: 200, body: JSON.stringify(answer), progress: [40], hold: true });
        render(
            <FilePicker
                endpoint="/host/upload"
                label="Upload a file"
                policy={RESOURCE_POLICY}
                onUploaded={() => undefined}
            />,
        );

        await userEvent.upload(screen.getByLabelText('Upload a file'), imageFile());

        const bar = await screen.findByRole('progressbar', { name: 'Upload progress' });
        expect(bar).toHaveAttribute('aria-valuenow', '40');
        /* The control is out of reach while its own upload runs, so a second
           pick cannot race the first into the form. */
        expect(screen.getByLabelText('Upload a file')).toBeDisabled();

        await act(async () => {
            server.release();
            await Promise.resolve();
        });
        expect(await screen.findByText('cover.png uploaded.')).toBeInTheDocument();
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });

    it('sends the purpose its policy names, which the host validates', async () => {
        server.answerWith({ status: 201, body: JSON.stringify(answer) });
        render(
            <FilePicker
                endpoint="/host/upload"
                label="Upload an image"
                policy={IMAGE_POLICY}
                requires="url"
                onUploaded={() => undefined}
            />,
        );

        await userEvent.upload(screen.getByLabelText('Upload an image'), imageFile());

        await waitFor(() => expect(server.calls[0]?.body?.get('purpose')).toBe('image'));
    });

    it('refuses an answer with no address where the field holds one', async () => {
        server.answerWith({ status: 201, body: JSON.stringify({ ...answer, url: null }) });
        const onUploaded = vi.fn();
        render(
            <FilePicker
                endpoint="/host/upload"
                label="Upload an image"
                policy={IMAGE_POLICY}
                requires="url"
                onUploaded={onUploaded}
            />,
        );

        await userEvent.upload(screen.getByLabelText('Upload an image'), imageFile());

        expect(await screen.findByRole('alert')).toHaveTextContent('the site answered no address for it');
        expect(onUploaded).not.toHaveBeenCalled();
    });

    it('takes the private path where the field holds one, which a private purpose answers with', async () => {
        server.answerWith({ status: 201, body: JSON.stringify({ ...answer, url: null }) });
        const onUploaded = vi.fn();
        render(
            <FilePicker
                endpoint="/host/upload"
                label="Upload a file"
                policy={RESOURCE_POLICY}
                onUploaded={onUploaded}
            />,
        );

        await userEvent.upload(screen.getByLabelText('Upload a file'), imageFile());

        await waitFor(() =>
            expect(onUploaded).toHaveBeenCalledWith('vidyalms/2026/09/cover.png', { ...answer, url: null }),
        );
    });

    it("shows the host's refusal in the host's own words", async () => {
        server.answerWith({
            status: 422,
            contentType: 'application/problem+json',
            body: JSON.stringify({ title: 'Validation failed', detail: 'Uploads are switched off on this site.' }),
        });
        const onUploaded = vi.fn();
        render(
            <FilePicker
                endpoint="/host/upload"
                label="Upload a file"
                policy={RESOURCE_POLICY}
                onUploaded={onUploaded}
            />,
        );

        await userEvent.upload(screen.getByLabelText('Upload a file'), imageFile());

        expect(await screen.findByRole('alert')).toHaveTextContent('Uploads are switched off on this site.');
        expect(onUploaded).not.toHaveBeenCalled();
    });

    it('says nothing was stored when the connection drops', async () => {
        server.answerWith({ status: 0, body: '', fail: 'error' });
        render(
            <FilePicker
                endpoint="/host/upload"
                label="Upload a file"
                policy={RESOURCE_POLICY}
                onUploaded={() => undefined}
            />,
        );

        await userEvent.upload(screen.getByLabelText('Upload a file'), imageFile());

        expect(await screen.findByRole('alert')).toHaveTextContent("The upload didn't finish. Nothing was stored.");
    });
});
