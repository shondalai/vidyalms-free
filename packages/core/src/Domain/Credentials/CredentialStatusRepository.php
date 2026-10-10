<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * Bitstring Status List allocation, shared by certificates and badge awards.
 *
 * One entry per purpose, in a child table rather than two columns on the
 * credential row, because one slot can carry one purpose and this design
 * makes revocation and suspension independent axes on purpose. Suspending a
 * certificate whose only index came from the revocation list makes every
 * verifier report a permanent and defamatory answer to a reversible hold;
 * allocated from the suspension list instead, a later revocation has nowhere
 * to be recorded and wallets keep reporting the credential valid.
 *
 * Sharing the table between the two credential types also closes the hole
 * where both allocate from the same counter and nothing stops a badge award
 * and a certificate holding the same bit, so revoking one revokes the other.
 */
interface CredentialStatusRepository
{
    public const PURPOSE_REVOCATION = 'revocation';
    public const PURPOSE_SUSPENSION = 'suspension';

    /**
     * The purposes this build allocates for, in the order they are allocated.
     *
     * @return list<string>
     */
    public const PURPOSES = [self::PURPOSE_REVOCATION, self::PURPOSE_SUSPENSION];

    /**
     * Takes the next index for the purpose by one conditional increment,
     * never a read followed by a write, and rolls to a new list when the
     * current one is full.
     *
     * The conditional form is portable across MySQL 5.7, MariaDB and SQLite,
     * which is the constraint the whole schema honours, and rolling over is
     * what stops credential 131,073 being written past the end of a
     * 131,072-bit list where a verifier would read nothing.
     */
    public function allocate(string $subjectType, int $subjectId, string $purpose): CredentialStatusEntry;

    /**
     * The subject type a retired entry carries.
     *
     * A slot is retired rather than freed, and the difference matters. The
     * index is a promise printed inside a credential somebody may still
     * hold, so handing it to the next credential would make one holder's
     * status answer for another's. The row therefore stays, keeping the
     * slot claimed for ever, and only its subject changes.
     */
    public const SUBJECT_RETIRED = 'retired';

    /**
     * @return list<CredentialStatusEntry>
     */
    public function entriesFor(string $subjectType, int $subjectId): array;

    /**
     * Retires the slots a credential held, because the credential is gone.
     *
     * Entries are allocated by the code that creates the credential, and
     * nothing was symmetrical with that: a deleted certificate left its
     * entries behind pointing at a row that no longer exists, so every
     * status list on the site accumulated slots nobody could account for
     * and a publisher walking live credentials cleared their bits, telling
     * every wallet that a credential which no longer exists is in force.
     *
     * So the slot is detached from its subject and kept, the lists it sits
     * on are dirtied so the next publication reconsiders them, and a
     * publisher treats a retired slot as permanently revoked. Nothing is
     * ever handed back: an index cannot be un-printed.
     *
     * @return list<int> the list ids that hold a retired slot
     */
    public function retire(string $subjectType, int $subjectId, \DateTimeImmutable $now): array;

    /**
     * Marks the lists holding this subject as needing republication, setting
     * dirty_since only when it is null.
     *
     * The ceiling is the point: scheduling the publish at now plus a delay
     * under a dedupe key lets two hundred revocations an hour apart push the
     * pending task forward every time, so the list is republished five
     * minutes after the last one and every wallet reads all two hundred as
     * valid for the whole hour.
     *
     * @return list<int> the list ids that became dirty or were already dirty
     */
    public function markDirty(string $subjectType, int $subjectId, \DateTimeImmutable $now): array;

    public function findList(int $listId): ?CredentialStatusList;

    public function findListByUuid(string $listUuid): ?CredentialStatusList;

    public function clearDirty(int $listId, string $encodedList, \DateTimeImmutable $publishedAt): void;
}
