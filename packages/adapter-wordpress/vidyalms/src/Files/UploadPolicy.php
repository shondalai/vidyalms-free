<?php

declare(strict_types=1);

/**
 * VidyaLMS: which uploads this site accepts.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Files;

defined('ABSPATH') || exit;

use Shondalai\VidyaLMS\Core\Upload\UploadPolicy as SharedPolicy;

/**
 * This site's upload policy: the shared registry, sized by this host's filter,
 * then whatever this host's extension action did to it.
 *
 * The registry itself, the purposes, the extension allowlist and the media
 * type table are the core's (Core\Upload\UploadPolicy). They were duplicated
 * here and in the Joomla adapter as identical CMS-free PHP, which put the
 * security boundary in two places; two copies of an allowlist diverge, and a
 * divergence there means one host accepts bytes the other refuses. What is
 * left here is what is genuinely WordPress's: the filter that carries the
 * ceilings, and the action that lets a plugin add a purpose.
 *
 * ## Extending it
 *
 * The policy is built once per request and passed through
 * vidyalms_upload_policy before it is used:
 *
 *     add_action('vidyalms_upload_policy', function (UploadPolicy $policy): void {
 *         $policy->register(new UploadPurpose('caption', ['vtt' => ['text/vtt']], 1048576));
 *     });
 *
 * It is an action rather than a filter because the policy is mutated in place;
 * a listener that throws leaves the site with the free build's policy, which is
 * the safe direction to fail in.
 */
final class UploadPolicy
{
    public const ACTION = 'vidyalms_upload_policy';

    /**
     * Site owners raise or lower a purpose's ceiling through this filter
     * rather than a setting, because the number that actually binds is usually
     * PHP's. Signature: (int $megabytes, string $purposeKey): int
     */
    public const FILTER_MAX_MB = 'vidyalms_upload_max_mb';

    private function __construct()
    {
    }

    /**
     * The site's policy: the free build's two purposes, sized by the filter,
     * then whatever the extension action did to them.
     */
    public static function forSite(): SharedPolicy
    {
        $policy = self::free();

        try {
            do_action('vidyalms_upload_policy', $policy);
        } catch (\Throwable) {
            // A listener that fails leaves the site with the free build's
            // policy, which is the safe direction to fail in.
        }

        return $policy;
    }

    /**
     * The free build's two purposes, with no site action applied. The smoke
     * harness uses this so a site's own listener cannot make it pass or fail.
     */
    public static function free(): SharedPolicy
    {
        return SharedPolicy::free(
            self::megabytes(SharedPolicy::DEFAULT_RESOURCE_MB, SharedPolicy::PURPOSE_RESOURCE),
            self::megabytes(SharedPolicy::DEFAULT_IMAGE_MB, SharedPolicy::PURPOSE_IMAGE),
        );
    }

    /**
     * The purpose's own ceiling in bytes. PHP's ceiling is applied by the
     * endpoint, not here: this number is the site owner's intent and that one
     * is what the server will actually accept.
     */
    public static function megabytes(int $fallback, string $purposeKey): int
    {
        $key = $purposeKey === SharedPolicy::PURPOSE_IMAGE ? 'upload_max_image_mb' : 'upload_max_resource_mb';
        $fallback = (int) \Shondalai\VidyaLMS\WordPress\Setup\Settings::get($key, $fallback);
        try {
            $configured = (int) apply_filters('vidyalms_upload_max_mb', $fallback, $purposeKey);
        } catch (\Throwable) {
            $configured = $fallback;
        }

        return max(1, $configured) * 1024 * 1024;
    }
}
