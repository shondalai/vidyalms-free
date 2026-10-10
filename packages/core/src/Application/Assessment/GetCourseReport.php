<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Report\OrgScopedReportInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * Any registered report over one course, resolved by its own key.
 *
 * **The registry was a proper extension point with exactly one way in.** The
 * only synchronous route hardcoded the item analysis key, so a second
 * registered report had no way to be read at all: adding one meant a new
 * core route, a new core handler and a new contract operation, which is the
 * shape ADR 0011 says none of these should have. A report is a registration,
 * and reading one is this.
 *
 * The capability comes from the provider rather than from here, because only
 * the report knows what it exposes: an item analysis is reports-or-teach, a
 * report over pending manual work would be grade, and the core has no
 * business deciding for a package it has never seen.
 *
 * **This is where the capability check lives, and it is not repeated inside the
 * provider.** `CourseOverviewReport` used to call `AccessGuard::require()`
 * itself, which is fine with one report and one capability and wrong the moment
 * a group leader exists: every provider then repeats both the capability check
 * and the scope filter, and the eleventh one forgets the second. A provider is
 * authorised here and handed an already-authorised scope; a provider that must
 * filter by scope has to be **given** the scope, not asked to look it up.
 *
 * A key nothing registers answers with the same placeholder shape a missing
 * provider always answers with, which is the registry miss contract applied
 * to a report: nothing on the contract is gated, and a route that failed
 * without a paid package would be a gate by another name.
 */
final class GetCourseReport
{
    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly CourseAccess $access,
        private readonly TranslatorPort $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function execute(Principal $principal, int $courseId, string $key, array $parameters = []): array
    {
        $page = $this->positive($parameters, 'page', 1);
        $perPage = $this->positive($parameters, 'per_page', 25);

        try {
            $provider = $this->reports->get($key);
        } catch (UnknownProviderException) {
            // Authorization still runs, on the widest reading of what a
            // report is: a caller with no claim on the course must not be
            // able to learn which report keys a site has by reading the
            // reasons apart.
            $this->access->require($principal, $courseId, Capability::VIEW_REPORTS, Capability::TEACH);

            return $this->placeholder($key, $page, $perPage);
        }

        if ($provider instanceof OrgScopedReportInterface) {
            // An organisation-only report has no meaning over one course and is
            // answered with the miss shape rather than a refusal, for the same
            // reason an unregistered key is: one answer for everything this
            // route will not serve.
            $this->access->require($principal, $courseId, Capability::VIEW_REPORTS, Capability::TEACH);

            return $this->placeholder($key, $page, $perPage);
        }

        $this->access->require($principal, $courseId, $provider->requiredCapability());

        if (
            !$provider instanceof \Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility
            && !$provider instanceof \Shondalai\VidyaLMS\Core\Registry\Report\CourseScopedReport
        ) {
            return $this->placeholder($key, $page, $perPage);
        }

        $report = $provider->generate(ReportContext::create(
            ['course_id' => $courseId] + $parameters,
            $principal,
            VisibilityScope::forCourse($courseId),
            $courseId,
        ));

        return $report + [
            'report' => $key,
            'computed' => true,
            'computed_by' => $provider->key(),
            'reason' => null,
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholder(string $key, int $page, int $perPage): array
    {
        return [
            'report' => $key,
            'computed' => false,
            'computed_by' => null,
            'reason' => $this->translator->translate('vidyalms.report.not_installed', ['report' => $key]),
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function positive(array $parameters, string $key, int $default): int
    {
        $value = $parameters[$key] ?? null;

        return \is_int($value) && $value > 0 ? $value : $default;
    }
}
