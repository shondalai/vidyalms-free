<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportProblem;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The one sentence an import note is allowed to quote. Everything here is
 * about the difference between telling an operator that something was refused
 * and telling them what to fix.
 */
final class ImportProblemTest extends TestCase
{
    public function testAValidationFailureIsRenderedAsItsFieldsAndProblems(): void
    {
        $failure = ValidationException::forField('title', 'A non-empty title of at most 255 characters is required.');

        self::assertSame(
            'title: A non-empty title of at most 255 characters is required.',
            ImportProblem::describe($failure),
        );
        self::assertSame('Validation failed.', $failure->getMessage(), 'Which is why the note may not use it.');
    }

    public function testEveryFieldOfAValidationFailureIsNamed(): void
    {
        $failure = new ValidationException([
            'title' => 'A title is required.',
            'content' => 'The content must be an object.',
        ]);

        self::assertSame(
            'title: A title is required. content: The content must be an object.',
            ImportProblem::describe($failure),
        );
    }

    public function testAValidationFailureWithAMessageOfItsOwnKeepsIt(): void
    {
        $failure = new ValidationException(['slug' => 'Already taken.'], 'The course could not be saved.');

        self::assertSame('The course could not be saved. slug: Already taken.', ImportProblem::describe($failure));
    }

    public function testAnyOtherFailureIsItsOwnMessage(): void
    {
        self::assertSame(
            'That module is locked.',
            ImportProblem::describe(new ConflictException('That module is locked.')),
        );
        self::assertSame('The archive is empty.', ImportProblem::describe(new \RuntimeException('The archive is empty.')));
    }

    public function testAFailureWithNoMessageAtAllIsNamedByItsClass(): void
    {
        self::assertSame(\RuntimeException::class, ImportProblem::describe(new \RuntimeException()));
    }
}
