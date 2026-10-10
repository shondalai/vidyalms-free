<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * One credential's slot in one status list, for one purpose.
 *
 * Entries are allocated by the code that creates the credential, because they
 * cannot be back-filled deterministically afterwards and a signed credential
 * cannot be edited once it sits in a wallet.
 */
final class CredentialStatusEntry
{
    public function __construct(
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly string $purpose,
        public readonly int $listId,
        public readonly string $listUuid,
        public readonly int $index,
    ) {
    }

    /**
     * The shape the credential builder reads, which is deliberately the list
     * uuid rather than the numeric id: a credential must not carry a
     * database key.
     *
     * @return array{purpose: string, listUuid: string, index: int}
     */
    public function toCredentialEntry(): array
    {
        return ['purpose' => $this->purpose, 'listUuid' => $this->listUuid, 'index' => $this->index];
    }
}
