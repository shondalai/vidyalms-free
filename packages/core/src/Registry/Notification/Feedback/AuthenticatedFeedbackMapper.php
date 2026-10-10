<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Feedback;

use Shondalai\VidyaLMS\Core\Registry\Context\FeedbackContext;

/** Provider-specific checks run after the channel's shared-secret check. */
interface AuthenticatedFeedbackMapper
{
    /** @param array<string, mixed> $settings */
    public function authenticate(string $body, FeedbackContext $context, array $settings): void;
}
