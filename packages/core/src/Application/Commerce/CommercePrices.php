<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Commerce;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Domain\Commerce\Money;
use Shondalai\VidyaLMS\Core\Port\Commerce\CommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\ContextAwareCommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\PriceOption;
use Shondalai\VidyaLMS\Core\Port\Commerce\PriceView;
use Shondalai\VidyaLMS\Core\Registry\Commerce\BatchPricesCourses;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\Commerce\ContextAwareCommerceSource;
use Shondalai\VidyaLMS\Core\Registry\Context\CommerceContext;

/**
 * Asks a commerce source, or the host's shop bridge, for a price view.
 *
 * One place probes the two capability interfaces so no call site does, which
 * is the whole reason a capability is an interface rather than a convention:
 * the fallback is written once, its provisional marking is written once, and
 * the N+1 the batch capability exists to remove is logged in one place rather
 * than discovered on a slow catalogue page.
 *
 * **The fallback is not a failure.** A source or a bridge that has not
 * implemented the context-taking ask still answers a price; that price simply
 * carries no resolved jurisdiction and is therefore marked provisional, which
 * the storefront states. That is the honest half of the free-build claim, and
 * saying it here rather than promising the finished state is deliberate.
 */
final class CommercePrices
{
    /**
     * The source a course that names none falls back to.
     *
     * The same key courses.commerce_source defaults to. It is repeated from
     * GetPlatformSettings rather than imported from it because a use case
     * that reads the settings screen is not a dependency a site model should
     * acquire to price a course.
     */
    public const DEFAULT_SOURCE = 'cms-shop';

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Which registered source answers for a course: the course's own, then
     * the shipped default, then nothing.
     *
     * Published as a static so the two host adapters and the API handler run
     * one rule rather than four copies of a hardcoded key. Before this, the
     * literal 'cms-shop' appeared in GetCourseHandler, the Joomla CourseModel
     * and CatalogueModel, and the WordPress Templates class, which meant a
     * site whose courses named a registered second source was priced by the
     * first one on its public pages and by the right one nowhere.
     *
     * Null is the answer a caller turns into a null price and a translated
     * reason. It happens in exactly one case worth naming: the course names a
     * source that is not registered on this site (its package was removed or
     * was never installed) and the shipped default is not registered either.
     * Silently pricing such a course through whichever source happens to be
     * present would show a buyer a number from a shop that is not selling it.
     *
     * @param ?string $named the course's own commerce_source, or null when
     *                       the caller has no course row to read it from
     */
    public static function resolveSourceKey(CommerceSourceRegistry $sources, ?string $named): ?string
    {
        if ($named !== null && $named !== '' && $sources->has($named)) {
            return $named;
        }

        // The fallback is for a course that names nothing of its own, which is
        // every row written before the column existed and every row a site
        // owner has never touched: both hold the column default. It is
        // deliberately NOT for a course that names some other source, because
        // a course pinned to a shop whose package has been removed must not be
        // priced by whichever shop happens to still be installed. That is the
        // miss the registry publishes as closed and visible, and pricing it
        // from another seller's catalogue is the one answer worse than none.
        if (
            ($named === null || $named === '' || $named === self::DEFAULT_SOURCE)
            && $sources->has(self::DEFAULT_SOURCE)
        ) {
            return self::DEFAULT_SOURCE;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $course
     */
    public function forCourse(CommerceSourceInterface $source, array $course, CommerceContext $context): ?PriceView
    {
        if ($source instanceof ContextAwareCommerceSource) {
            return $source->priceViewFor($course, $context);
        }

        $price = $source->priceFor($course);

        if ($price === null) {
            return null;
        }

        return new PriceView(
            new PriceOption(
                'headline',
                new Money($price->amountMinor, $price->currency),
                purchaseUrl: $source->purchaseUrlFor($course),
                provisional: true,
            ),
            sourceKey: $source->key(),
        );
    }

    /**
     * @param list<array<string, mixed>> $courses each carrying at least an int id
     *
     * @return array<int, PriceView> keyed by course id
     */
    public function forCourses(CommerceSourceInterface $source, array $courses, CommerceContext $context): array
    {
        if ($source instanceof BatchPricesCourses) {
            return $source->priceViewsFor($courses, $context);
        }

        // The N+1 the batch capability exists to remove. It is logged rather
        // than hidden, because a slow catalogue page nobody can see the cause
        // of is worse than a slow one with a line naming the source.
        $this->logger?->info('Commerce source cannot batch prices; the catalogue is looping.', [
            'source' => $source->key(),
            'courses' => \count($courses),
        ]);

        $views = [];

        foreach ($courses as $course) {
            $id = isset($course['id']) && is_numeric($course['id']) ? (int) $course['id'] : 0;

            if ($id < 1) {
                continue;
            }

            $view = $this->forCourse($source, $course, $context);

            if ($view !== null) {
                $views[$id] = $view;
            }
        }

        return $views;
    }

    /** The same probe on the host bridge rather than on a registered source. */
    public function forCourseThroughPort(CommercePort $commerce, int $courseId, CommerceContext $context): ?PriceView
    {
        if ($commerce instanceof ContextAwareCommercePort) {
            return $commerce->priceViewForCourse($courseId, $context);
        }

        $price = $commerce->priceForCourse($courseId);

        if ($price === null) {
            return null;
        }

        return new PriceView(
            new PriceOption(
                'headline',
                new Money($price->amountMinor, $price->currency),
                purchaseUrl: $commerce->purchaseUrlForCourse($courseId),
                provisional: true,
            ),
        );
    }

    /**
     * @param list<int> $courseIds
     *
     * @return array<int, PriceView>
     */
    public function forCoursesThroughPort(CommercePort $commerce, array $courseIds, CommerceContext $context): array
    {
        if ($commerce instanceof ContextAwareCommercePort) {
            return $commerce->priceViewsForCourses($courseIds, $context);
        }

        $views = [];

        foreach ($courseIds as $courseId) {
            $view = $this->forCourseThroughPort($commerce, $courseId, $context);

            if ($view !== null) {
                $views[$courseId] = $view;
            }
        }

        return $views;
    }
}
