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
 * One client bundle another extension wants loaded beside a VidyaLMS mount.
 *
 * Declarative on purpose. A listener says what it has and which mounts it
 * belongs on; the component decides when to enable it, in what order and
 * against which free bundle. A seam that handed listeners the web asset
 * manager instead would let one of them enable, disable and reorder the
 * component's own assets, and the first support ticket about a blank player
 * would be unanswerable.
 *
 * @since 1.0.0
 */
final class MountAssetBundle
{
    /**
     * @param string               $name          The web asset name, unique across the site.
     * @param list<string>         $mounts        The MountAssets::MOUNT_* keys this belongs on.
     * @param string|null          $script        A media-relative script uri, "vidyalms_pro/app/x.js".
     * @param string|null          $style         A media-relative stylesheet uri.
     * @param list<string>         $dependencies  Other web asset names, beside the mount's own bundle.
     * @param array<string, mixed> $attributes    Script tag attributes; defer keeps the declared order.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $mounts,
        public readonly ?string $script = null,
        public readonly ?string $style = null,
        public readonly array $dependencies = [],
        public readonly array $attributes = ['defer' => true],
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A mount asset bundle needs a name.');
        }

        if ($mounts === []) {
            throw new \InvalidArgumentException('Mount asset bundle "' . $name . '" names no mount.');
        }

        foreach ($mounts as $mount) {
            if (!\in_array($mount, MountAssets::MOUNTS, true)) {
                throw new \InvalidArgumentException(
                    'Mount asset bundle "' . $name . '" names the unknown mount "' . $mount . '". '
                    . 'The mounts are: ' . implode(', ', MountAssets::MOUNTS) . '.',
                );
            }
        }

        if ($script === null && $style === null) {
            throw new \InvalidArgumentException('Mount asset bundle "' . $name . '" carries neither a script nor a style.');
        }
    }

    /**
     * Whether this bundle belongs on the mount being rendered.
     *
     * The context is the whole question, so a bundle that only makes sense on
     * one application, or on one screen a later context member names, answers
     * it here rather than being filtered by the component.
     */
    public function appliesTo(MountAssetContext $context): bool
    {
        return \in_array($context->mount, $this->mounts, true);
    }
}
