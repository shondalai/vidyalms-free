<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatExpiryDisposition;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatExpiryOutcome;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What the term sweep does to a seat whose pool has ended.
 *
 * The free shape lapses access and keeps progress, because a learner's own
 * record is never a licensing artefact.
 *
 * Miss direction: normal path: it falls back to lapsing access and keeping progress, and records the substitution on the pool event.
 *
 * The fall-back is the reason this returns a value object rather than acting:
 * the sweep can record which disposition it actually applied, so a pool whose
 * Business provider has been uninstalled says so on its own event rather than
 * quietly behaving like a different pool.
 */
interface SeatExpiryDispositionInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /** What a still-held seat gets when the pool's term ends. */
    public function disposition(SeatDecisionContext $context): SeatExpiryOutcome;
}
