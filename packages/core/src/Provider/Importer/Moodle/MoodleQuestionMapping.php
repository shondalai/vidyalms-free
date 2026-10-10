<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Moodle;

/**
 * What one Moodle question became: a VidyaLMS question type and definition,
 * or a refusal carrying the reason the importer prints.
 *
 * The reason travels with the mapping rather than being reconstructed by the
 * caller, because "the Moodle question type has no equivalent" and "the
 * question marks none of its answers correct" are different facts and an
 * admin reading the job log needs to know which one happened.
 */
final class MoodleQuestionMapping
{
    /**
     * @param array<string, mixed> $definition
     * @param string|null          $skipped    why this question was not imported
     * @param string|null          $note       what was lost in an import that did happen
     */
    private function __construct(
        public readonly string $type = '',
        public readonly array $definition = [],
        public readonly ?string $skipped = null,
        public readonly ?string $note = null,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function of(string $type, array $definition, ?string $note = null): self
    {
        return new self($type, \Shondalai\VidyaLMS\Core\Provider\Importer\QuestionText::definition($definition), null, $note);
    }

    public static function skip(string $reason): self
    {
        return new self('', [], $reason);
    }

    public function isSkipped(): bool
    {
        return $this->skipped !== null;
    }
}
