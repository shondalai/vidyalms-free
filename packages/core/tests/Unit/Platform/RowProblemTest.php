<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The row level error model. Every test here is about the difference between
 * a report an operator can act on and a sentence they cannot.
 */
final class RowProblemTest extends TestCase
{
    public function testTheCodeVocabularyIsTheEightThatArePublished(): void
    {
        self::assertSame(
            [
                'unknown_org',
                'unmapped_role',
                'duplicate_key',
                'missing_required',
                'invalid_email',
                'seat_pool_exhausted',
                'referential_miss',
                'forbidden_scope',
            ],
            RowProblem::CODES,
        );
    }

    public function testACodeNobodyPublishesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"nearly_right" is none of them');

        RowProblem::of('users.csv', 4, 'email', 'nearly_right', 'No.');
    }

    public function testAnOutcomeNobodyPublishesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RowProblem::of('users.csv', 4, 'email', RowProblem::CODE_INVALID_EMAIL, 'No.', '', 'deleted');
    }

    public function testAValidationFailureExpandsIntoTheRowReportTheWayItExpandsIntoTheJobLog(): void
    {
        $failure = ValidationException::forField('email', 'An address is required.');

        $problem = RowProblem::fromFailure(
            'users.csv',
            9,
            'email',
            RowProblem::CODE_MISSING_REQUIRED,
            $failure,
        );

        self::assertSame('email: An address is required.', $problem->message);
        self::assertSame('Validation failed.', $failure->getMessage(), 'Which is why the row may not use it.');
    }

    public function testRowNumbersAreOneBasedAndIncludeTheHeader(): void
    {
        self::assertSame(2, RowProblem::FIRST_DATA_ROW);
        self::assertSame(1, RowProblem::of('users.csv', 0, null, RowProblem::CODE_DUPLICATE_KEY, 'x')->row);
    }

    public function testAValueIsNeutralisedOnceSoASpreadsheetDoesNotRunIt(): void
    {
        $problem = RowProblem::of(
            'users.csv',
            7,
            'given_name',
            RowProblem::CODE_INVALID_EMAIL,
            'Not an address.',
            '=cmd|\' /c calc\'!A1',
        );

        self::assertSame(CellSafetyPolicy::PREFIX . '=cmd|\' /c calc\'!A1', $problem->value);
        self::assertFalse(
            str_starts_with(substr($problem->value, 1), CellSafetyPolicy::PREFIX),
            'Applied once here, and never again downstream.',
        );
    }

    public function testAFullWidthOperatorIsNeutralisedToo(): void
    {
        $problem = RowProblem::of('u.csv', 3, null, RowProblem::CODE_MISSING_REQUIRED, 'x', "\u{FF1D}1+1");

        self::assertSame(CellSafetyPolicy::PREFIX . "\u{FF1D}1+1", $problem->value);
    }

    public function testAValueIsBoundedByTheNoteExcerptLimit(): void
    {
        $problem = RowProblem::of('u.csv', 3, null, RowProblem::CODE_MISSING_REQUIRED, 'x', str_repeat('a', 400));

        self::assertLessThanOrEqual(ImportNote::EXCERPT_LIMIT, \strlen($problem->value));
    }

    public function testAValueHoldingALineBreakCannotForgeASecondEntry(): void
    {
        $problem = RowProblem::of('u.csv', 3, null, RowProblem::CODE_MISSING_REQUIRED, 'x', "Tea\nrow 4");

        self::assertStringNotContainsString("\n", $problem->value);
    }

    public function testARowThatWasWrittenAndWarnedAboutIsNotARejectedRow(): void
    {
        $written = RowProblem::of('u.csv', 3, 'role_key', RowProblem::CODE_UNMAPPED_ROLE, 'x', '', 'updated');
        $rejected = RowProblem::of('u.csv', 4, 'role_key', RowProblem::CODE_UNMAPPED_ROLE, 'x', '', 'skipped');

        self::assertFalse($written->wasRejected());
        self::assertTrue($rejected->wasRejected());
    }

    public function testTheStoredShapeRoundTrips(): void
    {
        $problem = RowProblem::of(
            'enrollments.csv',
            12,
            'classSourcedId',
            RowProblem::CODE_REFERENTIAL_MISS,
            'No class carries sourcedId "c-9".',
            'c-9',
            RowProblem::OUTCOME_SKIPPED,
        );

        self::assertEquals($problem, RowProblem::fromArray($problem->toArray()));
    }

    public function testAStoredRowWithACodeThisBuildDoesNotKnowStillRenders(): void
    {
        $problem = RowProblem::fromArray([
            'file' => 'users.csv',
            'row' => 5,
            'column' => '',
            'code' => 'from_a_later_build',
            'message' => 'x',
            'value' => '',
            'outcome' => 'sideways',
        ]);

        self::assertSame(RowProblem::CODE_MISSING_REQUIRED, $problem->code);
        self::assertSame(RowProblem::OUTCOME_FAILED, $problem->outcome);
        self::assertNull($problem->column);
    }

    public function testOneLineNamesTheFileTheRowAndTheColumn(): void
    {
        $problem = RowProblem::of('users.csv', 9, 'email', RowProblem::CODE_INVALID_EMAIL, 'Not an address.');

        self::assertSame('users.csv row 9, column email [invalid_email] Not an address.', $problem->line());
    }

    public function testASingleFileImportDoesNotNameAFile(): void
    {
        $problem = RowProblem::of('', 9, null, RowProblem::CODE_INVALID_EMAIL, 'Not an address.');

        self::assertSame('row 9 [invalid_email] Not an address.', $problem->line());
    }

    /**
     * One derivation for the row a composed source key names.
     *
     * WordPressCourseImporter::rowOf took the first run of digits and
     * SourceRun::rowOf took the last, so "course:12/40" reported 12 on one host
     * family and 40 on the other, for the same object. A composed key ends with
     * the object's own identity, so the last run is the answer.
     */
    public function testARowIsDerivedFromTheEndOfAComposedKey(): void
    {
        self::assertSame(40, RowProblem::rowFromKey('course:12/40'));
        self::assertSame(41, RowProblem::rowFromKey('lesson:41'));
        self::assertSame(8, RowProblem::rowFromKey('module:course:8'));
        self::assertSame(
            1,
            RowProblem::rowFromKey('no-digits-at-all'),
            'Which reads as "this file, no particular line".',
        );
    }

    /**
     * The one-based-including-the-header rule, enforced for a real file.
     *
     * of() is deliberately tolerant, because a database source has no header
     * and reports the source object's own id. inFile() is where the rule is a
     * rule: it used to be stated and never checked, so of() folded row 0 up to
     * 1, which is the header line, and an importer counting its data rows from
     * zero sent the operator to the wrong line for every row in the file.
     */
    public function testAFileRowBelowTheFirstDataRowIsRefusedRatherThanFoldedUp(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RowProblem::inFile('users.csv', 0, 'email', RowProblem::CODE_INVALID_EMAIL, 'Not an address.');
    }

    public function testAFileRowAtOrAboveTheFirstDataRowIsAccepted(): void
    {
        $problem = RowProblem::inFile(
            'users.csv',
            RowProblem::FIRST_DATA_ROW,
            'email',
            RowProblem::CODE_INVALID_EMAIL,
            'Not an address.',
        );

        self::assertSame(2, $problem->row);
    }
}
