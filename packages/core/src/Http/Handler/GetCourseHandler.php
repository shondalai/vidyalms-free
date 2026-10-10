<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Commerce\CommercePrices;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCourseBySlug;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\CommerceContext;

/**
 * GET /courses/{slug}
 *
 * The price and the buy link come from the commerce source the course names,
 * resolved through the registry rather than from a hardcoded key. That is the
 * same fix courses.enrolment_rule already made for self enrolment and for the
 * same reason: with a constant here, no second commerce source was reachable
 * at all from this route, so a native or third-party checkout could register
 * and never answer a price.
 *
 * The resolution is has() guarded, and the miss is a normal path rather than
 * an exception: a course pinned to a source whose package has been removed
 * answers a null price and a translated reason. The direction is closed and
 * visible, which is the published one for this registry, because a miss that
 * rendered a paid course as free gives the content away.
 */
final class GetCourseHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetCourseBySlug $getCourseBySlug,
        private readonly CommerceSourceRegistry $commerceSources,
        private readonly ?TranslatorPort $translator = null,
        private readonly ?CommercePrices $prices = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $course = $this->getCourseBySlug->execute($request->principal, $request->pathParam('slug'));

        return ApiResponse::ok(
            Presenter::courseDetail($course) + $this->pricing($course, $request),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(Course $course, ApiRequest $request): array
    {
        if ($course->priceMode === 'free') {
            return ['price' => null, 'purchase_url' => null, 'price_reason' => null];
        }

        $key = $this->resolveSourceKey($course);

        if ($key === null) {
            // Closed and visible: the course is not purchasable and the
            // storefront says why, naming the source the course was pinned
            // to rather than reporting a free course.
            return [
                'price' => null,
                'purchase_url' => null,
                'price_reason' => $this->reason($course->commerceSource),
            ];
        }

        $source = $this->commerceSources->get($key);
        $courseRow = ['id' => $course->id, 'price_mode' => $course->priceMode];
        $view = ($this->prices ?? new CommercePrices())->forCourse($source, $courseRow, $this->context($request));

        if ($view === null) {
            return ['price' => null, 'purchase_url' => null, 'price_reason' => null];
        }

        $option = $view->headline;

        return [
            'price' => [
                'amount_minor' => $option->amount->minorUnits,
                'currency' => $option->amount->currency,
                'formatted' => null,
                'tax' => $option->tax->value,
                'provisional' => $option->provisional,
                // What the source actually priced against, which is what the
                // storefront's "Prices shown for <country>, change" control
                // reads. A guess the buyer cannot correct is worse than no
                // guess, and a buyer cannot correct one they cannot see.
                'resolved_country' => $view->resolvedCountry,
                'resolved_subdivision' => $view->resolvedSubdivision,
            ],
            'purchase_url' => $option->purchaseUrl,
            'price_reason' => null,
        ];
    }

    /**
     * The course's own source, then the shipped default, then nothing.
     *
     * The fallback exists because commerce_source is defaulted rather than
     * nullable, so a row written before the column existed and a row a site
     * owner has never touched both name cms-shop, and a build that registers
     * no source at all is the only case that reaches null.
     */
    private function resolveSourceKey(Course $course): ?string
    {
        // The rule itself lives on CommercePrices, because the two host
        // adapters' public catalogue and course pages have to run the same
        // one: four copies of a hardcoded key is what made a site with a
        // registered second source price its API answer correctly and its
        // public pages wrongly.
        return CommercePrices::resolveSourceKey($this->commerceSources, $course->commerceSource);
    }

    private function reason(string $missingKey): string
    {
        $key = 'vidyalms.commerce.source_missing';

        return $this->translator?->translate($key, ['source' => $missingKey]) ?? $key;
    }

    /**
     * The jurisdiction the ask carries, read off the request.
     *
     * `ContextAwareCommerceSource` exists so one catalogue can render
     * inclusive in the EU and the UK and exclusive in the US, and it was
     * unreachable over HTTP: this method built a context with no country, no
     * currency, no locale and no buyer kind, so the same source that answers a
     * settled gross price when handed a DE context answered a provisional one
     * on every public page, and the design's visible "Prices shown for
     * <country>, change" control had nothing to change.
     *
     * Everything here is a **request-supplied preference and never a claim**.
     * A country typed by a buyer is exactly what the design says the public
     * page offers, and it is not evidence: the place-of-supply evidence a
     * filing needs is captured at purchase time on the entitlement, from the
     * address and the caller's address hash, and nothing here reaches that.
     * A malformed value is dropped rather than refused, because a course page
     * that 422s on a stray query parameter is a course page a crawler breaks.
     */
    private function context(ApiRequest $request): CommerceContext
    {
        return CommerceContext::create(
            $request->principal,
            $request->principal->userId,
            self::locale($request),
            self::pattern($request, 'currency', '/^[A-Z]{3}$/D'),
            self::pattern($request, 'country', '/^[A-Z]{2}$/D'),
            self::pattern($request, 'subdivision', '/^[A-Za-z0-9-]{1,10}$/D'),
            $request->queryString('buyer_kind') === CommerceContext::BUYER_BUSINESS
                ? CommerceContext::BUYER_BUSINESS
                : CommerceContext::BUYER_CONSUMER,
        );
    }

    /** An uppercased query value, or null when it is absent or malformed. */
    private static function pattern(ApiRequest $request, string $name, string $pattern): ?string
    {
        $value = strtoupper(trim($request->queryString($name)));

        return $value !== '' && preg_match($pattern, $value) === 1 ? $value : null;
    }

    private static function locale(ApiRequest $request): string
    {
        $value = trim($request->queryString('locale'));

        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/D', $value) === 1 ? $value : 'en-GB';
    }
}
