<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Application\Ai\AiEnablementState;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunState;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PlacementEnabledPolicy;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;

/**
 * Builds the context a policy decides in, including the windowed spend it needs.
 *
 * It is a collaborator rather than a method on the stage because the windowed
 * read is the expensive half and the part a test wants to substitute, and
 * because the ceilings and the reservation both have to read the same window: a
 * report and a ceiling that computed the spend differently would disagree about
 * whether a site was over budget.
 *
 * The window is the rolling one the design names, and it is rolling rather than
 * fixed for the reason it gives: a fixed one-hour cache window lets a caller
 * spend two windows' worth across a boundary.
 */
final class AiDecisionContextFactory
{
    /** The default rolling window, in days, for a scope spend ceiling. */
    public const DEFAULT_WINDOW_DAYS = 30;

    /**
     * @param AiEnablementState|null $enablement the four levels and the stop, read
     *                                          through the one object that owns
     *                                          them. Optional so a caller with no
     *                                          settings store still constructs;
     *                                          absent, every switch answers its
     *                                          default, which is off, and the
     *                                          policies refuse. That is the
     *                                          direction a gate has to fail in.
     */
    public function __construct(
        private readonly AiRunRepository $runs,
        private readonly ?AiEnablementState $enablement = null,
        private readonly int $windowDays = self::DEFAULT_WINDOW_DAYS,
    ) {
    }

    public function for(AiPipelineRun $run): AiDecisionContext
    {
        $since = $run->now->modify('-' . max(1, $this->windowDays) . ' days');
        $unit = $run->reserved->unit;

        $spent = CostRecord::of(
            $unit,
            $this->runs->spentInWindow($unit, $since, ...$this->scopeOf($run)),
        );

        return AiDecisionContext::create(
            $run->now,
            $run->request->principal,
            $run->request->scopeType,
            $run->request->scopeId,
            $spent,
            0,
            $this->resolver($run),
            // Carried rather than re-derived: a run raised inside an
            // organisation decides under the scope that authorised it, and the
            // per-organisation ceilings narrow on that scope rather than on a
            // number a caller supplied.
            $run->request->orgScope,
        );
    }

    /**
     * The scoped settings lookup a policy reads its own configuration through.
     *
     * It is a closure over AiEnablementState rather than a handle to the
     * settings table, so a third-party policy reads scoped params through the one
     * shipped resolution order without being handed the table. Three keys are
     * answered from the enablement object because they are the four levels and
     * the stop; everything else is a per-provider cap, read from the scoped
     * ceilings row at organisation scope and then at site scope, and a cap
     * nobody configured answers the default the policy itself declares.
     */
    private function resolver(AiPipelineRun $run): \Closure
    {
        $enablement = $this->enablement;
        $organisationId = $run->request->organisationId();

        return static function (string $key, mixed $default = null) use ($enablement, $organisationId): mixed {
            if ($enablement === null) {
                return $default;
            }

            if ($key === PlacementEnabledPolicy::SETTING_STOP) {
                return $enablement->stopped();
            }

            if ($key === PlacementEnabledPolicy::SETTING_SITE_ENABLED) {
                return $enablement->siteEnabled();
            }

            if (str_starts_with($key, PlacementEnabledPolicy::SETTING_PLACEMENT_PREFIX)) {
                return $enablement->placementEnabled(
                    substr($key, \strlen(PlacementEnabledPolicy::SETTING_PLACEMENT_PREFIX)),
                );
            }

            // Everything else is a per-provider cap, and it falls through to
            // the scoped settings read rather than to the caller's own
            // default. Returning the default here pinned all six shipped
            // ceilings to their constants for ever, and a vendor policy
            // declaring its own cap had nowhere to read it from.
            return $enablement->ceiling($key, $default, $organisationId);
        };
    }

    /**
     * How many runs this principal has raised inside the rolling window, which is
     * the unit-free count that keeps a non-metering bridge bounded.
     */
    public function callsInWindow(AiPipelineRun $run): int
    {
        return $this->runs->countInWindow(
            $run->now->modify('-' . max(1, $this->windowDays) . ' days'),
            AiRunState::billableStates(),
            'principal_user_id',
            $run->request->principal->userId,
        );
    }

    /**
     * The scope column a ceiling narrows on, chosen from the run rather than
     * from a caller's string.
     *
     * An organisation run narrows on the organisation, because that is the
     * tenant paying; a course run narrows on the course; a site run narrows on
     * nothing, which is the site and not a missing tenant.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function scopeOf(AiPipelineRun $run): array
    {
        $organisationId = $run->request->organisationId();

        if ($organisationId !== null) {
            return ['organisation_id', $organisationId];
        }

        if ($run->request->courseId !== null) {
            return ['course_id', $run->request->courseId];
        }

        return [null, null];
    }
}
