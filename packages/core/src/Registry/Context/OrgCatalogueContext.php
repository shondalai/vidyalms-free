<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueReaderInterface;

/**
 * What an `OrgCatalogueSource` provider resolves an entry against.
 *
 * It carries the organisation, the entry's own scope, the resolved unit
 * subtree, the entry's versioned parameters, the clock and one narrow reader,
 * which is the row the design's context table fixes for it.
 *
 * **No raw database handle, on the standing rule.** The reader is three read
 * questions and nothing else, each of them already narrowed to this
 * organisation inside the implementation, so a third-party source can ask what
 * this organisation reaches and can neither widen the predicate nor write.
 *
 * The constructor is private and the factory is named, like every other
 * context in this design. A provider receives one of these and can forge
 * neither the entry it names nor the organisation it belongs to.
 */
final class OrgCatalogueContext
{
    public const SCOPE_ORGANISATION = 'organisation';
    public const SCOPE_UNIT = 'unit';

    public const MODE_VISIBLE = 'visible';
    public const MODE_AUTO_GRANT = 'auto_grant';

    private function __construct(
        public readonly int $organisationId,
        public readonly int $entryId,
        public readonly string $sourceKey,
        public readonly string $scopeType,
        public readonly int $scopeId,
        public readonly string $grantMode,
        /**
         * Every unit this entry reaches, ancestor first, already resolved
         * through the closure table.
         *
         * It is a resolved list rather than a starting unit and a flag,
         * because the walk is a tenancy question and a provider that walked it
         * itself would be a provider deciding its own reach.
         *
         * @var list<int>
         */
        public readonly array $unitSubtree,
        /** @var array<string, mixed> */
        public readonly array $params,
        public readonly int $paramsVersion,
        public readonly \DateTimeImmutable $now,
        public readonly OrgCatalogueReaderInterface $reader,
    ) {
    }

    /**
     * @param list<int>            $unitSubtree
     * @param array<string, mixed> $params
     */
    public static function forEntry(
        int $organisationId,
        int $entryId,
        string $sourceKey,
        string $scopeType,
        int $scopeId,
        string $grantMode,
        array $unitSubtree,
        array $params,
        int $paramsVersion,
        \DateTimeImmutable $now,
        OrgCatalogueReaderInterface $reader,
    ): self {
        return new self(
            $organisationId,
            $entryId,
            $sourceKey,
            $scopeType,
            $scopeId,
            $grantMode,
            array_values(array_unique(array_map(static fn (int $id): int => $id, $unitSubtree))),
            $params,
            $paramsVersion,
            $now,
            $reader,
        );
    }

    /** One integer parameter, or null when the entry did not carry a usable one. */
    public function integerParam(string $name): ?int
    {
        $value = $this->params[$name] ?? null;

        if (\is_int($value) && $value > 0) {
            return $value;
        }

        return \is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** One boolean parameter with the provider's own default when it is absent. */
    public function booleanParam(string $name, bool $default): bool
    {
        $value = $this->params[$name] ?? null;

        return \is_bool($value) ? $value : $default;
    }
}
