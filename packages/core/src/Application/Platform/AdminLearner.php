<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Access\LearnerSummary;
use Shondalai\VidyaLMS\Core\Port\Users\UserAccount;

/** One learner-directory row after its host account has been hydrated. */
final class AdminLearner
{
    public function __construct(
        public readonly LearnerSummary $summary,
        public readonly ?UserAccount $user,
    ) {
    }
}
