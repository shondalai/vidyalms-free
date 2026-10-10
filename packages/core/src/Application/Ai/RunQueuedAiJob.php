<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJob;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * What the host's task runner calls when "vidyalms.ai.run" fires, with the job
 * id from the payload.
 *
 * There is no caller principal: cron has no session. The job records who asked
 * for the build, and the run acts as that person, so a requester who has lost
 * the capability since queueing does not get the run. That keeps the
 * authorisation story honest in both directions.
 *
 * **A job that is not pending is refused with ConflictException**, so a task
 * runner that redelivers does not restart a build that is already halfway. The
 * handler catches it and logs an already-claimed line rather than rethrowing,
 * because the handler owns its own retry and therefore does not throw.
 *
 * **A deferred job resumes rather than fails.** A ceiling policy defers, the row
 * is parked with a real resume instant under the unchanged dedupe key, and this
 * is where the parked row is re-opened. Without the re-open a deferred build
 * would have needed a second job row, which is exactly the duplicate the dedupe
 * key exists to prevent.
 */
final class RunQueuedAiJob
{
    /**
     * The organisation action an AI build runs under.
     *
     * It is "learning", which is one of the four unit-scoped actions the
     * organisation role model already publishes, rather than a new action name.
     * An AI build writes drafts against an organisation's learning content and
     * nothing else, and inventing a fifth action nobody has been granted would
     * leave every organisation build unrunnable while looking like a permission
     * model.
     */
    public const ORGANISATION_ACTION = 'learning';

    public function __construct(
        private readonly AiJobRepository $jobs,
        private readonly AiJobRunner $runner,
        private readonly AiAccess $access,
        /**
         * How the drain re-mints the authority an organisation build was
         * accepted under. Optional so a caller built before organisation runs
         * existed still constructs; a null resolver leaves an organisation job
         * unrunnable and says so, which is the safe direction for a missing
         * dependency to fail in.
         */
        private readonly ?OrganisationAccess $organisations = null,
    ) {
    }

    public function execute(int $jobId): AiJob
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $job = $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('AI job', $jobId);

        // A parked job is claimable again by the same scheduled row, which is
        // what makes a resume one row rather than a second job.
        //
        // The guarded update IS the claim: reopenDeferred() matches only a row
        // still marked deferred, so two runners racing the same parked job
        // produce exactly one winner and the loser falls through to the refusal
        // below. There is deliberately no second read here, because a read after
        // the claim would reintroduce the window the guarded write closed.
        $claimedFromDeferral = $job->status === AiJobStatus::Deferred
            && $this->jobs->reopenDeferred($jobId);

        if (!$claimedFromDeferral && $job->status !== AiJobStatus::Pending) {
            throw new ConflictException(sprintf(
                'AI job %d is %s and cannot be started again.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $jobId,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $job->status->value,
            ));
        }

        $principal = $job->createdBy > 0 ? Principal::user($job->createdBy) : Principal::guest();
        $this->authorise($principal, $job);

        return $this->runner->run($job, $principal);
    }

    /**
     * The authority the job is drained under, which is the authority it was
     * accepted under.
     *
     * A site build is a manager's or a teacher's and that check is unchanged. An
     * organisation build is not: the tenant is re-resolved from the stored job
     * rather than trusted from it, so an administrator who has since been
     * removed loses the drain as well as the console. That is the lesson the
     * import pipeline already learned, where demanding site MANAGE at drain time
     * undid the whole organisation path and left every queued org job pending for
     * ever.
     */
    private function authorise(Principal $principal, AiJob $job): void
    {
        if ($job->organisationId === null) {
            $this->access->requireAuthoring(
                $principal,
                $job->scopeType === 'course' ? $job->scopeId : null,
            );

            return;
        }

        if ($this->organisations === null) {
            throw new ConflictException(sprintf(
                'AI job %d belongs to organisation %d and this build cannot re-resolve that organisation\'s'
                    . ' authority, so it will not run it as the site.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $job->id,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $job->organisationId,
            ));
        }

        $this->organisations->require($principal, $job->organisationId, self::ORGANISATION_ACTION);
    }
}
