<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunReservation;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;

/**
 * What the two entry paths hand the pipeline.
 *
 * StartAiRun builds one from a person's click and AiJobRunner builds one per
 * unit of a queued build, which is precisely why it exists: the pipeline has to
 * be reachable identically from both, or the nine invariants hold on one path
 * and not the other.
 *
 * The organisation is carried as an OrgScope on the interactive path and never
 * as a bare id. A scope is minted only after the resolver's check, so a run
 * raised inside an organisation carries the authorisation that raised it, and
 * the per-organisation reads narrow on the scope rather than on a number a
 * caller supplied. A site run carries no scope at all, which is the site and not
 * a missing tenant.
 *
 * The drain is the one exception, and it is a narrow one. A build accepted
 * inside an organisation days ago has no session to re-derive a scope from, and
 * OrgScope may be minted only by its own witnessed factories, so the drain
 * carries the job's own recorded organisation column instead. It stamps the run
 * rows and narrows the reads; it does not stand in for the authorisation, which
 * RunQueuedAiJob re-resolved before this object was built.
 */
final class AiRunRequest
{
    /**
     * @param array<string, mixed> $options
     */
    private function __construct(
        public readonly Principal $principal,
        public readonly string $taskKey,
        public readonly AiTaskRequest $task,
        public readonly ?string $placementKey,
        public readonly string $scopeType,
        public readonly ?int $scopeId,
        public readonly ?int $courseId,
        public readonly ?OrgScope $orgScope,
        public readonly ?int $onBehalfOfUserId,
        public readonly bool $dryRun,
        public readonly string $locale,
        public readonly ?int $jobId,
        public readonly string $actorKind,
        public readonly ?int $ruleId,
        public readonly array $options,
        /**
         * The organisation a QUEUED build recorded when it was accepted.
         *
         * It is set only by the drain, through recordedTenant() below, and it
         * exists because a job accepted inside an organisation days ago has no
         * session to re-derive a scope from, while OrgScope may be minted only
         * by its own witnessed factories and a runner is not one of them. The
         * id still stamps every run row the build writes, so the
         * per-organisation reads narrow correctly; what it does not do is
         * pretend to be an authorisation, because the authorisation happened at
         * RunQueuedAiJob before this object was built.
         */
        public readonly ?int $recordedOrganisationId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function create(
        Principal $principal,
        string $taskKey,
        AiTaskRequest $task,
        ?string $placementKey = null,
        string $scopeType = 'site',
        ?int $scopeId = null,
        ?int $courseId = null,
        ?OrgScope $orgScope = null,
        ?int $onBehalfOfUserId = null,
        bool $dryRun = false,
        string $locale = 'en-GB',
        ?int $jobId = null,
        string $actorKind = AiRunReservation::ACTOR_PERSON,
        ?int $ruleId = null,
        array $options = [],
    ): self {
        return new self(
            $principal,
            $taskKey,
            $task,
            $placementKey,
            $scopeType,
            $scopeId,
            $courseId,
            $orgScope,
            $onBehalfOfUserId,
            $dryRun,
            $locale,
            $jobId,
            $actorKind,
            $ruleId,
            $options,
            null,
        );
    }

    /**
     * The organisation this run belongs to, read from the scope and from nowhere
     * else, so an id in a caller's payload can never become a tenant.
     */
    public function organisationId(): ?int
    {
        return $this->orgScope->organisationId ?? $this->recordedOrganisationId;
    }

    /**
     * The same request, stamped with the tenant a queued job recorded.
     *
     * Only the drain calls this, and it calls it with the job's own column
     * rather than with anything from the caller's payload: an organisation read
     * out of a payload is an administrator raising a build against somebody
     * else's tenant.
     */
    public function recordedTenant(?int $organisationId): self
    {
        return new self(
            $this->principal,
            $this->taskKey,
            $this->task,
            $this->placementKey,
            $this->scopeType,
            $this->scopeId,
            $this->courseId,
            $this->orgScope,
            $this->onBehalfOfUserId,
            $this->dryRun,
            $this->locale,
            $this->jobId,
            $this->actorKind,
            $this->ruleId,
            $this->options,
            $organisationId,
        );
    }

    /** The same request, bound to the job that is draining it. */
    public function inJob(int $jobId): self
    {
        return new self(
            $this->principal,
            $this->taskKey,
            $this->task,
            $this->placementKey,
            $this->scopeType,
            $this->scopeId,
            $this->courseId,
            $this->orgScope,
            $this->onBehalfOfUserId,
            $this->dryRun,
            $this->locale,
            $jobId,
            $this->actorKind,
            $this->ruleId,
            $this->options,
            $this->recordedOrganisationId,
        );
    }

    /** The same request over a different subject, which is what a recipe step fans out into. */
    public function forSubject(AiTaskRequest $task, string $taskKey): self
    {
        return new self(
            $this->principal,
            $taskKey,
            $task,
            $this->placementKey,
            $this->scopeType,
            $this->scopeId,
            $this->courseId,
            $this->orgScope,
            $this->onBehalfOfUserId,
            $this->dryRun,
            $this->locale,
            $this->jobId,
            $this->actorKind,
            $this->ruleId,
            $this->options,
            $this->recordedOrganisationId,
        );
    }
}
