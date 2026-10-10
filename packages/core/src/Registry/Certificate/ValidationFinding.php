<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

/**
 * What one element said about its own payload at save time.
 *
 * A key plus parameters rather than a sentence, because the core owns no
 * locale, and two levels rather than one, because a missing alt text refuses
 * the save while a font size nobody can read is worth saying and not worth
 * blocking.
 */
final class ValidationFinding
{
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';

    /** @var array<string, scalar|null> */
    public readonly array $parameters;

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $messageKey,
        array $parameters,
    ) {
        $this->parameters = $parameters;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function error(string $code, string $messageKey, array $parameters = []): self
    {
        return new self(self::LEVEL_ERROR, $code, $messageKey, $parameters);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function warning(string $code, string $messageKey, array $parameters = []): self
    {
        return new self(self::LEVEL_WARNING, $code, $messageKey, $parameters);
    }

    public function blocks(): bool
    {
        return $this->level === self::LEVEL_ERROR;
    }
}
