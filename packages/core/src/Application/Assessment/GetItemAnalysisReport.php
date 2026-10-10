<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * Facility, discrimination and distractor rates per question version.
 *
 * **The inputs are free and the computation is a provider.** Every build
 * records what was served and at which version (attempt_questions), the raw
 * chosen option identifiers (attempt_answers), the per-try history
 * (attempt_answer_events) and the raw, minimum and maximum scores with the
 * policy that produced them (attempt_grades), because a later release cannot
 * retroactively populate a column that was never written and statistics over
 * attempts taken before a package was installed would otherwise be dead on
 * arrival.
 *
 * A site with no registered provider therefore answers 200 with computed
 * false, an empty item list and a reason, and the screen renders its
 * placeholder rather than an error. That is the registry miss contract
 * applied to a report: nothing on the contract is gated, and a route that
 * failed without a paid package would be a gate by another name.
 */
final class GetItemAnalysisReport
{
    public const PROVIDER_KEY = 'item_analysis';

    /**
     * 30 responses per version is where the discrimination index stops being
     * noise. The literature cites anywhere from 30 to 100, and random draws
     * make per-item n a fraction of cohort size, so it is a starting point
     * the caller may override rather than a constant.
     */
    public const DEFAULT_MIN_RESPONSES = 30;

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
    public function execute(Principal $principal, int $courseId, array $parameters = []): array
    {
        $this->access->require($principal, $courseId, Capability::VIEW_REPORTS, Capability::TEACH);

        $minResponses = $this->minResponses($parameters);
        $page = $this->positive($parameters, 'page', 1);
        $perPage = $this->positive($parameters, 'per_page', 25);

        try {
            $provider = $this->reports->get(self::PROVIDER_KEY);
        } catch (UnknownProviderException) {
            return $this->placeholder($minResponses, $page, $perPage);
        }

        $report = $provider->generate(ReportContext::create(
            ['course_id' => $courseId, 'min_responses' => $minResponses] + $parameters,
            $principal,
            VisibilityScope::forCourse($courseId),
            $courseId,
        ));

        return $report + [
            'computed' => true,
            'computed_by' => $provider->key(),
            'reason' => null,
            'min_responses' => $minResponses,
            'internal_consistency' => null,
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholder(int $minResponses, int $page, int $perPage): array
    {
        return [
            'computed' => false,
            'computed_by' => null,
            'reason' => $this->translator->translate('vidyalms.report.item_analysis.not_computed'),
            'min_responses' => $minResponses,
            'internal_consistency' => null,
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function minResponses(array $parameters): int
    {
        $value = $parameters['min_responses'] ?? null;

        return \is_int($value) && $value > 0 ? $value : self::DEFAULT_MIN_RESPONSES;
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
