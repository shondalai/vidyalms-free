<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * What an acceptor wrote, named well enough for the provenance row.
 *
 * The acceptor does the domain write and reports what it created; the accept
 * use case keeps what only it may own, which is the human-Principal check,
 * the transaction boundary, the provenance write and the disclosure-tier
 * derivation. So this carries an identity and not an entity: a returned
 * domain object would put a second write path inside the accept step and make
 * the provenance row depend on which acceptor answered.
 */
final class AcceptedArtefact
{
    /** @var array<string, scalar|null> */
    public readonly array $facts;

    /**
     * @param array<string, scalar|null> $facts anything the provenance row should carry, keyed
     */
    private function __construct(
        public readonly string $extensionPoint,
        public readonly string $providerKey,
        public readonly string $entityType,
        public readonly int $entityId,
        array $facts,
    ) {
        $this->facts = $facts;
    }

    /**
     * @param array<string, scalar|null> $facts
     */
    public static function of(
        string $extensionPoint,
        string $providerKey,
        string $entityType,
        int $entityId,
        array $facts = [],
    ): self {
        return new self($extensionPoint, $providerKey, $entityType, $entityId, $facts);
    }
}
