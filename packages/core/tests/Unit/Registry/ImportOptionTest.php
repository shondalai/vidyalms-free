<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;

/**
 * An import option declaration must describe a control a host can actually
 * draw. Everything asserted here is a shape the Import screens would render as
 * something broken and silent: a select with nothing in it, a preselected
 * value that is not on the list, or a control for the one option name the
 * pipeline decides itself.
 */
final class ImportOptionTest extends TestCase
{
    public function testATextOptionCarriesItsLabelAndHelp(): void
    {
        $option = new ImportOption('course_key', 'Course key', ImportOption::KIND_TEXT, 'The identity.');

        self::assertSame('course_key', $option->key);
        self::assertSame(ImportOption::KIND_TEXT, $option->kind);
        self::assertSame([
            'key' => 'course_key',
            'label' => 'Course key',
            'kind' => 'text',
            'help' => 'The identity.',
            'default' => '',
            'choices' => [],
        ], $option->toArray());
    }

    public function testABooleanOptionRecordsItsDefault(): void
    {
        self::assertSame('1', ImportOption::boolean('enrolments', 'Enrolments', '', true)->default);
        self::assertSame('', ImportOption::boolean('enrolments', 'Enrolments')->default);
    }

    public function testAChoiceOptionKeepsItsChoicesInOrder(): void
    {
        $option = ImportOption::choice(
            'profile',
            'Profile',
            [
                ['value' => 'strict', 'label' => 'Strict'],
                ['value' => 'extended', 'label' => 'Extended'],
            ],
            'What a foreign engine will run.',
            'strict',
        );

        self::assertSame(ImportOption::KIND_CHOICE, $option->kind);
        self::assertSame('strict', $option->default);
        self::assertSame(['strict', 'extended'], array_column($option->choices, 'value'));
    }

    public function testAKeyThatIsNotAWireNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ImportOption('Course Key', 'Course key');
    }

    public function testThePipelinesOwnOptionCannotBeDeclared(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ImportOption(ImportOption::RESERVED_KEY, 'Dry run', ImportOption::KIND_BOOLEAN);
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ImportOption('profile', 'Profile', 'radio');
    }

    public function testAChoiceWithNothingToChooseFromIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImportOption::choice('profile', 'Profile', []);
    }

    public function testChoicesOnSomethingThatIsNotAChoiceAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ImportOption(
            'profile',
            'Profile',
            ImportOption::KIND_TEXT,
            '',
            '',
            [['value' => 'strict', 'label' => 'Strict']],
        );
    }

    public function testABooleanIsAlwaysAnsweredTrueOrFalse(): void
    {
        $declared = [ImportOption::boolean('enrolments', 'Enrolments')];

        // An unchecked checkbox posts nothing, and the importer reads
        // ($options['enrolments'] ?? false) === true, which a string "1" would
        // silently fail.
        self::assertSame(['enrolments' => false], ImportOption::read($declared, []));
        self::assertSame(['enrolments' => true], ImportOption::read($declared, ['enrolments' => '1']));
        self::assertSame(['enrolments' => false], ImportOption::read($declared, ['enrolments' => '0']));
        self::assertSame(['enrolments' => false], ImportOption::read($declared, ['enrolments' => '']));
    }

    public function testAnEmptyTextFieldLeavesTheImportersOwnDefaultStanding(): void
    {
        $declared = [new ImportOption('table_prefix', 'Prefix', ImportOption::KIND_TEXT, '', 'cq_')];

        self::assertSame([], ImportOption::read($declared, ['table_prefix' => '   ']));
        self::assertSame(['table_prefix' => 'jos_cq_'], ImportOption::read($declared, ['table_prefix' => ' jos_cq_ ']));
    }

    public function testANumberThatIsNotOneIsDropped(): void
    {
        $declared = [new ImportOption('max_bytes', 'Bytes', ImportOption::KIND_NUMBER)];

        self::assertSame(['max_bytes' => 1024], ImportOption::read($declared, ['max_bytes' => '1024']));
        self::assertSame([], ImportOption::read($declared, ['max_bytes' => '1024mb']));
    }

    public function testAChoiceOutsideTheDeclaredValuesIsDropped(): void
    {
        $declared = [
            ImportOption::choice('profile', 'Profile', [
                ['value' => 'strict', 'label' => 'Strict'],
                ['value' => 'extended', 'label' => 'Extended'],
            ]),
        ];

        self::assertSame(['profile' => 'extended'], ImportOption::read($declared, ['profile' => 'extended']));
        self::assertSame([], ImportOption::read($declared, ['profile' => 'whatever-the-caller-liked']));
    }

    public function testAnUndeclaredScalarStillReachesTheImporter(): void
    {
        // An importer may read an option it chose not to draw, and a script
        // that sets one has to keep working.
        self::assertSame(['publish' => '1'], ImportOption::read([], ['publish' => '1']));
        self::assertSame([], ImportOption::read([], ['nested' => ['no']]));
    }

    public function testThePipelinesOwnOptionNeverSurvivesTheForm(): void
    {
        self::assertSame([], ImportOption::read([], [ImportOption::RESERVED_KEY => '1']));
    }

    public function testADefaultOutsideTheChoicesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImportOption::choice(
            'profile',
            'Profile',
            [['value' => 'strict', 'label' => 'Strict']],
            '',
            'extended',
        );
    }
}
