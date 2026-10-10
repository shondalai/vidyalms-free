<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Users;

/**
 * Read access to CMS user accounts. The core stores CMS user ids as plain
 * integers and never joins into CMS tables.
 */
interface UsersPort
{
    public function findById(int $id): ?UserAccount;

    public function findByEmail(string $email): ?UserAccount;

    public function exists(int $id): bool;

    /**
     * Display names for a set of ids, keyed by id, in one lookup.
     *
     * A roster page resolves twenty names and a name sort resolves a whole
     * course's, and neither may become twenty or ten thousand calls to
     * findById(): the core cannot join into the host's user table, so the
     * only way a name lookup stays one round trip is for the port to take
     * the whole set. Ids with no account are absent from the result rather
     * than mapped to null, because "the CMS account is gone" and "the person
     * has an empty name" are different facts.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function namesFor(array $ids): array;

    /**
     * Whole account records for a set of ids, keyed by id, in one lookup.
     * Missing host accounts are absent. A learner directory needs email as
     * well as name and must not turn one database page into one host-user
     * query per row.
     *
     * @param list<int> $ids
     *
     * @return array<int, UserAccount>
     */
    public function accountsFor(array $ids): array;

    /**
     * Every host account id whose display name, username or email contains
     * the literal term, ordered by id. Unlike search(), this has no result
     * cap: it is the filter input to a server-paged learner directory, so a
     * cap would make its total and later pages false.
     *
     * The method returns ids rather than accounts because only the learning
     * database knows which host accounts are actual learners. It intersects
     * this set with distinct enrolment user ids before hydrating one page.
     *
     * @return list<int>
     */
    public function idsMatching(string $term): array;

    /**
     * Accounts whose display name, username or email contains the term, id
     * ascending, at most $limit of them. The core cannot query the host's
     * user table itself, so the adapter answers from its own user store.
     *
     * The three columns are the contract, not the adapter's preference: a
     * term must find the same people on every host, so an adapter neither
     * drops one of them nor adds a fourth.
     *
     * The term is data, never a pattern; an adapter escapes whatever its
     * store treats as a wildcard, the term's own wildcards included.
     *
     * @return list<UserAccount>
     */
    public function search(string $term, int $limit): array;

    /**
     * The narrow form of the same lookup: accounts whose display name or
     * username STARTS WITH the term, plus the one account whose email is
     * exactly the term. Same columns, same ordering, same escaping.
     *
     * This exists because a substring match over an email column is a site
     * directory: two characters of a shared domain return most of a user
     * table. SearchUsers gives this form to a caller who may enrol but does
     * not manage the site, which still serves the enrol dialog, because a
     * person being invited is known by name or by address.
     *
     * @return list<UserAccount>
     */
    public function matchNamePrefixOrEmail(string $term, int $limit): array;
}
