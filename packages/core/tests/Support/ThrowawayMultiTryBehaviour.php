<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyContext;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * A behaviour that honours a try penalty, so a test can store a quiz against
 * it and then boot a registry without it.
 *
 * There is no unregister and there will not be one, so "the package was
 * removed" is a second registry that never had the key, which is exactly
 * what a site boots after an uninstall.
 */
final class ThrowawayMultiTryBehaviour implements AttemptPolicyInterface
{
    public const KEY = 'x_acme_multi_try';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Acme multi try';
    }

    public function resolve(array $quizSettings, AttemptPolicyContext $context): AttemptPolicySet
    {
        return AttemptPolicySet::fromQuizPolicy($this->key(), $context->quizPolicy, [
            AttemptPolicySet::KEY_SHOW_FEEDBACK => true,
        ]);
    }

    public function revealsOutcomeDuringAttempt(): bool
    {
        return true;
    }

    public function triesPerPart(): int
    {
        return 3;
    }
}
