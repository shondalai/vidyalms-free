<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionVersionAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;

/**
 * One key, two implementations, so a test can decorate it through replace()
 * exactly as a paid package does and then take the decoration away again.
 *
 * Version 1 keeps its accepted answers under "accepted"; version 2 renames
 * that field to "matchers" and upgrades a stored version 1 body on read. That
 * is the smallest honest reproduction of the decoration hazard: the key
 * always resolves, so nothing throws, and only the version the body actually
 * carries can tell the two shapes apart.
 */
final class VersionedProbeType implements QuestionTypeInterface, DefinitionVersionAwareInterface
{
    public const KEY = 'x_acme_probe';

    private function __construct(
        private readonly int $version,
        private readonly string $field,
    ) {
    }

    public static function v1(): self
    {
        return new self(1, 'accepted');
    }

    public static function v2(): self
    {
        return new self(2, 'matchers');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Probe';
    }

    public function definitionVersion(): int
    {
        return $this->version;
    }

    public function supportedDefinitionVersions(): array
    {
        return [$this->version];
    }

    public function upgradeDefinition(array $definition, int $fromVersion): array
    {
        if ($this->version < 2 || $fromVersion >= 2) {
            return $definition;
        }

        $accepted = $definition['accepted'] ?? [];
        unset($definition['accepted']);
        $definition['matchers'] = $accepted;

        return $definition;
    }

    public function validateDefinition(array $definition): void
    {
    }

    public function presentForLearner(array $definition): array
    {
        return ['prompt' => $definition['prompt'] ?? ''];
    }

    public function grade(array $definition, mixed $answer): GradeResult
    {
        $accepted = $definition[$this->field] ?? [];

        if (!\is_array($accepted) || !\is_string($answer)) {
            return GradeResult::incorrect();
        }

        return \in_array($answer, $accepted, true) ? GradeResult::correct() : GradeResult::incorrect();
    }
}
