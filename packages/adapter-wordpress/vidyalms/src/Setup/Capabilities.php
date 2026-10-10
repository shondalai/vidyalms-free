<?php

declare(strict_types=1);

/**
 * VidyaLMS: the capability map and the roles that carry it.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Setup;

defined('ABSPATH') || exit;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;

/**
 * The core's capability vocabulary expressed as WordPress capabilities.
 *
 * The core speaks dotted keys (Application\Shared\Capability); WordPress speaks
 * underscored capability names on roles. The two are one substitution apart, so
 * a Pro package that adds "vidyalms.something" gets "vidyalms_something" here
 * without editing this map, exactly as the Joomla adapter passes unknown
 * vidyalms.* actions through to access.xml.
 *
 * Roles: administrator gains every capability on activation, a
 * vidyalms_instructor role is added for people who teach without running the
 * site, and a vidyalms_org_admin role for the people who run one organisation
 * inside it. Anything finer is the site owner's job with a role editor, which
 * is why the capabilities are plain and individually grantable.
 */
final class Capabilities
{
    public const ADMIN = 'vidyalms_admin';

    public const MANAGE = 'vidyalms_manage';

    public const TEACH = 'vidyalms_teach';

    public const GRADE = 'vidyalms_grade';

    public const ENROL = 'vidyalms_enrol';

    public const REPORTS = 'vidyalms_reports';

    public const MANAGE_GROUPS = 'vidyalms_manage_groups';

    /**
     * WordPress's own administration gate. Holding it implies every VidyaLMS
     * capability except ADMIN; see WpAuthorization for why.
     */
    public const SITE_ADMINISTRATION = 'manage_options';

    public const INSTRUCTOR_ROLE = 'vidyalms_instructor';

    /**
     * The delegated organisation console, the WordPress half of the Joomla
     * "Organisation administrators" group. Organisations are free, so this
     * role ships with the free plugin exactly as the instructor role does.
     */
    public const ORG_ADMIN_ROLE = 'vidyalms_org_admin';

    public const ADMINISTRATOR_ROLE = 'administrator';

    /**
     * The prefix a dotted core capability carries.
     */
    private const CORE_PREFIX = 'vidyalms.';

    /**
     * Core capability key to WordPress capability.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'vidyalms.admin' => self::ADMIN,
        'vidyalms.manage' => self::MANAGE,
        'vidyalms.teach' => self::TEACH,
        'vidyalms.grade' => self::GRADE,
        'vidyalms.enrol' => self::ENROL,
        'vidyalms.reports' => self::REPORTS,
        'vidyalms.manage_groups' => self::MANAGE_GROUPS,
    ];

    /**
     * What an instructor may do: teach, grade, enrol people onto their courses
     * and read the reports that go with it. Not site configuration, not groups.
     *
     * @var list<string>
     */
    private const INSTRUCTOR_CAPABILITIES = [
        self::TEACH,
        self::GRADE,
        self::ENROL,
        self::REPORTS,
    ];

    /**
     * What an organisation administrator may do here: nothing at all.
     *
     * MANAGE_GROUPS grants entry to the delegated organisation surface and is
     * asked with no resource; what the caller may then do inside an
     * organisation is decided by their rows in org_role_assignments, never by
     * a WordPress capability. That is why this list is one entry long and has
     * to stay one entry long: every site-wide use case refuses an
     * organisation administrator precisely because they hold this and nothing
     * else, so a second capability here would widen four of them silently.
     *
     * @var list<string>
     */
    private const ORG_ADMIN_CAPABILITIES = [
        self::MANAGE_GROUPS,
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function map(): array
    {
        return self::MAP;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(self::MAP);
    }

    /**
     * @return list<string>
     */
    public static function instructorCapabilities(): array
    {
        return self::INSTRUCTOR_CAPABILITIES;
    }

    /**
     * @return list<string>
     */
    public static function orgAdminCapabilities(): array
    {
        return self::ORG_ADMIN_CAPABILITIES;
    }

    /**
     * Translates a core capability key to a WordPress capability. Anything that
     * is not a vidyalms.* key is refused rather than guessed: a typo must fail
     * closed, not resolve to some other site capability.
     */
    public static function wordpressCapability(string $coreCapability): ?string
    {
        if (isset(self::MAP[$coreCapability])) {
            return self::MAP[$coreCapability];
        }

        if (!str_starts_with($coreCapability, self::CORE_PREFIX)) {
            return null;
        }

        $tail = substr($coreCapability, \strlen(self::CORE_PREFIX));

        if (preg_match('/^[a-z][a-z0-9_.]*$/', $tail) !== 1) {
            return null;
        }

        return 'vidyalms_' . str_replace('.', '_', $tail);
    }

    /**
     * Called on activation. add_role() is a no-op when the role already exists,
     * so the capabilities are applied afterwards either way: a site upgrading
     * from a build with fewer capabilities gains the new ones.
     */
    public static function register(): void
    {
        if (get_role(self::INSTRUCTOR_ROLE) === null) {
            add_role(
                self::INSTRUCTOR_ROLE,
                __('VidyaLMS Instructor', 'vidyalms'),
                ['read' => true],
            );
        }

        // Same terms as the instructor role: add_role() is a no-op when a
        // site owner already made a role of that name, and the capability is
        // applied afterwards either way, so a site upgrading from a build
        // that had no organisations gains the role rather than needing a
        // reactivation. `read` is WordPress's own "may see wp-admin at all"
        // and is what every non-subscriber role carries; the delegated
        // console is reached through the site, not through wp-admin.
        if (get_role(self::ORG_ADMIN_ROLE) === null) {
            add_role(
                self::ORG_ADMIN_ROLE,
                __('VidyaLMS Organisation Administrator', 'vidyalms'),
                ['read' => true],
            );
        }

        self::grant(self::INSTRUCTOR_ROLE, self::INSTRUCTOR_CAPABILITIES);
        self::grant(self::ORG_ADMIN_ROLE, self::ORG_ADMIN_CAPABILITIES);
        self::grant(self::ADMINISTRATOR_ROLE, self::all());
    }

    /**
     * Called on uninstall. Every role is swept, not just the two this plugin
     * created: a site owner may have granted vidyalms_grade to a role of their
     * own, and leaving it behind would leave a dead capability on that role
     * forever.
     */
    public static function unregister(): void
    {
        $roles = wp_roles();

        foreach (array_keys($roles->roles) as $roleName) {
            $role = get_role((string) $roleName);

            if ($role === null) {
                continue;
            }

            foreach (self::all() as $capability) {
                $role->remove_cap($capability);
            }
        }

        remove_role(self::INSTRUCTOR_ROLE);
        remove_role(self::ORG_ADMIN_ROLE);
    }

    /**
     * @param list<string> $capabilities
     */
    private static function grant(string $roleName, array $capabilities): void
    {
        $role = get_role($roleName);

        if ($role === null) {
            return;
        }

        foreach ($capabilities as $capability) {
            if (!$role->has_cap($capability)) {
                $role->add_cap($capability);
            }
        }
    }
}
