<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * What is written to the ledger before anything is spent.
 *
 * It is a separate object from AiRun because the two shapes are genuinely
 * different: a reservation is what the pipeline knows before the call, and a run
 * is what a reader gets back afterwards. Passing a half-populated AiRun into the
 * write path would have made every nullable outcome column look optional at
 * reservation time, which is how a reservation ends up written without an
 * inputs digest.
 *
 * The reservation and the ceiling evaluation happen inside one
 * DatabasePort::transactional() call, so two concurrent runs cannot both read
 * the same headroom.
 */
final class AiRunReservation
{
    public const ACTOR_PERSON = 'person';
    public const ACTOR_AUTOMATION = 'automation';

    /** @var array<string, mixed>|null */
    public readonly ?array $redactionManifest;

    /**
     * @param array<string, mixed>|null $redactionManifest the fields the active
     *                                                     policy permits, as the
     *                                                     dry run and the
     *                                                     right-to-know view
     *                                                     render them
     */
    private function __construct(
        public readonly string $runKey,
        public readonly string $taskKey,
        public readonly int $taskVersion,
        public readonly ?int $jobId,
        public readonly ?string $placementKey,
        public readonly ?int $routeId,
        public readonly string $bridgeKey,
        public readonly ?string $model,
        public readonly int $policyVersion,
        public readonly ?int $principalUserId,
        public readonly string $actorKind,
        public readonly ?int $ruleId,
        public readonly ?int $onBehalfOfUserId,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?int $courseId,
        public readonly ?int $organisationId,
        public readonly string $locale,
        public readonly string $inputsDigest,
        ?array $redactionManifest,
        public readonly int $redactionManifestVersion,
        public readonly bool $dryRun,
        public readonly string $costUnit,
        public readonly ?float $costReserved,
        public readonly \DateTimeImmutable $retainUntil,
    ) {
        $this->redactionManifest = $redactionManifest;
    }

    /**
     * @param array<string, mixed>|null $redactionManifest
     */
    public static function create(
        string $runKey,
        string $taskKey,
        string $inputsDigest,
        \DateTimeImmutable $retainUntil,
        int $taskVersion = 1,
        ?int $jobId = null,
        ?string $placementKey = null,
        ?int $routeId = null,
        string $bridgeKey = 'null',
        ?string $model = null,
        int $policyVersion = 0,
        ?int $principalUserId = null,
        string $actorKind = self::ACTOR_PERSON,
        ?int $ruleId = null,
        ?int $onBehalfOfUserId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $courseId = null,
        ?int $organisationId = null,
        string $locale = 'en-GB',
        ?array $redactionManifest = null,
        int $redactionManifestVersion = 1,
        bool $dryRun = false,
        string $costUnit = 'none',
        ?float $costReserved = null,
    ): self {
        return new self(
            $runKey,
            $taskKey,
            $taskVersion,
            $jobId,
            $placementKey,
            $routeId,
            $bridgeKey,
            $model,
            $policyVersion,
            $principalUserId,
            $actorKind,
            $ruleId,
            $onBehalfOfUserId,
            $subjectType,
            $subjectId,
            $courseId,
            $organisationId,
            $locale,
            $inputsDigest,
            $redactionManifest,
            $redactionManifestVersion,
            $dryRun,
            $costUnit,
            $costReserved,
            $retainUntil,
        );
    }

    /** The same reservation, stamped with the job it belongs to. */
    public function forJob(int $jobId): self
    {
        $clone = clone $this;

        return new self(
            $clone->runKey,
            $clone->taskKey,
            $clone->taskVersion,
            $jobId,
            $clone->placementKey,
            $clone->routeId,
            $clone->bridgeKey,
            $clone->model,
            $clone->policyVersion,
            $clone->principalUserId,
            $clone->actorKind,
            $clone->ruleId,
            $clone->onBehalfOfUserId,
            $clone->subjectType,
            $clone->subjectId,
            $clone->courseId,
            $clone->organisationId,
            $clone->locale,
            $clone->inputsDigest,
            $clone->redactionManifest,
            $clone->redactionManifestVersion,
            $clone->dryRun,
            $clone->costUnit,
            $clone->costReserved,
            $clone->retainUntil,
        );
    }
}
