<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Pro;

use Shondalai\VidyaLMS\Core\Http\Presenter\CurriculumPresenter;
use Shondalai\VidyaLMS\Core\Http\Presenter\ShapeDecorator;

/**
 * The first thing Pro needs: one extra field on the course row.
 *
 * Before the presenter registry this was impossible without editing a free
 * build file, because the presenter was a final class of public static
 * methods called by name from forty-two handlers.
 */
final class SeatsOnCourses implements ShapeDecorator
{
    public function supports(string $shape): bool
    {
        return $shape === CurriculumPresenter::SHAPE_MANAGED_COURSE;
    }

    public function decorate(array $emitted, object $source): array
    {
        return $emitted + ['seats_used' => 7];
    }
}
