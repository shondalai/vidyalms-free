<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Storage;

/** Enumerates uploaded work, including files never attached to a submission. */
interface ListsLearnerUploads
{
    /** @return iterable<string> Logical private paths belonging only to this learner. */
    public function learnerUploads(int $userId): iterable;
}
