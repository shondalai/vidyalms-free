<?php

declare(strict_types=1);

/**
 * VidyaLMS: the authorization port over WordPress capabilities.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Port\Authorization\HoldersOfCapability;
use Shondalai\VidyaLMS\WordPress\Setup\Capabilities;

defined('ABSPATH') || exit;

/**
 * The core's capability vocabulary answered by WordPress capabilities.
 *
 * WordPress denies an unknown capability by default, and vidyalms_* are unknown
 * to every role the site had before this plugin arrived. Left at that, an
 * administrator who had not yet been granted the capabilities (a site restored
 * from a backup, a role reset by a security plugin, a network admin on a site
 * where activation ran elsewhere) would open VidyaLMS and find every screen
 * refusing them, which is not what "administrator" means in WordPress. So two
 * implications hold, mirroring the Joomla adapter where core.admin and
 * core.manage imply the component's actions:
 *
 *   vidyalms_admin, or the network super admin, grants every capability
 *   manage_options grants every capability except vidyalms_admin
 *
 * A site owner still delegates finer rights by granting the individual
 * vidyalms_* capabilities to roles that hold neither, which is the normal way
 * to give someone only teaching or only grading.
 *
 * A resource reference of the form "course:42" is answered through the
 * vidyalms_user_can_course filter, so a per-course grant has a seam without the
 * core learning anything about WordPress.
 */
final class WpAuthorization implements AuthorizationPort, HoldersOfCapability
{
    /**
     * Filter: (bool $allowed, int $userId, string $coreCapability, int $courseId): bool
     */
    public const COURSE_FILTER = 'vidyalms_user_can_course';

    /**
     * How many users one lookup examines before the capability check. A site
     * with more holders than this has a directory problem rather than a
     * notification problem, and a bounded scan is what keeps the fan-out
     * bounded.
     */
    public const MAX_SCAN = 2000;

    /**
     * The reverse direction: who holds a capability.
     *
     * The free site_managers audience is the only consumer, and with no
     * implementation here it resolved empty on every install and counted one
     * suppression, so a free provider had no free code path that could reach
     * it. WordPress can enumerate by capability directly, which is the whole
     * of the candidate set; every candidate is then confirmed through the
     * same can() the forward check uses, so the two answers cannot disagree
     * and a course-scoped question keeps the filter's own scoping.
     *
     * @return list<int>
     */
    public function holdersOf(string $capability, ?string $resource, int $limit, int $offset): array
    {
        $wordpressCapability = Capabilities::wordpressCapability($capability);

        if ($wordpressCapability === null || $limit < 1 || !self::supportedResource($resource)) {
            return [];
        }

        $candidates = [];

        // The capability itself, and the two that imply it, because a site
        // whose administrator was never granted the plugin capabilities still
        // has an administrator and the forward check says so.
        $capabilities = $wordpressCapability === Capabilities::ADMIN
            ? [Capabilities::ADMIN]
            : [$wordpressCapability, Capabilities::ADMIN, Capabilities::SITE_ADMINISTRATION];

        foreach ($capabilities as $queried) {
            /** @var list<\WP_User> $users */
            $users = get_users([
                'capability' => $queried,
                'fields' => ['ID'],
                'number' => self::MAX_SCAN,
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);

            foreach ($users as $user) {
                $userId = (int) $user->ID;

                if ($userId > 0) {
                    $candidates[$userId] = true;
                }
            }
        }

        $ids = array_keys($candidates);
        sort($ids);

        $holders = [];
        $skipped = 0;

        foreach ($ids as $userId) {
            if (!$this->can($userId, $capability, $resource)) {
                continue;
            }

            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            $holders[] = $userId;

            if (\count($holders) >= $limit) {
                break;
            }
        }

        return $holders;
    }

    public function can(int $userId, string $capability, ?string $resource = null): bool
    {
        if ($userId <= 0 || !self::supportedResource($resource)) {
            return false;
        }

        $wordpressCapability = Capabilities::wordpressCapability($capability);

        if ($wordpressCapability === null) {
            return false;
        }

        $user = get_userdata($userId);

        if (!$user instanceof \WP_User || (int) $user->ID !== $userId) {
            return false;
        }

        $allowed = $this->granted($user, $wordpressCapability);
        $courseId = self::courseId($resource);

        if ($courseId === null) {
            return $allowed;
        }

        return (bool) apply_filters('vidyalms_user_can_course', $allowed, $userId, $capability, $courseId);
    }

    /**
     * is_super_admin() is the WordPress question "may this account do anything
     * on this site": on a network it is network membership, on a single site it
     * is the delete_users capability. Both are strictly above VidyaLMS.
     */
    private function granted(\WP_User $user, string $wordpressCapability): bool
    {
        if ($user->has_cap(Capabilities::ADMIN) || is_super_admin((int) $user->ID)) {
            return true;
        }

        if ($user->has_cap($wordpressCapability)) {
            return true;
        }

        return $wordpressCapability !== Capabilities::ADMIN
            && $user->has_cap(Capabilities::SITE_ADMINISTRATION);
    }

    /**
     * Course references are the only host-scoped resource. Organisation
     * authority belongs to the Core scope resolver, never a global fallback.
     */
    private static function courseId(?string $resource): ?int
    {
        if ($resource === null || $resource === '') {
            return null;
        }

        $parts = explode(':', $resource, 2);

        if (\count($parts) !== 2 || $parts[0] !== 'course' || preg_match('/^[1-9][0-9]*$/', $parts[1]) !== 1) {
            return null;
        }

        return (int) $parts[1];
    }

    private static function supportedResource(?string $resource): bool
    {
        return $resource === null || $resource === ''
            || (preg_match('/^course:[1-9][0-9]{0,9}$/D', $resource) === 1
                && (int) substr($resource, 7) <= 2147483647);
    }
}
