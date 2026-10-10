<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\QuizSetting\QuizSettingContext;
use Shondalai\VidyaLMS\Core\Registry\QuizSetting\QuizSettingGroupInterface;

/**
 * A settings group with nothing to say about this quiz. Null rather than an
 * empty field list, because a group with no fields renders as an empty tab
 * and an author reads that as a broken screen.
 */
final class SilentSettingGroup implements QuizSettingGroupInterface
{
    public function key(): string
    {
        return 'silent';
    }

    public function describe(QuizSettingContext $context): ?array
    {
        return null;
    }
}
