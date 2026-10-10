<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Commerce;

use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Resolves a course price and purchase link from the host shop.
 * The shop owns checkout and financial records; VidyaLMS owns learning access.
 */
interface CommerceSourceInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * @param array<string, mixed> $course
     */
    public function priceFor(array $course): ?CoursePrice;

    /**
     * Where the buy button points, or null when this source does not sell
     * the given course.
     *
     * @param array<string, mixed> $course
     */
    public function purchaseUrlFor(array $course): ?string;
}
