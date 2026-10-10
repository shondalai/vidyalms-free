<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * Where a task appears to a person, and in which panels.
 *
 * **Absent means** ungrouped, last, and offered in no placement.
 *
 * **placements() is the authoritative binding between a task and a placement,
 * and it is the only one.** An earlier draft declared the same relation three
 * ways, from the placement through taskKeys(), from the placement through
 * offersFor() and from the task through placements(), with no precedence
 * stated and with free placements naming Pro task keys: adding the eighteenth
 * paid task or any third-party task to the shipped question bank panel would
 * then have been an edit to a free provider in the free bundle. taskKeys() is
 * deleted, the placement holds a predicate, and the binding lives here.
 */
interface TaskMetadataInterface
{
    /** The picker heading this task sits under. */
    public function group(): string;

    /** Sort position within that heading, low first. */
    public function order(): int;

    /**
     * The placement keys this task asks to be offered in. A placement whose
     * predicate refuses the descriptor still refuses it.
     *
     * @return list<string>
     */
    public function placements(): array;
}
