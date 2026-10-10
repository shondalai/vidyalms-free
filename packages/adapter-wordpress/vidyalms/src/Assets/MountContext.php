<?php

declare(strict_types=1);

/**
 * VidyaLMS: which mount is being rendered, handed to every mount bundle listener.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Assets;

defined('ABSPATH') || exit;

/**
 * Which mount is being rendered, and where.
 *
 * A context object rather than a bare string, for the reason the core passes
 * context objects into its providers and for the reason the Joomla adapter
 * already passes one into the same seam: this is a published extension point,
 * and a listener written against it must keep working when the seam learns a
 * new fact. Adding a member here is additive; adding a positional argument to
 * a filter is not, because every listener declares its own arity.
 *
 * The two adapters publishing structurally different seams was itself the
 * defect: an add-on shipping for both hosts wrote two unrelated integrations,
 * and only one of them could grow a fact without touching every listener.
 *
 * The bare mount string stays as the second argument for one release, so a
 * listener written before this object keeps working; the context is the third.
 */
final class MountContext
{
    public const APPLICATION_SITE = 'site';

    public const APPLICATION_ADMIN = 'admin';

    /**
     * @param string $mount       one of the MountBundles::MOUNT_* keys
     * @param string $application which WordPress surface is rendering it
     * @param string $appHandle   the handle the free app bundle was enqueued under
     */
    public function __construct(
        public readonly string $mount,
        public readonly string $application,
        public readonly string $appHandle
    ) {
    }

    public static function site(string $mount, string $appHandle): self
    {
        return new self($mount, self::APPLICATION_SITE, $appHandle);
    }

    public static function admin(string $mount, string $appHandle): self
    {
        return new self($mount, self::APPLICATION_ADMIN, $appHandle);
    }

    public function isAdmin(): bool
    {
        return $this->application === self::APPLICATION_ADMIN;
    }
}
