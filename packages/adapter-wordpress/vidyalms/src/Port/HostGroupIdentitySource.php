<?php

declare(strict_types=1);

/**
 * VidyaLMS: what WordPress itself asserts about a person.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentityPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Provider\Identity\HostGroupIdentitySource as CoreHostGroupIdentitySource;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry;
use Shondalai\VidyaLMS\WordPress\Plugin;

defined('ABSPATH') || exit;

/**
 * What WordPress itself asserts about a person: this account, and these roles.
 *
 * **Only the role read lives here now.** Everything above it (resolving the
 * tenant from the organisation whose verified connection holds this site's own
 * local issuer, refusing on none and on more than one, resolving the dialect
 * through the registry, and shaping the assertion) is
 * {@see CoreHostGroupIdentitySource}, written once for every host rather than
 * once per host. This file and its Joomla twin used to be the same two hundred
 * lines twice over, differing in the six below, so a rule fixed in one was a
 * rule fixed on one host and a third adapter would have written them a third
 * time.
 *
 * **The four adapter security requirements still hold and are still visible.**
 * No attribute is read from a request: the only input is a `Principal`, which
 * is an integer the authorisation layer produced, and the roles come from the
 * user record. No token, secret or certificate is held, read or resolved.
 * Nothing is written: `get_userdata()` and `wp_roles()` are WordPress's own
 * cached readers. No directory is contacted, and there is no `HttpPort` in the
 * constructor.
 *
 * **The key is the role slug and not its display name.** A slug is what
 * `add_role()` registers and is stable across a rename and a translation; the
 * display name is translated for the current locale on every read, so a rule
 * matching one would match different people in different languages. The name
 * rides along as a label nothing ever compares.
 */
final class HostGroupIdentitySource implements AssertedIdentityPort
{
    private readonly CoreHostGroupIdentitySource $source;

    public function __construct(
        DatabasePort $database,
        ClockPort $clock,
        UsersPort $users,
    ) {
        $this->source = new CoreHostGroupIdentitySource(
            $database,
            $clock,
            $users,
            // Resolved on the request that needs it. Ports is readonly and is
            // finished before the kernel exists, so a registry taken here
            // would be one built a second time; asking late is also what lets
            // a package that registered a dialect from kernel-ready be seen.
            static fn (): IdentityMapperRegistry => Plugin::kernel()->registries->identityMappers,
            $this->hostGroups(...),
        );
    }

    public function assertedIdentityFor(Principal $principal): ?AssertedIdentity
    {
        return $this->source->assertedIdentityFor($principal);
    }

    /**
     * The roles WordPress recorded against this account, as slugs.
     *
     * @return list<array{key: string, label?: string}>
     */
    private function hostGroups(int $userId): array
    {
        $user = get_userdata($userId);

        if (!$user instanceof \WP_User) {
            return [];
        }

        $names = [];

        if (function_exists('wp_roles')) {
            /** @var array<string, string> $names */
            $names = wp_roles()->get_names();
        }

        $groups = [];

        foreach ($user->roles as $role) {
            $slug = trim((string) $role);

            if ($slug === '') {
                continue;
            }

            $group = ['key' => $slug];
            $label = isset($names[$slug]) ? (string) $names[$slug] : '';

            if ($label !== '') {
                $group['label'] = $label;
            }

            $groups[] = $group;
        }

        return $groups;
    }
}
