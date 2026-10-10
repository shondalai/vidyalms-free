<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The other end of allocation: a credential's row is going away, so its
 * status list slots stop belonging to it.
 *
 * The design puts allocation in the code that creates the credential,
 * because a slot cannot be back-filled deterministically and a signed
 * credential cannot be edited once it sits in a wallet. Nothing was
 * symmetrical with that. This product never deletes a certificate (a
 * correction is a supersession and nothing is ever mutated or deleted beyond
 * the lifecycle fields), but a host does: an erasure request, an uninstall,
 * a fixture teardown. Every one of those left the entries behind, pointing
 * at a row that no longer existed, and both live sites finished a smoke run
 * with every status entry orphaned.
 *
 * Two things follow from that, and they pull in opposite directions, which
 * is why the answer is neither "delete the row" nor "leave it".
 *
 * The slot may never be reused. The index is printed inside a document
 * somebody may still hold, and giving it to the next credential would make
 * one holder's revocation answer for another's. So the row stays and keeps
 * the `(list_id, list_index)` slot claimed for ever.
 *
 * The slot must stop reading as valid. A publisher walking live credentials
 * finds nothing for a deleted one and clears its bit, which tells every
 * wallet that a credential that no longer exists is in force. So the entry
 * is retired rather than removed: a publisher treats a retired slot as
 * permanently revoked, and the lists are dirtied so the next publication
 * takes account of it.
 *
 * This is the published path rather than an internal one, because the caller
 * is the host: giving it an invented caller inside the core would be worse
 * than saying plainly that the core never deletes a credential and this is
 * what to call when something else does.
 *
 * The one host delete path that exists today is the WordPress personal data
 * eraser, and it retires before it deletes. It calls the repository rather
 * than this use case because it runs behind WordPress's own erase-capability
 * check with no Principal of its own, and a guard refusal there would abort a
 * lawful erasure. This class is the guarded form for a host that has one.
 */
final class RetireCredential
{
    public function __construct(
        private readonly CredentialStatusRepository $statusLists,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return list<int> the list ids now holding a retired slot
     */
    public function certificate(Principal $principal, int $certificateId): array
    {
        return $this->execute($principal, IssuanceRecord::SUBJECT_CERTIFICATE, $certificateId);
    }

    /**
     * @return list<int> the list ids now holding a retired slot
     */
    public function badgeAward(Principal $principal, int $awardId): array
    {
        return $this->execute($principal, IssuanceRecord::SUBJECT_BADGE_AWARD, $awardId);
    }

    /**
     * @return list<int>
     */
    private function execute(Principal $principal, string $subjectType, int $subjectId): array
    {
        $this->guard->require($principal, Capability::MANAGE);

        return $this->statusLists->retire($subjectType, $subjectId, $this->clock->now());
    }
}
