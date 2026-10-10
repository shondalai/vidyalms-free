<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuizSetting;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * The quiz-level setting groups this site's packages contribute to the
 * authoring form.
 *
 * The miss is defined here like every other registry's, and it is the
 * quietest one in the codebase: a group whose package is gone is simply
 * absent from the form. Nothing stored depends on it, because the core never
 * writes a contributed field, so an author on a site that lost the package
 * sees the five core groups and no error.
 *
 * @extends AbstractProviderRegistry<QuizSettingGroupInterface>
 */
final class QuizSettingGroupRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return QuizSettingGroupInterface::class;
    }
}
