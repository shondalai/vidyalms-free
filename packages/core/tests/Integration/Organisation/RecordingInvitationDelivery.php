<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\OrganisationInvitationDelivery;

/** Synthetic test capture only: never connects to a mailbox or production queue. */
final class RecordingInvitationDelivery implements OrganisationInvitationDelivery
{
    /** @var list<array{kind: string, org: int, invitation: int, claim: ?int, email: string, secret: string, expires: string, key: string}> */
    public array $notices = [];
    public bool $fail = false;

    public function queue(
        string $kind,
        int $organisationId,
        int $invitationId,
        ?int $claimId,
        string $recipientEmail,
        string $secret,
        string $expiresAt,
        string $dedupeKey
    ): void {
        if ($this->fail) {
            throw new \RuntimeException('Synthetic unavailable notification queue.');
        }
        $this->notices[] = ['kind' => $kind, 'org' => $organisationId, 'invitation' => $invitationId,
            'claim' => $claimId, 'email' => $recipientEmail, 'secret' => $secret, 'expires' => $expiresAt, 'key' => $dedupeKey];
    }

    /**
     * The raw secret of the most recent notice.
     *
     * The ceremony hands the raw token to the delivery and stores only its
     * hash, so a test that wants to complete an acceptance has to read it
     * from here. There is deliberately no way to read it back out of the
     * database, which is the property this recorder exists beside.
     */
    public function lastSecret(): string
    {
        if ($this->notices === []) {
            throw new \RuntimeException('No invitation notice has been queued.');
        }

        return $this->notices[array_key_last($this->notices)]['secret'];
    }
}
