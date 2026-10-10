<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Accepts an import and hands it to the scheduler. The job row is created
 * pending and returned immediately; the work happens when the host's task
 * runner picks up "vidyalms.import.run" and calls RunQueuedImport with the id
 * in the payload.
 *
 * A Moodle course with two thousand questions takes minutes. Running it
 * inside the request that asked for it would hit the web server's timeout
 * halfway through and leave a half-imported course with no job row to explain
 * it, so the only thing this use case does synchronously is write the row.
 */
final class QueueImportJob
{
    /** The scheduled task type both adapters register a handler for. */
    public const TASK_TYPE = 'vidyalms.import.run';

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ImportIntake $intake,
        private readonly SchedulerPort $scheduler,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function execute(Principal $principal, string $importerKey, ?string $sourcePath, array $options = []): ImportJob
    {
        $this->guard->require($principal, Capability::MANAGE);

        return $this->queue($principal, $importerKey, $sourcePath, $options, null);
    }

    /**
     * The second, already-authorised entry point, for a run inside one
     * organisation.
     *
     * It takes the scope rather than an organisation id, and it takes it as a
     * required first argument, so an unscoped organisation call does not
     * compile. The caller has already authorised the operation:
     * OrganisationAccess minted the scope against the caller's own
     * org_memberships rows, which is the check, and a second
     * Capability::MANAGE here would refuse every org administrator, because an
     * org administrator is deliberately not a site manager.
     *
     * The organisation is stamped onto the job from this scope and from
     * nowhere else. A member CSV carrying an organisation column would let an
     * org admin enrol people into another organisation, which is the
     * group-leader leak wearing a spreadsheet, so the file is never asked.
     *
     * @param array<string, mixed> $options
     */
    public function executeForOrganisation(
        OrgScope $scope,
        Principal $principal,
        string $importerKey,
        ?string $sourcePath,
        array $options = [],
    ): ImportJob {
        return $this->queue($principal, $importerKey, $sourcePath, $options, $scope);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function queue(
        Principal $principal,
        string $importerKey,
        ?string $sourcePath,
        array $options,
        ?OrgScope $scope,
    ): ImportJob {
        $importer = $this->intake->importer($importerKey);
        $this->intake->source($sourcePath, $principal->userId, $importer);
        $options = $this->intake->options($options, $importer);
        $jobId = $this->jobs->create(
            $importerKey,
            $sourcePath,
            // Versioned by the importer that owns the meaning of these keys.
            // A queued job is read back at drain time, which may be days later
            // and after a plugin upgrade, and that is exactly what StoredShape
            // was written for.
            StoredShape::wrap($options, $this->intake->shapeOf($importer)),
            $principal->userId,
            $scope?->organisationId,
            $scope?->unitId,
        );

        $this->scheduler->schedule(new ScheduledTask(
            self::TASK_TYPE,
            ['job_id' => $jobId],
            $this->clock->now(),
            self::dedupeKeyFor($jobId),
        ));

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('Import job', $jobId);
    }

    /**
     * One pending task per job. The key is derived from the job id and
     * carries the task type, so cancelling an import cannot cancel an
     * unrelated task that happens to share the number.
     */
    public static function dedupeKeyFor(int $jobId): string
    {
        return self::TASK_TYPE . ':' . $jobId;
    }
}
