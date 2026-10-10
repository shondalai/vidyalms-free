<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Settings;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/** Extra host-owned permission for configuration that controls another system. */
interface AuthorizesSettingsWrite
{
    public function requireSettingsWrite(Principal $principal): void;
}
