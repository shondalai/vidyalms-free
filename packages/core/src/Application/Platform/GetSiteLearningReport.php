<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Provider\Report\SiteLearningReport;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;

/**
 * The stable alias behind `GET /manage/reports/overview`.
 *
 * The arithmetic moved into {@see SiteLearningReport}, which is a registered
 * report definition reachable by its own key like every other report. What is
 * left here is what a use case is for: authorise, resolve the caller's reach
 * into a {@see VisibilityScope}, and hand the provider an already-authorised
 * context.
 *
 * **The alias is kept deliberately and is not a deprecation.** The operations
 * screen calls this operation through the generated SDK, the contract has
 * published its response shape since before the registry route existed, and
 * `/manage/reports/overview` is registered strictly **before**
 * `/manage/reports/{key}` because route matching is first-registration-wins
 * with no literal-over-parameter precedence. Reordering those two registrations
 * would send every overview request into the registry with the key "overview",
 * which nothing registers, and answer the miss shape instead of the report.
 *
 * It resolves through the registry rather than holding the provider, so a site
 * that replaced `site-learning` with a decorated version gets the decorated
 * one here too. A build with nothing registered under the key is a broken boot
 * rather than a miss, so this is the one report caller that does not carry the
 * miss contract: the alias promises a shape the contract declares, and
 * answering `computed: false` there would be a 200 that no consumer of this
 * operation can read.
 */
final class GetSiteLearningReport
{
    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly AccessGuard $guard,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(
        Principal $principal,
        int $page = 1,
        int $perPage = 25,
        string $search = '',
        ?string $state = null,
    ): array {
        $this->guard->require($principal, Capability::VIEW_REPORTS);

        return $this->reports->get(SiteLearningReport::KEY)->generate(ReportContext::create(
            array_filter([
                'page' => $page,
                'per_page' => $perPage,
                'search' => $search,
                'state' => $state,
            ], static fn (mixed $value): bool => $value !== null),
            $principal,
            self::scopeFor($this->guard, $principal),
        ));
    }

    /**
     * How far this caller may see, decided once by the thing that authorised
     * them.
     *
     * A site manager sees every course. Anybody else holding the reports
     * capability sees the courses they are assigned to, expressed as a
     * user-typed scope rather than as a list of course ids: the list would cost
     * a second query and an unbounded `IN`, and the provider turns the same
     * scope back into the `course_instructors` join the shipped code already
     * used.
     */
    public static function scopeFor(AccessGuard $guard, Principal $principal): VisibilityScope
    {
        $siteWide = $guard->can($principal, Capability::MANAGE) || $guard->can($principal, Capability::ADMIN);

        return $siteWide ? VisibilityScope::site() : VisibilityScope::forUser($principal->userId);
    }
}
