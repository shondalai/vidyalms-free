<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\GradeDisplay;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\GradeDisplay\GradeDisplayInterface;

/**
 * The number itself, at the item's own precision.
 *
 * The default `grade_items.display_mode` has been `real` since the gradebook
 * shipped, so this is the provider that makes the stored default mean
 * something. It formats with a point and no thousands separator deliberately:
 * a grade crossing an API is a machine-readable string, and localising the
 * separator here would make the same grade parse differently in two locales.
 * Presentation formatting belongs to the client that knows the reader's
 * locale.
 */
final class RealGradeDisplay implements GradeDisplayInterface
{
    public const KEY = 'real';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.grade_display.real');
    }

    public function format(float $value, float $maximum, ?float $percentage, int $decimals): string
    {
        return number_format($value, max($decimals, 0), '.', '');
    }
}
