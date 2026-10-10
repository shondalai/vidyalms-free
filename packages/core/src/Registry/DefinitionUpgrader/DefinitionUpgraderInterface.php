<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader;

use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One step of a definition's version chain.
 *
 * Upgraders compose by version, so a type that has moved from 1 to 3 ships
 * two of them and a row stored at 1 runs both. A third party evolves its own
 * format without a core release, and a definition whose upgrader is gone is
 * never mutated and never thrown on: it is read as stored and rendered by
 * the placeholder.
 *
 * The upgrade rule, stated once so it is checkable: an upgrader rewrites
 * only the newest questions row for a bank entry. It never rewrites a
 * version an attempt_questions row references, and because every attempted
 * question is referenced by one, that is a rule about which row is rewritten
 * rather than a promise that nothing is ever rewritten.
 */
interface DefinitionUpgraderInterface extends ProviderInterface
{
    /**
     * The registry key: "<typeKey>:<fromVersion>:<toVersion>". Built by
     * DefinitionUpgraderRegistry::keyFor() so no provider composes it by
     * hand and two never disagree.
     */
    public function key(): string;

    public function typeKey(): string;

    public function fromVersion(): int;

    public function toVersion(): int;

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function upgrade(array $definition, UpgradeContext $context): array;
}
