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
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Port\Authorization\HoldersOfCapability;

/**
 * The core's capability vocabulary answered by Joomla's ACL.
 *
 * Two capabilities ride on Joomla's own actions so that a Super User and a
 * component manager need no extra configuration; the rest are custom actions
 * declared in admin/access.xml.
 *
 * Joomla's ACL has no notion of one action implying another, and custom
 * actions are denied by default because the global configuration knows nothing
 * about them. Left at that, a group granted core.manage could open the
 * component and then find every screen refusing it, which is not what
 * "manage this component" means to a Joomla administrator. So the two
 * administration actions imply the component's capabilities:
 *
 *   core.admin   grants every VidyaLMS capability
 *   core.manage  grants every VidyaLMS capability except vidyalms.admin
 *
 * A site owner still delegates finer rights by granting the individual
 * vidyalms.* actions to groups that hold neither administration action, which
 * is the normal way to give someone only teaching or only grading.
 *
 * A resource reference of the form "course:42" is checked against the asset
 * com_vidyalms.course.42, falling back to the component asset when no
 * per-course asset has been created. Without the fallback Joomla would resolve
 * an unknown asset name against the root asset and lose the component's rules.
 *
 * @since 1.0.0
 */
final class JoomlaAuthorization implements AuthorizationPort, HoldersOfCapability
{
    public const COMPONENT_ASSET = 'com_vidyalms';

    /**
     * Core capability to Joomla ACL action.
     *
     * @var array<string, string>
     */
    private const ACTIONS = [
        Capability::ADMIN => 'core.admin',
        Capability::MANAGE => 'core.manage',
        Capability::TEACH => 'vidyalms.teach',
        Capability::GRADE => 'vidyalms.grade',
        Capability::ENROL_OTHERS => 'vidyalms.enrol',
        Capability::VIEW_REPORTS => 'vidyalms.reports',
        Capability::MANAGE_GROUPS => 'vidyalms.manage_groups',
    ];

    /**
     * Joomla actions that grant any capability other than Capability::ADMIN,
     * checked after the capability's own action.
     *
     * @var list<string>
     */
    private const ADMINISTRATION_ACTIONS = ['core.admin', 'core.manage'];

    /** @var array<string, bool> */
    private array $assetCache = [];

    public function __construct(
        private readonly UserFactoryInterface $users,
        private readonly DatabaseInterface $db,
    ) {
    }

    public function can(int $userId, string $capability, ?string $resource = null): bool
    {
        if ($userId <= 0 || !self::supportedResource($resource)) {
            return false;
        }

        $action = $this->actionFor($capability);

        if ($action === null) {
            return false;
        }

        $user = $this->users->loadUserById($userId);

        if ((int) $user->id !== $userId || (int) $user->block === 1) {
            return false;
        }

        $asset = $this->assetFor($resource);

        if ((bool) $user->authorise($action, $asset)) {
            return true;
        }

        // Checked against the same asset, so a course-scoped question is
        // answered by a course-scoped grant, and the component grant reaches
        // it through Joomla's own asset inheritance.
        foreach ($this->implyingActions($action) as $implying) {
            if ((bool) $user->authorise($implying, $asset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function implyingActions(string $action): array
    {
        if ($action === 'core.admin') {
            return [];
        }

        return array_values(array_diff(self::ADMINISTRATION_ACTIONS, [$action]));
    }

    /**
     * The reverse direction: who holds a capability.
     *
     * The free site_managers audience is the only consumer, and without an
     * implementation on this host it resolved empty on every install and
     * counted one suppression, so a free provider had no free code path that
     * could reach it. It is also the seam a third adapter would otherwise
     * reinvent, so the scope semantics are the same as can()'s: the resource
     * reference resolves to the same asset, falling back to the component
     * asset when no per-course asset exists.
     *
     * Joomla has no reverse index from an action to users, so the candidate
     * set is the groups the asset's rules allow the action to, and the answer
     * is the users in those groups plus every Super User (core.admin implies
     * everything, and a site with one administrator and no group grant must
     * still resolve a manager). Each candidate is confirmed through the same
     * authorise() call can() makes, so the two answers cannot disagree.
     *
     * @return list<int>
     */
    public function holdersOf(string $capability, ?string $resource, int $limit, int $offset): array
    {
        $action = $this->actionFor($capability);

        if ($action === null || $limit < 1 || !self::supportedResource($resource)) {
            return [];
        }

        $groups = $this->groupsAllowed($action, $this->assetFor($resource));

        if ($groups === []) {
            return [];
        }

        $query = $this->db->createQuery()
            ->select('DISTINCT ' . $this->db->quoteName('m.user_id'))
            ->from($this->db->quoteName('#__user_usergroup_map', 'm'))
            ->join(
                'INNER',
                $this->db->quoteName('#__users', 'u')
                . ' ON ' . $this->db->quoteName('u.id') . ' = ' . $this->db->quoteName('m.user_id'),
            )
            ->where($this->db->quoteName('u.block') . ' = 0')
            ->whereIn($this->db->quoteName('m.group_id'), $groups)
            ->order($this->db->quoteName('m.user_id') . ' ASC');

        $this->db->setQuery($query);

        /** @var list<int|string> $candidates */
        $candidates = $this->db->loadColumn() ?: [];
        $holders = [];
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $userId = (int) $candidate;

            if ($userId < 1 || !$this->can($userId, $capability, $resource)) {
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

    /**
     * The groups an asset's rules allow an action to, and every group beneath
     * them, plus the groups any implying action reaches.
     *
     * Joomla exposes no reverse index from an action to users, so the rules
     * of the asset are the candidate set. Descendants are included because
     * Joomla's ACL inherits down the group tree: a rule granted to Manager
     * reaches Administrator without naming it, and a candidate set that
     * stopped at the named group would miss exactly the accounts a site
     * actually uses. Every candidate is confirmed through can() afterwards,
     * so an over-wide candidate set costs a check and never a wrong answer.
     *
     * @return list<int>
     */
    private function groupsAllowed(string $action, string $asset): array
    {
        $named = [];

        foreach ([$action, ...$this->implyingActions($action), 'core.admin'] as $candidateAction) {
            $rule = Access::getAssetRules($asset, true)->getData()[$candidateAction] ?? null;

            if ($rule === null) {
                continue;
            }

            foreach ($rule->getData() as $groupId => $allowed) {
                if ((bool) $allowed) {
                    $named[(int) $groupId] = true;
                }
            }
        }

        if ($named === []) {
            return [];
        }

        return $this->withDescendants(array_keys($named));
    }

    /**
     * @param list<int> $groupIds
     *
     * @return list<int>
     */
    private function withDescendants(array $groupIds): array
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName(['child.id']))
            ->from($this->db->quoteName('#__usergroups', 'child'))
            ->join(
                'INNER',
                $this->db->quoteName('#__usergroups', 'parent')
                . ' ON ' . $this->db->quoteName('child.lft') . ' >= ' . $this->db->quoteName('parent.lft')
                . ' AND ' . $this->db->quoteName('child.rgt') . ' <= ' . $this->db->quoteName('parent.rgt'),
            )
            ->whereIn($this->db->quoteName('parent.id'), $groupIds);

        $this->db->setQuery($query);

        /** @var list<int|string> $rows */
        $rows = $this->db->loadColumn() ?: [];
        $all = [];

        foreach ([...$groupIds, ...$rows] as $groupId) {
            $all[(int) $groupId] = true;
        }

        return array_keys($all);
    }

    /**
     * Unknown vidyalms.* capabilities pass through as custom actions so a Pro
     * package can add an action in its own access.xml without editing this map.
     */
    private function actionFor(string $capability): ?string
    {
        if (isset(self::ACTIONS[$capability])) {
            return self::ACTIONS[$capability];
        }

        return str_starts_with($capability, 'vidyalms.') ? $capability : null;
    }

    private function assetFor(?string $resource): string
    {
        if ($resource === null || $resource === '') {
            return self::COMPONENT_ASSET;
        }

        $parts = explode(':', $resource, 2);

        if (\count($parts) !== 2 || $parts[0] !== 'course' || preg_match('/^[1-9][0-9]*$/', $parts[1]) !== 1) {
            return self::COMPONENT_ASSET;
        }

        $asset = self::COMPONENT_ASSET . '.course.' . $parts[1];

        return $this->assetExists($asset) ? $asset : self::COMPONENT_ASSET;
    }

    private static function supportedResource(?string $resource): bool
    {
        return $resource === null || $resource === ''
            || (preg_match('/^course:[1-9][0-9]{0,9}$/D', $resource) === 1
                && (int) substr($resource, 7) <= 2147483647);
    }

    private function assetExists(string $asset): bool
    {
        if (isset($this->assetCache[$asset])) {
            return $this->assetCache[$asset];
        }

        $query = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__assets'))
            ->where($this->db->quoteName('name') . ' = :name')
            ->bind(':name', $asset, ParameterType::STRING)
            ->setLimit(1);

        return $this->assetCache[$asset] = (int) $this->db->setQuery($query)->loadResult() > 0;
    }
}
