<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobPage;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;

/**
 * The import history, newest first: what was migrated, when, by whom, and
 * whether it worked.
 */
final class ListImportJobs
{
    public const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly AccessGuard $guard,
    ) {
    }

    public function execute(Principal $principal, int $page = 1, int $perPage = 20): ImportJobPage
    {
        $this->guard->require($principal, Capability::MANAGE);

        return $this->jobs->recent(
            max(1, $page),
            min(self::MAX_PER_PAGE, max(1, $perPage)),
        );
    }
}
