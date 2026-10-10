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

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\Table\Usergroup;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Creating a user group under Registered and granting it actions on an asset,
 * as a service any package can call.
 *
 * The whole of it was private to pkg_vidyalms_pro's install script with the
 * group title welded into a constant and the asset name written inline, so a
 * second package wanting a group of its own (a proctoring add-on with a
 * Proctors group, a third-party question type with its own role) had nothing
 * to call. The adopt-or-create rule, the Registered-parent resolution and the
 * grant-only-where-the-asset-is-silent rule are all generic, and this is where
 * they live now. The Pro installer passes its own title, its own parent and
 * its own action list.
 *
 * Three rules it keeps, in order of how much damage breaking one would do:
 *
 * - **It never modifies a group somebody else made.** A group of that title
 *   under the resolved parent is this package's own and is adopted. One of
 *   that title anywhere else in the tree is somebody else's, and is refused
 *   with the group named rather than silently handed four capabilities over a
 *   whole component to members this installer has never seen.
 * - **It never overwrites an existing rule.** An action is granted only where
 *   the asset says nothing about this group yet, so an administrator who
 *   deliberately denied one keeps the denial across every future update.
 * - **It never throws.** A site whose ACL cannot be read or written answers
 *   with a refusal an installer reports; refusing an install over a group
 *   would be an installer inventing a requirement.
 */
final class UserGroupProvisioner
{
    /** What ensure() did, for the message the caller shows. */
    public const CREATED = 'created';
    public const ADOPTED = 'adopted';

    /** A group of that title exists outside the parent and is not ours. */
    public const FOREIGN = 'foreign';

    public const FAILED = 'failed';

    private function __construct(
        private readonly DatabaseInterface $database,
    ) {
    }

    public static function create(?DatabaseInterface $database = null): ?self
    {
        if ($database !== null) {
            return new self($database);
        }

        try {
            $resolved = Factory::getContainer()->get(DatabaseInterface::class);
        } catch (\Throwable) {
            return null;
        }

        return $resolved instanceof DatabaseInterface ? new self($resolved) : null;
    }

    /**
     * The group new registrations land in, which is what "under Registered"
     * means on a site that renamed or renumbered it.
     *
     * com_users names it in new_usertype; a site that has never saved those
     * options has no value there, so the title is the fallback and neither is
     * guessed at.
     */
    public function registeredGroupId(): int
    {
        try {
            $configured = (int) ComponentHelper::getParams('com_users')->get('new_usertype', 0);
        } catch (\Throwable) {
            $configured = 0;
        }

        if ($configured > 0 && $this->groupExists($configured)) {
            return $configured;
        }

        try {
            $query = $this->database->createQuery()
                ->select($this->database->quoteName('id'))
                ->from($this->database->quoteName('#__usergroups'))
                ->where($this->database->quoteName('title') . ' = ' . $this->database->quote('Registered'))
                ->setLimit(1);

            return (int) $this->database->setQuery($query)->loadResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The group of that title under that parent, created when it is not there.
     *
     * @return array{id: int, outcome: string, foreign_id: int, foreign_parent: int}
     */
    public function ensure(string $title, int $parentId): array
    {
        $refused = ['id' => 0, 'outcome' => self::FAILED, 'foreign_id' => 0, 'foreign_parent' => 0];

        if ($title === '' || $parentId <= 0) {
            return $refused;
        }

        try {
            $query = $this->database->createQuery()
                ->select($this->database->quoteName(['id', 'parent_id']))
                ->from($this->database->quoteName('#__usergroups'))
                ->where($this->database->quoteName('title') . ' = :title')
                ->bind(':title', $title, ParameterType::STRING)
                ->setLimit(1);

            /** @var array{id: int|string, parent_id: int|string}|null $existing */
            $existing = $this->database->setQuery($query)->loadAssoc();
        } catch (\Throwable) {
            return $refused;
        }

        if ($existing !== null) {
            // Scoped to the parent this package resolved. A group of that
            // title elsewhere in the tree has members and a parent nobody here
            // chose, and adopting it granted four teaching capabilities over
            // the whole component to people the installer never saw.
            // A matching title and parent do not prove package ownership.
            // Never grant component permissions to an existing site's group.

            return [
                'id' => 0,
                'outcome' => self::FOREIGN,
                'foreign_id' => (int) $existing['id'],
                'foreign_parent' => (int) $existing['parent_id'],
            ];
        }

        try {
            $table = new Usergroup($this->database);

            if (!$table->save(['id' => 0, 'parent_id' => $parentId, 'title' => $title])) {
                return $refused;
            }

            return ['id' => (int) $table->id, 'outcome' => self::CREATED, 'foreign_id' => 0, 'foreign_parent' => 0];
        } catch (\Throwable) {
            return $refused;
        }
    }

    /**
     * Grants actions on one asset, and only where that asset is silent about
     * this group.
     *
     * Read and written through Joomla's own Asset table rather than with a
     * bare UPDATE, so an administrator saving the component's Permissions
     * screen while an install runs and this writer cannot lose one side of
     * each other's work to a read-modify-write on a JSON column.
     *
     * @param list<string> $actions
     *
     * @return int how many actions this call added
     */
    public function grant(string $assetName, int $groupId, array $actions): int
    {
        if ($assetName === '' || $groupId <= 0 || $actions === []) {
            return 0;
        }

        try {
            $asset = new Asset($this->database);

            if (!$asset->loadByName($assetName)) {
                return 0;
            }

            /** @var mixed $decoded */
            $decoded = json_decode((string) $asset->rules, true);
            $rules = \is_array($decoded) ? $decoded : [];
            $added = 0;

            foreach ($actions as $action) {
                /** @var mixed $current */
                $current = $rules[$action] ?? null;
                $entries = \is_array($current) ? $current : [];

                // An explicit allow or deny for this group is somebody's
                // decision, and this is not the place to revisit it.
                if (\array_key_exists((string) $groupId, $entries)) {
                    continue;
                }

                $entries[(string) $groupId] = 1;
                $rules[$action] = $entries;
                ++$added;
            }

            if ($added === 0) {
                return 0;
            }

            $asset->rules = (string) json_encode($rules);

            return $asset->store() ? $added : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function groupExists(int $groupId): bool
    {
        try {
            $query = $this->database->createQuery()
                ->select($this->database->quoteName('id'))
                ->from($this->database->quoteName('#__usergroups'))
                ->where($this->database->quoteName('id') . ' = :id')
                ->bind(':id', $groupId, ParameterType::INTEGER)
                ->setLimit(1);

            return (int) $this->database->setQuery($query)->loadResult() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
