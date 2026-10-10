<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\GradeDisplay;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * How one grade is written down for a reader.
 *
 * **It is not an aggregator and it never computes.** `GradeAggregation` folds
 * cells into a number; this turns that number into the string a transcript
 * carries. Keeping them apart is what stops a display rule quietly changing a
 * total: everything here takes a value and a maximum and returns text, and has
 * nowhere to put a different number.
 *
 * The keys are the vocabulary `grade_items.display_mode` has stored since the
 * gradebook shipped, which is why this seam arrived after the column did. The
 * column was written, read back and rendered by nobody: a site could set
 * `letter` and be handed the same decimal as `real`, because the only reader
 * passed the string through to the client and no client shipped a scale.
 *
 * **Miss direction: visible fallback.** An unresolved display key answers the
 * raw number rather than an empty cell or an exception, and the reader says
 * which key was missing. A grade nobody can read is worse than a grade in the
 * wrong notation, and this is the one registry in the gradebook whose miss
 * cannot change a total, so falling back is safe here in a way it never is for
 * an aggregator.
 */
interface GradeDisplayInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The grade as text.
     *
     * @param float      $value      the grade, in the category's own units
     * @param float      $maximum    the maximum it is out of, which may be zero
     *                               when nothing declared one
     * @param float|null $percentage the same grade as a percentage, or null
     *                               when nothing counted and there is no
     *                               fraction to write down
     * @param int        $decimals   how many decimal places the item is
     *                               configured to show
     */
    public function format(float $value, float $maximum, ?float $percentage, int $decimals): string;
}
