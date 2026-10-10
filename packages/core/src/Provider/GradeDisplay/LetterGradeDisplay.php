<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\GradeDisplay;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\GradeDisplay\GradeDisplayInterface;

/**
 * The grade as a letter, from a published band table.
 *
 * `grade_items.display_mode` has accepted `letter` since the gradebook shipped
 * and nothing has ever answered it: a site setting letters was handed the same
 * decimal a site setting `real` was. This is the provider that closes that,
 * and it is free rather than paid because a stored setting no build can honour
 * is a defect in the free build rather than an upsell.
 *
 * **The bands are a constant here and a registration elsewhere.** A site whose
 * scale is not this one registers its own provider under its own key rather
 * than configuring this one, because a configurable scale is a stored shape
 * with a version and an upgrader chain behind it, and the first thing that
 * shape has to answer is what a letter means when the bands change after the
 * grade was awarded. Until a design answers that, a fixed published scale is
 * the honest shape: a reader can see exactly which table produced their
 * letter.
 *
 * The bands below are the common eleven-grade scale with plus and minus, read
 * top down: the first band whose floor the percentage reaches wins. They are
 * percentages of the maximum, never raw marks, so a category out of 60 and one
 * out of 100 letter the same way.
 *
 * **A category where nothing counted has no letter.** It answers a dash, for
 * the reason the percentage provider does: "nothing yet" and "nothing right"
 * are different answers and an F for an unsubmitted assignment is the wrong
 * one.
 */
final class LetterGradeDisplay implements GradeDisplayInterface
{
    public const KEY = 'letter';

    public const EMPTY = '-';

    /**
     * Floor percentage to letter, highest first.
     *
     * @var list<array{0: float, 1: string}>
     */
    public const BANDS = [
        [93.0, 'A'],
        [90.0, 'A-'],
        [87.0, 'B+'],
        [83.0, 'B'],
        [80.0, 'B-'],
        [77.0, 'C+'],
        [73.0, 'C'],
        [70.0, 'C-'],
        [67.0, 'D+'],
        [60.0, 'D'],
        [0.0, 'F'],
    ];

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.grade_display.letter');
    }

    public function format(float $value, float $maximum, ?float $percentage, int $decimals): string
    {
        if ($percentage === null) {
            return self::EMPTY;
        }

        foreach (self::BANDS as [$floor, $letter]) {
            if ($percentage >= $floor) {
                return $letter;
            }
        }

        // Unreachable while the last band floors at zero, and kept because a
        // negative percentage is arithmetically possible on a category whose
        // minimum is above its grades.
        return self::EMPTY;
    }
}
