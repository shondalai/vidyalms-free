<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Helper\UserGroupsHelper;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentityPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Provider\Identity\HostGroupIdentitySource as CoreHostGroupIdentitySource;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;

/**
 * What Joomla itself asserts about a person: this account, and these groups.
 *
 * **Only the group read lives here now.** Everything above it (resolving the
 * tenant from the organisation whose verified connection holds this site's own
 * local issuer, refusing on none and on more than one, resolving the dialect
 * through the registry, and shaping the assertion) is
 * {@see CoreHostGroupIdentitySource}, written once for every host rather than
 * once per host. This file and its WordPress twin used to be the same two
 * hundred lines twice over, differing in the six below, so a rule fixed in one
 * was a rule fixed on one host and a third adapter would have written them a
 * third time.
 *
 * **The four adapter security requirements still hold and are still visible.**
 * No attribute is read from a request: the only input is a `Principal`, which
 * is an integer the authorisation layer produced, and the groups come from
 * Joomla's own access API. No token, secret or certificate is held, read or
 * resolved. Nothing is written: `Access::getGroupsByUser()` and
 * `UserGroupsHelper` are Joomla's own cached readers. No directory is
 * contacted, and there is no `HttpPort` in the constructor.
 *
 * @since 1.0.0
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
            static fn (): IdentityMapperRegistry => KernelFactory::kernel()->registries->identityMappers,
            $this->hostGroups(...),
        );
    }

    public function assertedIdentityFor(Principal $principal): ?AssertedIdentity
    {
        return $this->source->assertedIdentityFor($principal);
    }

    /**
     * The groups Joomla recorded against this account, as identifiers.
     *
     * Assigned groups and not the inherited ones. A Joomla group tree makes
     * every parent of an assigned group effective for permission checks, and
     * that is an access-control derivation rather than a statement about the
     * person: an administrator who put somebody in "Editors" said Editors, and
     * a mapping rule written against Editors should not also fire for everyone
     * in "Registered". The key is the group id because a group id is immutable
     * and a group title is renameable, which is exactly the distinction
     * {@see \Shondalai\VidyaLMS\Core\Port\Identity\AssertedKeyKind} exists to
     * carry; the title rides along as a label nothing ever compares.
     *
     * @return list<array{key: string, label?: string}>
     */
    private function hostGroups(int $userId): array
    {
        $titles = [];

        try {
            foreach (UserGroupsHelper::getInstance()->getAll() as $id => $group) {
                $title = \is_object($group) && isset($group->title) ? (string) $group->title : '';

                if ($title !== '') {
                    $titles[(int) $id] = $title;
                }
            }
        } catch (\Throwable) {
            // A title is decoration. Losing the catalogue must not lose the
            // assertion, because the keys are what a rule matches on.
            $titles = [];
        }

        $groups = [];

        foreach (Access::getGroupsByUser($userId, false) as $groupId) {
            $groupId = (int) $groupId;

            if ($groupId < 1) {
                continue;
            }

            $group = ['key' => (string) $groupId];

            if (isset($titles[$groupId])) {
                $group['label'] = $titles[$groupId];
            }

            $groups[] = $group;
        }

        return $groups;
    }
}
