<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * One entry of the content-addressed definition store: the authored
 * definition an attempt was graded from.
 *
 * A regrade needs a definition to grade against, and neither of the other
 * two candidates works. The learner projection carries no correctness by
 * construction, so it cannot be it. The live questions row can be rewritten
 * by a lazy upgrade, which would silently move a mark under a graded
 * attempt.
 *
 * Content addressing by hash gives one row per distinct definition however
 * many attempts reference it, which is also the deduplication the frozen
 * projection needs.
 */
final class StoredQuestionDefinition
{
    /** @var array<string, mixed> */
    public readonly array $definition;

    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        public readonly string $hash,
        public readonly string $type,
        public readonly int $definitionVersion,
        array $definition,
    ) {
        $this->definition = $definition;
    }

    /**
     * The content address. Keys are sorted before encoding so two equal
     * definitions written in a different key order share one row, which is
     * the whole point of addressing by content.
     *
     * @param array<string, mixed> $definition
     */
    public static function hashOf(string $type, int $definitionVersion, array $definition): string
    {
        return hash('sha256', $type . "\0" . $definitionVersion . "\0" . self::canonicalise($definition));
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function of(string $type, int $definitionVersion, array $definition): self
    {
        return new self(self::hashOf($type, $definitionVersion, $definition), $type, $definitionVersion, $definition);
    }

    /**
     * @param array<string, mixed>|list<mixed> $value
     */
    private static function canonicalise(array $value): string
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ':' . (
                \is_array($item) ? self::canonicalise($item) : json_encode($item, JSON_THROW_ON_ERROR)
            );
        }

        return '{' . implode(',', $parts) . '}';
    }
}
