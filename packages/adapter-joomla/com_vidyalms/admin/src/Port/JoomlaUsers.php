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

use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Shondalai\VidyaLMS\Core\Port\Users\UserAccount;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * Read access to Joomla accounts.
 *
 * Joomla's user factory answers a missing id with a guest object rather than
 * null, so every lookup is filtered: the core must never receive a guest
 * dressed up as an account.
 *
 * @since 1.0.0
 */
final class JoomlaUsers implements UsersPort
{
    public function __construct(
        private readonly UserFactoryInterface $users,
        private readonly DatabaseInterface $db,
    ) {
    }

    public function findById(int $id): ?UserAccount
    {
        if ($id <= 0) {
            return null;
        }

        return $this->accountFrom($this->users->loadUserById($id));
    }

    public function findByEmail(string $email): ?UserAccount
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        $query = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__users'))
            ->where($this->db->quoteName('email') . ' = :email')
            ->bind(':email', $email, ParameterType::STRING)
            ->setLimit(1);

        $id = (int) $this->db->setQuery($query)->loadResult();

        return $id > 0 ? $this->findById($id) : null;
    }

    public function exists(int $id): bool
    {
        return $this->findById($id) !== null;
    }

    /**
     * One statement for the whole set, never one per id.
     *
     * A roster page resolves twenty names and a name sort resolves a whole
     * course's, and loadUserById() is a query and an object per call. The
     * names are read straight off #__users, which is what UserFactory would
     * have read, and a blocked account still has a name: this is a display
     * lookup for rows that already exist, not the enrol dialog's search, so
     * leaving a blocked person nameless would only put an id on a screen.
     *
     * Ids with no row are absent from the result rather than mapped to null,
     * as the port requires: "the account is gone" and "the name is empty" are
     * different facts.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function namesFor(array $ids): array
    {
        $wanted = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $wanted[$id] = $id;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $query = $this->db->createQuery()
            ->select($this->db->quoteName(['id', 'name']))
            ->from($this->db->quoteName('#__users'))
            ->whereIn($this->db->quoteName('id'), array_values($wanted), ParameterType::INTEGER);

        $rows = $this->db->setQuery($query)->loadAssocList();
        $names = [];

        foreach ($rows === null ? [] : $rows as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, UserAccount>
     */
    public function accountsFor(array $ids): array
    {
        $wanted = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $wanted[$id] = $id;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $query = $this->db->createQuery()
            ->select($this->db->quoteName(['id', 'name', 'email']))
            ->from($this->db->quoteName('#__users'))
            ->whereIn($this->db->quoteName('id'), array_values($wanted), ParameterType::INTEGER)
            ->order($this->db->quoteName('id') . ' ASC');

        $rows = $this->db->setQuery($query)->loadAssocList();
        $accounts = [];

        foreach ($rows === null ? [] : $rows as $row) {
            $id = (int) $row['id'];
            $accounts[$id] = new UserAccount($id, (string) $row['name'], (string) $row['email']);
        }

        return $accounts;
    }

    /**
     * @return list<int>
     */
    public function idsMatching(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $pattern = '%' . addcslashes($term, '\\%_') . '%';
        $placeholders = [':name', ':username', ':email'];
        $values = [$pattern, $pattern, $pattern];
        $condition = '(' . $this->db->quoteName('name') . " LIKE :name ESCAPE '\\\\'"
            . ' OR ' . $this->db->quoteName('username') . " LIKE :username ESCAPE '\\\\'"
            . ' OR ' . $this->db->quoteName('email') . " LIKE :email ESCAPE '\\\\')";

        $query = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__users'))
            ->where($condition)
            ->order($this->db->quoteName('id') . ' ASC')
            ->bind($placeholders, $values, ParameterType::STRING);

        return array_map(
            static fn (mixed $id): int => (int) $id,
            $this->db->setQuery($query)->loadColumn() ?? [],
        );
    }

    /**
     * Blocked accounts are left out: an enrol dialog that offered one would
     * be offering a person who cannot sign in.
     *
     * @return list<UserAccount>
     */
    public function search(string $term, int $limit): array
    {
        $term = trim($term);

        if ($term === '' || $limit < 1) {
            return [];
        }

        // The term is data, never a pattern: the LIKE metacharacters and the
        // escape character are escaped before the wildcards are added.
        $pattern = '%' . addcslashes($term, '\\%_') . '%';

        return $this->accounts(
            '(' . $this->db->quoteName('name') . " LIKE :name ESCAPE '\\\\'"
            . ' OR ' . $this->db->quoteName('username') . " LIKE :username ESCAPE '\\\\'"
            . ' OR ' . $this->db->quoteName('email') . " LIKE :email ESCAPE '\\\\')",
            [':name' => $pattern, ':username' => $pattern, ':email' => $pattern],
            $limit,
        );
    }

    /**
     * The narrow match: a prefix over name and username, plus an exact email.
     * Same columns and same ordering as search(), so the two differ only in how
     * much of the term has to line up.
     *
     * @return list<UserAccount>
     */
    public function matchNamePrefixOrEmail(string $term, int $limit): array
    {
        $term = trim($term);

        if ($term === '' || $limit < 1) {
            return [];
        }

        $prefix = addcslashes($term, '\\%_') . '%';

        return $this->accounts(
            '(' . $this->db->quoteName('name') . " LIKE :name ESCAPE '\\\\'"
            . ' OR ' . $this->db->quoteName('username') . " LIKE :username ESCAPE '\\\\'"
            . ' OR ' . $this->db->quoteName('email') . ' = :email)',
            [':name' => $prefix, ':username' => $prefix, ':email' => $term],
            $limit,
        );
    }

    /**
     * @param array<string, string> $bindings placeholder to value
     *
     * @return list<UserAccount>
     */
    private function accounts(string $condition, array $bindings, int $limit): array
    {
        // Bound in one call with two arrays. DatabaseQuery::bind() takes the
        // value BY REFERENCE, so binding inside a foreach would hand every
        // placeholder a reference to the one loop variable and the last value
        // would win for all of them.
        $placeholders = array_keys($bindings);
        $values = array_values($bindings);

        $query = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__users'))
            ->where($condition)
            ->where($this->db->quoteName('block') . ' = 0')
            ->order($this->db->quoteName('id') . ' ASC')
            ->bind($placeholders, $values, ParameterType::STRING)
            ->setLimit($limit);

        $ids = $this->db->setQuery($query)->loadColumn();
        $accounts = [];

        foreach ($ids === null ? [] : $ids as $id) {
            $account = $this->findById((int) $id);

            if ($account !== null) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    private function accountFrom(User $user): ?UserAccount
    {
        $id = (int) $user->id;

        if ($id <= 0 || (int) $user->guest === 1) {
            return null;
        }

        return new UserAccount($id, (string) $user->name, (string) $user->email);
    }
}
