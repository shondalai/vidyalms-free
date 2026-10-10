<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatScope;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What one seat in a pool buys.
 *
 * A course today, a programme or a whole catalogue later. The two questions a
 * pool asks are separate on purpose: whether this scope covers the course
 * somebody is being seated on, and how many seats one assignment of it
 * spends. A provider that answers the second with anything but one is what
 * makes a catalogue seat different from a course seat, and assuming one is
 * how a pool goes silently over its purchased count.
 *
 * Miss direction: fail closed at the pool: no new assignment is written, and the pool names the provider.
 *
 * The context carries the pool row, the decoded scope_params and their stored
 * shape version, so a provider reads its own configuration and never the
 * schema.
 */
interface SeatScopeInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * Whether a seat from this pool opens the course being assigned.
     *
     * False is a refusal and not a fall-back: a pool whose scope does not
     * cover the course is a pool nobody may spend on it.
     */
    public function covers(SeatDecisionContext $context): bool;

    /**
     * How many seats one assignment spends.
     *
     * One for a course and for a programme consumed as a unit, which is
     * IOMAD's documented rule for a program licence. A provider returning
     * less than one is refused by the use case rather than trusted.
     */
    public function arity(SeatDecisionContext $context): int;
}
