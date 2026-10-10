<?php

declare(strict_types=1);

/**
 * VidyaLMS: the users port over WP_User.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\Users\UserAccount;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

defined('ABSPATH') || exit;

/**
 * Read access to WordPress accounts.
 *
 * The core stores WordPress user ids as plain integers and never joins into
 * wp_users, so this is the only place the two meet. A missing user is null, not
 * an empty WP_User: nothing downstream should have to tell those apart.
 *
 * Profile fields stay in WordPress. The core sees an id, a display name and an
 * email and asks for nothing else.
 */
final class WpUsers implements UsersPort
{
    public function findById(int $id): ?UserAccount
    {
        if ($id <= 0) {
            return null;
        }

        $user = get_userdata($id);

        return $user instanceof \WP_User ? $this->accountFrom($user) : null;
    }

    public function findByEmail(string $email): ?UserAccount
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        $user = get_user_by('email', $email);

        return $user instanceof \WP_User ? $this->accountFrom($user) : null;
    }

    public function exists(int $id): bool
    {
        return $this->findById($id) !== null;
    }

    /**
     * One query for the whole set, never one per id.
     *
     * A roster page resolves twenty names and a name sort resolves a whole
     * course's, and get_userdata() is a query per call unless the object cache
     * happens to be warm. WP_User_Query with an include list is one statement
     * and primes that cache for whatever asks next.
     *
     * The empty display_name fallback is accountFrom()'s, so a name here and a
     * name in an enrolment payload are the same string.
     *
     * Ids with no account are absent from the result rather than mapped to
     * null, as the port requires: "the account is gone" and "the name is
     * empty" are different facts.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function namesFor(array $ids): array
    {
        $wanted = array();

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $wanted[$id] = $id;
            }
        }

        if ($wanted === array()) {
            return array();
        }

        $query = new \WP_User_Query(
            array(
                'include' => array_values($wanted),
                // include is the whole filter: no search, no role and no
                // number, because a caller asking for forty ids means forty.
                'number'  => \count($wanted),
                'orderby' => 'ID',
                'order'   => 'ASC',
                'fields'  => 'all',
            )
        );

        $names = array();

        foreach ($query->get_results() as $user) {
            if (!$user instanceof \WP_User) {
                continue;
            }

            $account = $this->accountFrom($user);

            if ($account !== null) {
                $names[$account->id] = $account->name;
            }
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
        $wanted = array();

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $wanted[$id] = $id;
            }
        }

        if ($wanted === array()) {
            return array();
        }

        $query = new \WP_User_Query(
            array(
                'include' => array_values($wanted),
                'number'  => \count($wanted),
                'orderby' => 'ID',
                'order'   => 'ASC',
                'fields'  => 'all',
            )
        );

        $accounts = array();

        foreach ($query->get_results() as $user) {
            if (!$user instanceof \WP_User) {
                continue;
            }

            $account = $this->accountFrom($user);

            if ($account !== null) {
                $accounts[$account->id] = $account;
            }
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
            return array();
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        // WP_User_Query treats a leading or trailing asterisk as syntax.
        // The port promises the term is data, so use esc_like() directly and
        // add only our own two wildcards.
        $pattern = '%' . $wpdb->esc_like($term) . '%';
        $sql = $wpdb->prepare(
            'SELECT ID FROM %i'
            . ' WHERE display_name LIKE %s OR user_login LIKE %s OR user_email LIKE %s'
            . ' ORDER BY ID ASC',
            $wpdb->users,
            $pattern,
            $pattern,
            $pattern
        );

        if (!\is_string($sql)) {
            return array();
        }

        return array_map(
            static fn ($id): int => (int) $id,
            // Identifier search avoids loading full user objects; prepared matching must read the current account set.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->get_col($sql)
        );
    }

    /**
     * WP_User_Query does the matching, so the search follows whatever the site
     * has configured for its own user list rather than a second rule here. The
     * term is data: search_columns is fixed and the wildcards are ours.
     *
     * @return list<UserAccount>
     */
    public function search(string $term, int $limit): array
    {
        $term = self::literal($term);

        if ($term === '' || $limit < 1) {
            return array();
        }

        return $this->accounts('*' . $term . '*', $limit);
    }

    /**
     * The narrow match: a prefix over the name columns, plus an exact email.
     *
     * WP_User_Query's search cannot express "prefix on these columns OR equals
     * on that one" in a single query, so it is two: the prefix search over the
     * name columns, and get_user_by('email'), which is an exact lookup by
     * definition. Merged on id, ordered by id, then capped, so the answer is
     * shaped exactly like search()'s.
     *
     * @return list<UserAccount>
     */
    public function matchNamePrefixOrEmail(string $term, int $limit): array
    {
        $term = self::literal($term);

        if ($term === '' || $limit < 1) {
            return array();
        }

        $found = array();

        foreach ($this->accounts($term . '*', $limit, array('user_login', 'display_name')) as $account) {
            $found[$account->id] = $account;
        }

        $exact = $this->findByEmail($term);

        if ($exact !== null) {
            $found[$exact->id] = $exact;
        }

        ksort($found);

        return array_slice(array_values($found), 0, $limit);
    }

    /**
     * WP_User_Query evaluates "is there a search term" BEFORE it trims the
     * asterisks off it (class-wp-user-query.php, prepare_query), so a term of
     * nothing but asterisks becomes the pattern "%%" and matches every row.
     * A term is data, never a pattern, so its own asterisks come off here.
     */
    private static function literal(string $term): string
    {
        return trim(trim($term), '*');
    }

    /**
     * @param array<int, string> $columns
     *
     * @return list<UserAccount>
     */
    private function accounts(string $search, int $limit, ?array $columns = null): array
    {
        $query = new \WP_User_Query(
            array(
                'search'         => $search,
                // The three the Joomla port matches too, so a term answers the
                // same on both hosts. user_nicename is a slug WordPress derives
                // from the login and no other host has one, so it is not here.
                'search_columns' => $columns ?? array('user_login', 'user_email', 'display_name'),
                'number'         => $limit,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'all',
            )
        );

        $accounts = array();

        foreach ($query->get_results() as $user) {
            if (!$user instanceof \WP_User) {
                continue;
            }

            $account = $this->accountFrom($user);

            if ($account !== null) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * display_name is nullable in practice (a user created through an import can
     * carry an empty one), and the core's mail rendering prints it, so the login
     * name stands in rather than an empty greeting.
     */
    private function accountFrom(\WP_User $user): ?UserAccount
    {
        $id = (int) $user->ID;

        if ($id <= 0) {
            return null;
        }

        $name = trim((string) $user->display_name);

        if ($name === '') {
            $name = (string) $user->user_login;
        }

        return new UserAccount($id, $name, (string) $user->user_email);
    }
}
