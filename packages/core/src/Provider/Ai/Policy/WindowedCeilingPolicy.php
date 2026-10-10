<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Policy;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\EvaluatedInsideTheReservation;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * The four windowed ceilings, which are one shape with four configurations.
 *
 * **They defer rather than deny**, and that is the reason the decision object
 * is the notification one taken unchanged. A period ceiling that has been
 * reached has a real answer a deny-set cannot express: not now, at the window
 * boundary. Quiet hours is why defer exists there and a monthly lot resetting
 * on the first is the identical shape here.
 *
 * Three corrections are built in, each the difference between a ceiling and a
 * number on a screen.
 *
 * **A ceiling that reads only the settled figure does not bind under
 * fan-out.** The charged figure is written at the terminal state and a course
 * build is dozens of calls from a binned outbox with two runners over one
 * pending row as the normal case, so every concurrent unit would sum the same
 * unsettled total and every one would pass. The window therefore takes the
 * greater of charged and reserved and includes the rows that have not
 * settled.
 *
 * **A ceiling that counts previews charges a site owner for exercising the
 * privacy control the design sells.** A run row is written for every run
 * including refused and previewed ones, so without a state predicate a
 * manifest inspection and a policy refusal both spend the person's rate
 * budget. Previews get their own generous window instead.
 *
 * **A ceiling denominated in a unit the bridge does not report never fires**,
 * which is why two of these four count rows rather than money.
 */
final class WindowedCeilingPolicy implements AiPolicyInterface, EvaluatedInsideTheReservation
{
    public const KEY_SCOPE_SPEND = 'per_scope_spend';
    public const KEY_SCOPE_CALLS = 'per_scope_calls';
    public const KEY_PRINCIPAL_RATE = 'per_principal_rate';
    public const KEY_PRINCIPAL_PREVIEW_RATE = 'per_principal_preview_rate';

    public const MEASURE_SPEND = 'spend';
    public const MEASURE_COUNT = 'count';

    /**
     * The states a windowed spend or rate reads.
     *
     * `previewed`, `refused` and `cancelled` are excluded on purpose:
     * previewing costs nothing and is the privacy artefact, a refusal is a
     * condition the person did not cause, and a cancelled run is the exercise
     * of a stop control rather than a use of the service.
     */
    public const LIVE_STATES = ['reserved', 'running', 'succeeded', 'failed'];

    /** The one state the preview budget reads, and the only one. */
    public const PREVIEW_STATES = ['previewed'];

    /** @var list<string> */
    private readonly array $states;

    /**
     * @param list<string> $states
     */
    private function __construct(
        private readonly DatabasePort $database,
        private readonly string $key,
        private readonly string $measure,
        private readonly string $setting,
        private readonly float $default,
        private readonly string $window,
        private readonly bool $perPrincipal,
        array $states,
    ) {
        $this->states = $states;
    }

    /** A windowed sum of money or tokens across one scope. */
    public static function perScopeSpend(DatabasePort $database): self
    {
        return new self(
            $database,
            self::KEY_SCOPE_SPEND,
            self::MEASURE_SPEND,
            'ceiling.per_scope_spend',
            100000.0,
            'P30D',
            false,
            self::LIVE_STATES,
        );
    }

    /** The same window as a count, so a non-metering bridge is still bounded. */
    public static function perScopeCalls(DatabasePort $database): self
    {
        return new self(
            $database,
            self::KEY_SCOPE_CALLS,
            self::MEASURE_COUNT,
            'ceiling.per_scope_calls',
            2000.0,
            'P30D',
            false,
            self::LIVE_STATES,
        );
    }

    /**
     * A **rolling** window per person, and the roll is a named departure.
     *
     * The precedent this replaces is a fixed one-hour cache window, and a
     * fixed window lets a caller spend two windows' worth across a boundary.
     * The two-level structure, per person and per site, is kept because it is
     * right; the window is replaced.
     */
    public static function perPrincipalRate(DatabasePort $database): self
    {
        return new self(
            $database,
            self::KEY_PRINCIPAL_RATE,
            self::MEASURE_COUNT,
            'ceiling.per_principal_rate',
            60.0,
            'PT1H',
            true,
            self::LIVE_STATES,
        );
    }

    /** A separate, generous window so preview abuse is bounded without borrowing the real budget. */
    public static function perPrincipalPreviewRate(DatabasePort $database): self
    {
        return new self(
            $database,
            self::KEY_PRINCIPAL_PREVIEW_RATE,
            self::MEASURE_COUNT,
            'ceiling.per_principal_preview_rate',
            600.0,
            'PT1H',
            true,
            self::PREVIEW_STATES,
        );
    }

    /**
     * @return list<self>
     */
    public static function all(DatabasePort $database): array
    {
        return [
            self::perScopeSpend($database),
            self::perScopeCalls($database),
            self::perPrincipalRate($database),
            self::perPrincipalPreviewRate($database),
        ];
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'ai.policy.' . $this->key . '.label';
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        $configured = $context->setting($this->setting, $this->default);
        $cap = \is_numeric($configured) ? (float) $configured : $this->default;

        if ($cap <= 0.0) {
            return PolicyDecision::defer([PolicyDecision::TARGET_AI_RUN], $this->boundary($context->now));
        }

        $used = $this->measure === self::MEASURE_SPEND
            ? $this->spend($intent, $context)
            : (float) $this->count($intent, $context);

        return $used >= $cap
            ? PolicyDecision::defer([PolicyDecision::TARGET_AI_RUN], $this->boundary($context->now))
            : PolicyDecision::allow();
    }

    /**
     * The greater of charged and reserved, per row, summed in PHP.
     *
     * The fold is done here rather than in SQL because the fluent query API
     * has no two-column maximum and adding one for a single caller would put
     * an expression language in the port. The window bounds the row count, so
     * this reads a page of numbers rather than a table.
     */
    private function spend(AiRunIntent $intent, AiDecisionContext $context): float
    {
        $rows = $this->database->selectAll(
            $this->window($intent, $context)->select(['cost_charged', 'cost_reserved']),
        );

        $total = 0.0;

        foreach ($rows as $row) {
            $charged = \is_numeric($row['cost_charged'] ?? null) ? (float) $row['cost_charged'] : 0.0;
            $reserved = \is_numeric($row['cost_reserved'] ?? null) ? (float) $row['cost_reserved'] : 0.0;
            $total += max($charged, $reserved);
        }

        return $total;
    }

    private function count(AiRunIntent $intent, AiDecisionContext $context): int
    {
        return $this->database->count($this->window($intent, $context)->select(['id']));
    }

    private function window(AiRunIntent $intent, AiDecisionContext $context): Query
    {
        $since = $context->now->sub(new \DateInterval($this->window));

        $query = Query::from('ai_runs')
            ->whereIn('state', $this->states)
            ->where('started_at', '>=', $since->format('Y-m-d H:i:s'));

        if ($this->perPrincipal) {
            return $query->where('principal_user_id', '=', $context->principal->userId);
        }

        if ($context->scopeType === 'course' && $context->scopeId !== null) {
            return $query->where('course_id', '=', $context->scopeId);
        }

        if ($context->scopeType === 'organisation' && $context->scopeId !== null) {
            return $query->where('organisation_id', '=', $context->scopeId);
        }

        return $query;
    }

    /**
     * When the window next has room, which is what a defer has to answer.
     *
     * A rolling window's honest boundary is one window from now: the oldest
     * row inside it could be read instead, but that read would be a second
     * query answering a question the caller only needs approximately, and a
     * deferral that lands slightly late is a deferral, while one that lands
     * slightly early is a second refusal.
     */
    private function boundary(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->add(new \DateInterval($this->window));
    }
}
