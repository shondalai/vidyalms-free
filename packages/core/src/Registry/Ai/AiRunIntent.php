<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * What a policy is being asked to decide about, before anything is spent.
 *
 * A policy vetoes or defers and never selects, so it is handed the shape of
 * the intended run rather than the run itself: no payload, no segments and no
 * bridge handle, because a policy that could read the payload would be a
 * second redactor and a policy that held a bridge would be a second caller.
 *
 * The subject count is here because two of the free ceilings are unit-free
 * counts rather than sums, which is what keeps a non-metering bridge bounded.
 *
 * **The subject and the course are here for the same reason the scope is.** A
 * scope on its own says which course the caller was authorised for and says
 * nothing about the thing the run is pointed at, so a policy asked to bind the
 * two had to be handed the subject or invent a second read of the request.
 * Carrying both makes `is this subject inside the scope this caller was
 * authorised for` a question a policy can answer before any fact resolves.
 *
 * **The actor kind is here so a gate can tell a click from a job.** A person
 * pressing a button acts through a placement; a queued build and a sweep act
 * through neither, and a rule that reads an absent placement key as `no gate
 * applies` cannot tell the two apart.
 */
final class AiRunIntent
{
    /** A person pressed a button, which is the only actor kind a placement gates. */
    public const ACTOR_PERSON = 'person';

    private function __construct(
        public readonly string $taskKey,
        public readonly ?string $recipeKey,
        public readonly ?string $placementKey,
        public readonly string $scopeType,
        public readonly ?int $scopeId,
        public readonly int $subjectCount,
        public readonly CostEstimate $estimate,
        public readonly bool $dryRun,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?int $courseId,
        public readonly string $actorKind,
    ) {
    }

    public static function forTask(
        string $taskKey,
        string $scopeType = 'site',
        ?int $scopeId = null,
        ?string $placementKey = null,
        int $subjectCount = 1,
        ?CostEstimate $estimate = null,
        bool $dryRun = false,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $courseId = null,
        string $actorKind = self::ACTOR_PERSON,
    ): self {
        return new self(
            $taskKey,
            null,
            $placementKey,
            $scopeType,
            $scopeId,
            $subjectCount,
            $estimate ?? CostEstimate::unknown(),
            $dryRun,
            $subjectType,
            $subjectId,
            $courseId,
            $actorKind,
        );
    }

    public static function forRecipe(
        string $recipeKey,
        string $taskKey,
        string $scopeType = 'site',
        ?int $scopeId = null,
        ?string $placementKey = null,
        int $subjectCount = 1,
        ?CostEstimate $estimate = null,
        bool $dryRun = false,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $courseId = null,
        string $actorKind = self::ACTOR_PERSON,
    ): self {
        return new self(
            $taskKey,
            $recipeKey,
            $placementKey,
            $scopeType,
            $scopeId,
            $subjectCount,
            $estimate ?? CostEstimate::unknown(),
            $dryRun,
            $subjectType,
            $subjectId,
            $courseId,
            $actorKind,
        );
    }
}
