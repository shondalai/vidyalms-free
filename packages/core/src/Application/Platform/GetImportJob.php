<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * One import job, for the screen that polls a running migration. The log a
 * job carries can name courses and people, so reading one needs MANAGE, the
 * same capability as starting one.
 */
final class GetImportJob
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly AccessGuard $guard,
    ) {
    }

    public function execute(Principal $principal, int $jobId): ImportJob
    {
        $this->guard->require($principal, Capability::MANAGE);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('Import job', $jobId);
    }
}
