<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * How a reserved run ended.
 *
 * **The spend travels with the outcome on every terminal path, including the
 * failed one.** That is the one rule the import pipeline does not have, and it
 * is here because money changes it: an import that fails costs nothing, and an
 * AI job that fails has already paid the upstream tokens. A job log saying only
 * "failed" leaves a site owner unable to explain their bill.
 *
 * The token counts and the cost amount are all nullable and stay null when the
 * bridge does not declare ReportsUsage, because a usage report saying a site
 * spent nothing is worse than one saying it does not know.
 */
final class AiRunOutcome
{
    private function __construct(
        public readonly AiRunState $state,
        public readonly CostRecord $cost,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly ?bool $schemaValid,
        public readonly ?string $refusalKind,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?string $model,
        public readonly ?string $promptRef,
        public readonly ?string $providerRequestId,
        public readonly ?string $providerFingerprint,
        public readonly ?string $finishReason,
        public readonly ?int $durationMs,
        public readonly bool $unbilled,
    ) {
    }

    public static function succeeded(
        CostRecord $cost,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?bool $schemaValid = null,
        ?string $model = null,
        ?string $promptRef = null,
        ?string $providerRequestId = null,
        ?string $providerFingerprint = null,
        ?string $finishReason = null,
        ?int $durationMs = null,
        bool $unbilled = false,
    ): self {
        return new self(
            AiRunState::Succeeded,
            $cost,
            $tokensIn,
            $tokensOut,
            $schemaValid,
            null,
            null,
            null,
            $model,
            $promptRef,
            $providerRequestId,
            $providerFingerprint,
            $finishReason,
            $durationMs,
            $unbilled,
        );
    }

    /**
     * A run that broke. The cost is required rather than defaulted, so a caller
     * has to say what it spent before it failed rather than forgetting to.
     */
    public static function failed(
        CostRecord $cost,
        string $errorCode,
        string $errorMessage,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?int $durationMs = null,
    ): self {
        return new self(
            AiRunState::Failed,
            $cost,
            $tokensIn,
            $tokensOut,
            null,
            null,
            $errorCode,
            $errorMessage,
            null,
            null,
            null,
            null,
            null,
            $durationMs,
            false,
        );
    }

    /**
     * A typed decline: a policy, a ceiling, a missing route or a bridge that
     * said no. Not a breakage, and it does not enter the spend window.
     */
    public static function refused(string $refusalKind, ?string $message = null, ?CostRecord $cost = null): self
    {
        return new self(
            AiRunState::Refused,
            $cost ?? CostRecord::none(),
            null,
            null,
            null,
            $refusalKind,
            null,
            $message,
            null,
            null,
            null,
            null,
            null,
            null,
            false,
        );
    }

    /** A dry run. The manifest is on the reservation and no bridge was called. */
    public static function previewed(): self
    {
        return new self(
            AiRunState::Previewed,
            CostRecord::none(),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            false,
        );
    }

    /**
     * The Article 14 stop, observed by a run in flight.
     *
     * It carries the spend, because a build stopped at item forty has already
     * paid for forty items.
     */
    public static function cancelled(string $reason, ?CostRecord $cost = null): self
    {
        return new self(
            AiRunState::Cancelled,
            $cost ?? CostRecord::none(),
            null,
            null,
            null,
            null,
            null,
            $reason,
            null,
            null,
            null,
            null,
            null,
            null,
            false,
        );
    }
}
