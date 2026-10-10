<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJob;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRecipeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRegistry;

/**
 * Accepts a build and hands it to the scheduler.
 *
 * The job row is created pending and returned immediately; the work happens when
 * the host's task runner picks up "vidyalms.ai.run" and calls RunQueuedAiJob
 * with the id in the payload. That is QueueImportJob's own reason, unchanged: a
 * two hundred item build takes minutes, and running it inside the request that
 * asked for it would hit the web server's timeout halfway through and leave half
 * a course with no job row to explain it.
 *
 * **One pending task per job**, keyed on the job id and namespaced by the task
 * type, so cancelling a build cannot cancel an unrelated task that happens to
 * share the number. The deferred resume re-arms under the SAME key, which is
 * what makes a resume one row rather than a second job.
 *
 * A job names exactly one of a task and a recipe. A recipe is how a build runs
 * three task keys under one row without a core orchestrator hardcoding the
 * sequence, and it is refused here when nothing registers it, because a queued
 * job naming an absent recipe would be discovered at drain time and marked
 * failed for a package somebody had not installed yet.
 */
final class QueueAiJob
{
    /** The scheduled task type both adapters register a handler for. */
    public const TASK_TYPE = 'vidyalms.ai.run';

    public function __construct(
        private readonly AiJobRepository $jobs,
        private readonly AiTaskRegistry $tasks,
        private readonly AiRecipeRegistry $recipes,
        private readonly SchedulerPort $scheduler,
        private readonly AiAccess $access,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function execute(
        Principal $principal,
        ?string $taskKey,
        ?string $recipeKey,
        string $scopeType,
        int $scopeId,
        array $options = [],
        bool $dryRun = false,
        ?string $placementKey = null,
        ?OrgScope $orgScope = null,
        ?int $courseId = null,
    ): AiJob {
        $this->access->requireAuthoring($principal, $courseId);
        $this->assertWorkExists($taskKey, $recipeKey);

        $jobId = $this->jobs->create(
            $taskKey,
            $recipeKey,
            $scopeType,
            $scopeId,
            $options,
            $principal->userId,
            $dryRun,
            $placementKey,
            // The organisation is stamped from the authorised scope and from
            // nowhere else. An organisation taken out of the options map is an
            // administrator raising a build against somebody else's tenant.
            $orgScope?->organisationId,
        );

        $this->scheduler->schedule(new ScheduledTask(
            self::TASK_TYPE,
            ['job_id' => $jobId],
            $this->clock->now(),
            self::dedupeKeyFor($jobId),
        ));

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('AI job', $jobId);
    }

    /**
     * One pending task per job. The key carries the task type, so a build's
     * pending row and an unrelated task sharing the number are different keys,
     * and a deferred resume re-arms under this same key rather than adding a
     * second pending row for the same work.
     */
    public static function dedupeKeyFor(int $jobId): string
    {
        return self::TASK_TYPE . ':' . $jobId;
    }

    private function assertWorkExists(?string $taskKey, ?string $recipeKey): void
    {
        if (($taskKey === null) === ($recipeKey === null)) {
            throw ValidationException::forField(
                'task_key',
                'An AI job names exactly one of a task key and a recipe key.',
            );
        }

        if ($taskKey !== null && !$this->tasks->has($taskKey)) {
            throw ValidationException::forField(
                'task_key',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('No AI task is registered under "%s".', $taskKey),
            );
        }

        if ($recipeKey !== null && !$this->recipes->has($recipeKey)) {
            throw ValidationException::forField(
                'recipe_key',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('No AI recipe is registered under "%s".', $recipeKey),
            );
        }
    }
}
