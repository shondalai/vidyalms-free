<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * REQUIRED durable notification-engine bridge, never a direct mail sender.
 *
 * queue() runs INSIDE the invitation's DatabasePort transaction. It must
 * enqueue on that SAME connection, throw when durable queuing is unavailable,
 * and perform no SMTP/network delivery until after commit. Secret and exact
 * recipient are sensitive: encrypt durable queue payloads using the host's
 * managed secret, redact API/queue previews/logs, and erase the encrypted
 * payload after delivery or expiry. Do not place the raw secret in a domain
 * event, audit entry, idempotency response or unencrypted notification params.
 *
 * kind is invitation (64-character link token, seven-day lifetime) or proof
 * (12-character mailbox proof, ten-minute lifetime). dedupeKey identifies
 * this exact invitation revision or proof claim; re-queuing it cannot resend.
 * Delivery must recheck invitation/claim state and expiry before sending, so
 * revocation, rotation and consumption cancel old pending messages.
 */
interface OrganisationInvitationDelivery
{
    public function queue(
        string $kind,
        int $organisationId,
        int $invitationId,
        ?int $claimId,
        string $recipientEmail,
        string $secret,
        string $expiresAt,
        string $dedupeKey,
    ): void;
}
