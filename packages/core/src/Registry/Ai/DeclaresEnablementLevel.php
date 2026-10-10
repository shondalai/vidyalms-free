<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A policy that publishes itself as a level of enablement on the admin
 * screen.
 *
 * The four levels were a hardcoded list naming the three free policy keys,
 * while the placement toggles below them were generated from the registry. A
 * site owner running a vendor gate that denies therefore saw four levels all
 * green and every run refused, with nothing on the screen naming the gate
 * that refused it: the one place the design's own "generated from the
 * registries, so a new helper arrives with its own toggle" argument was not
 * applied.
 *
 * So a policy that is a level says so, and the screen is the union of what
 * the registered policies publish. A policy that declares nothing is a
 * ceiling or a scope rule rather than a level, which is what the six ceilings
 * and the two scope gates are.
 */
interface DeclaresEnablementLevel
{
    /**
     * The levels this policy is the gate for, in the order a run meets them.
     *
     * A policy may publish more than one: the shipped placement policy is
     * both the site switch and the per-placement switch, and separating them
     * into two providers would give one stored row two owners.
     *
     * @return list<array{level: string, setting: string, stored_in: string}>
     */
    public function enablementLevels(): array;
}
