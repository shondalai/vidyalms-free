<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Credit;

use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;

/** Optional authoring metadata for a credit scheme; the scheme still owns validation. */
interface DescribesCreditSettings extends DeclaresSettings
{
    /** @return 'free'|'pro'|'business' */
    public function edition(): string;
}
