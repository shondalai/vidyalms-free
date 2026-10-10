<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * A named person releases this step BEFORE its effect runs.
 *
 * Absent means **the effect runs on its own**, with no approval preceding it.
 *
 * This is one half of a capability that was doing two different jobs. For a
 * grant of availability the parked object is a proposed change and nothing is
 * granted until a person releases it: approval precedes the effect. For an AI
 * draft the parked object exists only after the model has already been called,
 * the budget already spent and the learner data already sent: approval follows
 * the effect. Both declared the same thing, so the capability told a reviewer
 * nothing about which.
 *
 * Any action that could publish, mark, gate or issue must declare this, and a
 * rule naming one that does not is refused at save.
 */
interface RequiresApprovalBeforeEffect
{
    /**
     * Why a person is being asked, as a language key the review screen
     * renders. It is a method rather than a marker because "approve this"
     * with no statement of what is being approved is the drift this design
     * spends a section refusing.
     */
    public function approvalReasonKey(): string;
}
