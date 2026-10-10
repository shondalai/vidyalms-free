<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;

/** Public verification names require site approval and respect the holder's preference. */
final class CredentialNamePublication
{
    public const SITE_SETTING = 'certificates.publish_learner_name';

    public static function holderSetting(int $userId): string
    {
        return 'certificates.publish_learner_name.user.' . $userId;
    }

    public static function allowed(?Settings $settings, int $userId): bool
    {
        return $settings !== null && $settings->getBool(self::SITE_SETTING, false)
            && $settings->getBool(self::holderSetting($userId), true);
    }
}
