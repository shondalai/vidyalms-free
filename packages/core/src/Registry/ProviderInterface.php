<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry;

/**
 * Anything that can be registered into an extension point. The key is stable,
 * lowercase and stored in the database (lessons.type, questions.type,
 * entitlements.source), so a provider key may never change once shipped.
 */
interface ProviderInterface
{
    public function key(): string;
}
