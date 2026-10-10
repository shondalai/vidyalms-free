<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

/**
 * The dry run, which is the privacy artefact and costs nothing.
 *
 * For an importer a dry run counts what it would create and writes nothing. For
 * an AI run it does more, and the extra is nearly free because the redaction
 * stage already runs in front of every call: it resolves the grounding set,
 * renders the exact redacted payload and the field manifest the active policy
 * permits, computes the inputs digest, estimates the cost, writes the run row in
 * state previewed with the manifest attached, and calls no bridge.
 *
 * That gives a site owner an inspectable, per-run answer to "what exactly would
 * you send about my learners" before a token is spent. No peer product ships it:
 * the nearest published position is prose saying only limited course information
 * leaves the tenant, and prose is not a manifest.
 *
 * It is a separate use case rather than a flag on StartAiRun because the two are
 * authorised the same way and counted differently. Previews get their own
 * generous rate window rather than borrowing the real budget, because charging a
 * person for exercising the privacy control the design sells would be exactly
 * backwards, and ten refusals for an unaccepted policy version would otherwise
 * rate limit somebody for a condition they never caused.
 */
final class EstimateAiRun
{
    public function __construct(
        private readonly AiRunPipeline $pipeline,
        private readonly AiAccess $access,
    ) {
    }

    /**
     * Runs every stage up to and including the reservation, and stops.
     *
     * The request is forced into a dry run here rather than trusted from the
     * caller, so an estimate route can never be talked into spending money by a
     * payload that set the flag the other way.
     */
    public function execute(AiRunRequest $request): AiRunReport
    {
        $this->access->requireAuthoring($request->principal, $request->courseId);

        return $this->pipeline->run(AiRunRequest::create(
            $request->principal,
            $request->taskKey,
            $request->task,
            $request->placementKey,
            $request->scopeType,
            $request->scopeId,
            $request->courseId,
            $request->orgScope,
            $request->onBehalfOfUserId,
            true,
            $request->locale,
            $request->jobId,
            $request->actorKind,
            $request->ruleId,
            $request->options,
        ));
    }
}
