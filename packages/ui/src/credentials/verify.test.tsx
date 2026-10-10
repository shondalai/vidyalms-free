import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { CertificateVerification } from '@vidyalms/api-sdk';
import { CredentialsApp } from './app';
import {
    jsonResponse,
    makeConfig,
    makeHarness,
    makeVerification,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

/*
 * Artboard 1d, with every state the status enumeration can report.
 *
 * The rules under test are the ones with consequences. The endpoint answers
 * 200 for every input including an unknown code, so an unknown code is a
 * result and never an error. The status is single valued under the published
 * precedence, and `revoked` and `expired` are reported independently beside
 * it, because a credential can be revoked and expired at once and a single
 * value cannot say both. A superseded code keeps answering and points
 * forward, so a certificate printed on paper never goes silent after a
 * correction. And a withheld name is stated as withheld rather than blanked,
 * because the site setting and the certificate's own publication flag decide
 * what this page may disclose.
 */

function renderVerify(
    result: CertificateVerification | null,
    overrides: Parameters<typeof makeConfig>[0] = {},
    status = 200,
) {
    const config = makeConfig({ screen: 'verify', code: 'NL-7QK2-9M4X', ...overrides });
    const harness = makeHarness((request: RecordedRequest) => {
        if (request.method === 'GET' && request.path.startsWith('/v1/certificates/')) {
            return status === 200 ? jsonResponse(result) : problemResponse(status, 'Refused');
        }
        if (request.method === 'GET' && request.path.startsWith('/v1/badges/')) {
            return jsonResponse(result);
        }
        return null;
    }, config);
    render(<CredentialsApp />, { wrapper: harness.wrapper });
    return harness;
}

/**
 * The result, scoped to its own region. The polite live region the page
 * announces into is attached to document.body when there is no mount
 * element, which is the case in a test, and it survives cleanup between
 * tests, so every assertion below reads the card rather than the page.
 */
async function resultRegion() {
    return within(await screen.findByRole('region', { name: 'Verification result' }));
}

describe('the public verification page', () => {
    it('reports a valid certificate with the facts the design lists', async () => {
        renderVerify(makeVerification());
        const region = await resultRegion();
        expect(await region.findByText('Valid certificate')).toBeInTheDocument();
        expect(region.getByText('Ana Costa')).toBeInTheDocument();
        expect(region.getByText('Foundations of Web Accessibility')).toBeInTheDocument();
        expect(region.getByRole('link', { name: 'Northlight Academy' })).toBeInTheDocument();
    });

    it('says "revoked on" with the date rather than pretending the code is unknown', async () => {
        renderVerify(
            makeVerification({
                status: 'revoked',
                valid: false,
                reason: 'revoked',
                revoked: true,
                revoked_at: '2026-08-14 09:00:00',
            }),
        );
        const region = await resultRegion();
        expect(await region.findByText('Revoked certificate')).toBeInTheDocument();
        expect(region.getByText('14 August 2026')).toBeInTheDocument();
    });

    it('reports a suspension as the reversible hold it is, not as a revocation', async () => {
        renderVerify(
            makeVerification({ status: 'suspended', valid: false, reason: 'suspended', revoked: false }),
        );
        const region = await resultRegion();
        expect(await region.findByText('Suspended certificate')).toBeInTheDocument();
        expect(region.getByText(/reversible hold/)).toBeInTheDocument();
        expect(region.queryByText('Revoked certificate')).toBeNull();
    });

    it('points a superseded code forward instead of going silent', async () => {
        renderVerify(
            makeVerification({
                status: 'superseded',
                valid: false,
                reason: 'superseded',
                superseded_by_url: 'https://example.test/verify/NL-0000-NEW1',
            }),
        );
        const region = await resultRegion();
        expect(await region.findByText('Replaced by a newer certificate')).toBeInTheDocument();
        expect(region.getByRole('link', { name: 'Verify the replacement' })).toHaveAttribute(
            'href',
            'https://example.test/verify/NL-0000-NEW1',
        );
    });

    it('reports expiry and revocation as independent axes when both apply', async () => {
        renderVerify(
            makeVerification({
                status: 'revoked',
                valid: false,
                reason: 'revoked',
                revoked: true,
                expired: true,
                revoked_at: '2026-06-01 09:00:00',
                expires_at: '2026-07-01 09:00:00',
            }),
        );
        const region = await resultRegion();
        expect(await region.findByText('Revoked certificate')).toBeInTheDocument();
        expect(region.getAllByText('Revoked').length).toBeGreaterThan(0);
        expect(region.getAllByText('Expired').length).toBeGreaterThan(0);
    });

    it('reports a not-yet-valid certificate with the date it takes effect', async () => {
        renderVerify(
            makeVerification({
                status: 'not_yet_valid',
                valid: false,
                reason: 'not_yet_valid',
                valid_from: '2027-01-01 00:00:00',
            }),
        );
        const region = await resultRegion();
        expect(await region.findByText('Not yet valid')).toBeInTheDocument();
        expect(region.getByText('1 January 2027')).toBeInTheDocument();
    });

    it('answers an unknown code as a result rather than as an error', async () => {
        renderVerify(
            makeVerification({
                status: null,
                valid: false,
                reason: 'not_found',
                course_title: null,
                learner_name: null,
                issued_at: null,
                completed_at: null,
                issuer: null,
                credential_url: null,
            }),
        );
        const region = await resultRegion();
        expect(await region.findByText('No certificate matches this code')).toBeInTheDocument();
        expect(region.queryByText('Ana Costa')).toBeNull();
    });

    it('states a withheld name as withheld rather than blanking the row', async () => {
        renderVerify(makeVerification({ learner_name: null }));
        const region = await resultRegion();
        expect(await region.findByText('Withheld by the issuer')).toBeInTheDocument();
    });

    it('checks the code a person retypes, separators and all', async () => {
        const harness = renderVerify(makeVerification(), { code: '' });
        await userEvent.type(screen.getByRole('textbox', { name: 'Certificate code' }), 'nl 7qk2 9m4x');
        await userEvent.click(screen.getByRole('button', { name: 'Verify' }));
        await waitFor(() =>
            expect(
                harness.requests.some((request) => request.path.includes('nl%207qk2%209m4x')),
            ).toBe(true),
        );
    });

    it('says nothing until a code is entered', () => {
        renderVerify(makeVerification(), { code: '' });
        expect(screen.getByText('Enter a code to check it.')).toBeInTheDocument();
    });

    it('names the rate limit rather than showing a bare failure', async () => {
        renderVerify(null, {}, 429);
        expect(await screen.findByText('Too many checks from here. Wait a moment and try again.')).toBeInTheDocument();
    });

    it('does not claim the achievement is fit for a purpose, only that it verifies', () => {
        renderVerify(makeVerification());
        expect(
            screen.getByText(/Whether the achievement means the holder can do a particular job/),
        ).toBeInTheDocument();
    });

    it('verifies a badge through the badge route when the host says so', async () => {
        /* The badge route answers the widened shape: the same contract with
           the credential type and the achievement name added, which is the
           point of implementing revocation and expiry once. */
        const award = {
            ...makeVerification(),
            subject_type: 'badge_award' as const,
            achievement_name: 'Accessible Forms',
        };
        const harness = renderVerify(award, { subject: 'badge_award', code: 'NL9BADGE1234' });
        const region = await resultRegion();
        expect(await region.findByText('Accessible Forms')).toBeInTheDocument();
        expect(harness.requests.some((request) => request.path.startsWith('/v1/badges/'))).toBe(true);
    });
});
