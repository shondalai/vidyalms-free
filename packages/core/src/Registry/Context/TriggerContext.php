<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * What an event trigger may read while turning a domain event into template
 * parameters: the clock, the people directory, a read-only database handle
 * for cross-context lookups, and the host facts the core cannot know.
 *
 * The database handle is here rather than injected into each provider,
 * because a third-party trigger reading its own package's tables would
 * otherwise need a constructor the registry cannot supply.
 */
final class TriggerContext
{
    /**
     * @param array<string, string> $hostFacts
     */
    private function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly UsersPort $users,
        public readonly DatabasePort $database,
        public readonly string $locale,
        public readonly array $hostFacts,
    ) {
    }

    /**
     * @param array<string, string> $hostFacts
     */
    public static function create(
        \DateTimeImmutable $now,
        UsersPort $users,
        DatabasePort $database,
        string $locale = 'en-GB',
        array $hostFacts = [],
    ): self {
        return new self($now, $users, $database, $locale, $hostFacts);
    }

    public function hostFact(string $key, string $default = ''): string
    {
        return $this->hostFacts[$key] ?? $default;
    }
}
