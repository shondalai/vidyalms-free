<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Field rules shared by CreateStimulus and UpdateStimulus.
 */
final class StimulusRules
{
    private const MAX_TITLE = 255;

    private function __construct()
    {
    }

    public static function validate(string $title): string
    {
        $trimmed = trim($title);

        if ($trimmed === '') {
            throw ValidationException::forField('title', 'A title is required.');
        }

        if (mb_strlen($trimmed) > self::MAX_TITLE) {
            throw ValidationException::forField(
                'title',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('The title must be at most %d characters.', self::MAX_TITLE),
            );
        }

        return $trimmed;
    }
}
