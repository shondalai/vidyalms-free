<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Which mount is being rendered, handed to every bundle the asset seam asks.
 *
 * A context object rather than a pair of string arguments, for the reason the
 * core passes context objects into its providers: this is a published
 * extension seam, and a third-party bundle compiled against it must keep
 * working when the seam learns a new fact. Adding a member here is additive;
 * adding a parameter to appliesTo() would break every listener on every site.
 *
 * @since 1.0.0
 */
final class MountAssetContext
{
    public const APPLICATION_SITE = 'site';

    public const APPLICATION_ADMINISTRATOR = 'administrator';

    /**
     * @param string $mount        One of the MountAssets::MOUNT_* keys.
     * @param string $application  Which Joomla application is rendering it.
     */
    public function __construct(
        public readonly string $mount,
        public readonly string $application,
    ) {
    }

    public static function site(string $mount): self
    {
        return new self($mount, self::APPLICATION_SITE);
    }

    public static function administrator(string $mount): self
    {
        return new self($mount, self::APPLICATION_ADMINISTRATOR);
    }
}
