<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

use Shondalai\VidyaLMS\Core\Registry\Context\BridgeContext;

/**
 * A bridge whose real capabilities depend on the deployed counterparty rather
 * than only on the class.
 *
 * **Absent means** the instanceof probe is the whole answer, which is the
 * right default: a class that implements a capability has it.
 *
 * The design describes this as a bridge "overriding supports()" without
 * naming a home for the method, and a method on the seam is exactly what the
 * seam forbids. So it is a capability like every other, and its one job is to
 * **downgrade**: a bridge may answer false for a capability it declares, from
 * a cached description of the remote it is pointed at, and it may never
 * answer true for one it does not.
 */
interface NegotiatesCapabilities
{
    /**
     * @param class-string $capability one of the capability interfaces in this namespace
     */
    public function supports(string $capability, BridgeContext $context): bool;
}
