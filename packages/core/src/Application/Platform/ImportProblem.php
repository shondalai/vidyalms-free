<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Renders a failure as the one sentence an import note may quote.
 *
 * It exists because ValidationException carries the reason in its errors map
 * while getMessage() is the generic "Validation failed.". A skip note built
 * from getMessage() tells an operator that something was rejected and nothing
 * about what to fix, which is the same as telling them nothing. Every catch
 * site in the import stage goes through here, so a log line always names the
 * field and the problem.
 */
final class ImportProblem
{
    private function __construct()
    {
    }

    public static function describe(\Throwable $failure): string
    {
        $message = trim($failure->getMessage());

        if (!$failure instanceof ValidationException || $failure->errors === []) {
            return $message === '' ? $failure::class : $message;
        }

        $pairs = [];

        foreach ($failure->errors as $field => $problem) {
            $pairs[] = sprintf('%s: %s', $field, $problem);
        }

        $expanded = implode(' ', $pairs);

        // A caller that wrote its own message meant it, so it is kept in front
        // of the fields; the generic one is replaced rather than repeated.
        return $message === '' || $message === ValidationException::GENERIC_MESSAGE
            ? $expanded
            : $message . ' ' . $expanded;
    }
}
