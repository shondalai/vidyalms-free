<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\GradeDisplay;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\GradeDisplay\GradeDisplayInterface;

/**
 * The grade as a percentage of its maximum.
 *
 * The percentage is handed in rather than divided here, because the caller has
 * already worked out whether anything counted: a category where every cell was
 * empty has a value of zero and no fraction at all, and dividing by the
 * maximum would report that as a hard zero per cent. An empty category shows a
 * dash instead, which is the one case where a reader must be able to tell
 * "nothing yet" from "nothing right".
 */
final class PercentageGradeDisplay implements GradeDisplayInterface
{
    public const KEY = 'percentage';

    public const EMPTY = '-';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.grade_display.percentage');
    }

    public function format(float $value, float $maximum, ?float $percentage, int $decimals): string
    {
        if ($percentage === null) {
            return self::EMPTY;
        }

        return number_format($percentage, max($decimals, 0), '.', '') . '%';
    }
}
