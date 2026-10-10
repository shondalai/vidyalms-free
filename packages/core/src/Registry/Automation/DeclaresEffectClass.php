<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * The action says what kind of thing it changes.
 *
 * Absent means **unclassified**, and an unclassified action must declare
 * RequiresApprovalBeforeEffect or the rule naming it is refused at save. That
 * is the safe direction and it is the whole point of the capability: the core
 * cannot know whether `acme.unlock_module` gates content, so an action that
 * will not say either classifies itself or accepts a person in the loop.
 *
 * The refusal that used to run turned on seven English words appearing inside
 * the action key, so it fired on `grant_availability_probe` and not on
 * `enrol`, and any non-English or differently worded third-party key walked
 * past it. The words are kept only as a redundant net over an action that
 * declares nothing at all; they are no longer the authority for whether the
 * check runs.
 */
interface DeclaresEffectClass
{
    public function effectClass(): AutomationEffectClass;
}
