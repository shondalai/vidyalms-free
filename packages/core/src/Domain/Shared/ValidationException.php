<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * Invalid input, with per-field messages the API can return as a 422.
 */
final class ValidationException extends DomainException
{
    /**
     * What getMessage() says when the caller gave no message of its own. It
     * names no field and no problem, so anything writing a log line reads
     * errors instead: ImportProblem::describe() is how the import stage does
     * it.
     */
    public const GENERIC_MESSAGE = 'Validation failed.';

    /**
     * @param array<string, string> $errors field name to message
     */
    public function __construct(
        public readonly array $errors,
        string $message = self::GENERIC_MESSAGE,
    ) {
        parent::__construct($message);
    }

    public static function forField(string $field, string $problem): self
    {
        return new self([$field => $problem]);
    }
}
