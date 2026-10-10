<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assignments;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionRepository;

/**
 * The caller's own attempts at one assignment, newest first. Scoped to the
 * principal so no capability is needed and nothing of others can leak.
 */
final class GetMySubmissions
{
    public function __construct(
        private readonly SubmissionRepository $submissions,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @return list<Submission>
     */
    public function execute(Principal $principal, int $assignmentId): array
    {
        $this->guard->requireAuthenticated($principal);

        return $this->submissions->listForUser($assignmentId, $principal->userId);
    }
}
