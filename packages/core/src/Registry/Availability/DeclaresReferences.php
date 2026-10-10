<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;

/**
 * The entities a leaf names. Default when absent: the leaf names no entity
 * and needs no remap.
 *
 * The save pass writes these into availability_rule_refs, which is what makes
 * "every rule that references lesson 88" an indexed query on one table across
 * all three owner types, and remapReferences() is what makes a course copy
 * repoint a leaf instead of leaving it aimed at the original course. A leaf
 * that cannot resolve its referent evaluates to BLOCKED and never to
 * AVAILABLE.
 */
interface DeclaresReferences
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return list<GateSubject>
     */
    public function dependsOn(array $payload): array;

    /**
     * @param array<string, mixed>           $payload
     * @param array<string, array<int, int>> $map old id to new id, keyed by reference type
     *
     * @return array<string, mixed>
     */
    public function remapReferences(array $payload, array $map): array;
}
