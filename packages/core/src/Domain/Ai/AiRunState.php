<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * Every state an ai_runs row can be in.
 *
 * An unenumerated state is a stalled queue, which is why this is a closed enum
 * and not a string column with a convention around it. A run row is written for
 * every invocation including the refused and the previewed ones, because the
 * learner's right-to-know view and the dry run are both built out of rows that
 * never reached a model.
 *
 * The three predicates below are the ceiling reads, and each of them is a
 * decision rather than a filter:
 *
 * - countsTowardsSpend() includes Reserved and Running, because cost_charged is
 *   written only at a terminal state and a course build is dozens of calls from
 *   a binned outbox: a window that summed only the settled figure would let
 *   every concurrent unit pass on the same unsettled zero.
 * - countsTowardsRate() excludes Previewed and Refused, because a ceiling that
 *   counts previews charges a site owner for exercising the privacy control the
 *   design sells, and ten refusals for an unaccepted policy version would rate
 *   limit somebody for a condition they never caused.
 * - Previewed has its own generous window instead, so preview abuse is still
 *   bounded without borrowing the real budget.
 */
enum AiRunState: string
{
    /** Written before the bridge is called, inside the ceiling transaction. */
    case Reserved = 'reserved';

    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** A policy, a ceiling or a bridge declined. Not a breakage. */
    case Refused = 'refused';

    /** A dry run: the manifest was rendered and no bridge was called. */
    case Previewed = 'previewed';

    /** The Article 14 stop, which must not be filed as an ordinary failure. */
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this !== self::Reserved && $this !== self::Running;
    }

    /**
     * The billable states whose authoritative figure is the RESERVED one,
     * because they have not settled yet.
     *
     * @return list<string>
     */
    public static function holdingStates(): array
    {
        return array_values(array_map(
            static fn (self $state): string => $state->value,
            array_filter(
                self::cases(),
                static fn (self $state): bool => $state->countsTowardsSpend() && !$state->isTerminal(),
            ),
        ));
    }

    /**
     * The billable states whose authoritative figure is the CHARGED one,
     * because the run has settled and the spend is written on every terminal
     * path.
     *
     * @return list<string>
     */
    public static function settledStates(): array
    {
        return array_values(array_map(
            static fn (self $state): string => $state->value,
            array_filter(
                self::cases(),
                static fn (self $state): bool => $state->countsTowardsSpend() && $state->isTerminal(),
            ),
        ));
    }

    /**
     * Whether a run in this state is inside the windowed spend sum.
     *
     * @see per_scope_spend and per_scope_calls in the free policy set
     */
    public function countsTowardsSpend(): bool
    {
        return match ($this) {
            self::Reserved, self::Running, self::Succeeded, self::Failed => true,
            self::Refused, self::Previewed, self::Cancelled => false,
        };
    }

    /** Whether a run in this state is inside the per-principal rolling window. */
    public function countsTowardsRate(): bool
    {
        return $this->countsTowardsSpend();
    }

    /**
     * The states a windowed spend or rate query selects on, as strings.
     *
     * One list, because a reader enumerating them itself is a reader that will
     * be missing the next one.
     *
     * @return list<string>
     */
    public static function billableStates(): array
    {
        return array_values(array_map(
            static fn (self $state): string => $state->value,
            array_filter(self::cases(), static fn (self $state): bool => $state->countsTowardsSpend()),
        ));
    }
}
