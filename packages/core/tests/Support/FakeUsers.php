<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Users\UserAccount;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

final class FakeUsers implements UsersPort
{
    /** @var array<int, UserAccount> */
    private array $users = [];

    /** @var array<int, string> */
    private array $usernames = [];

    /** How many times namesFor() was called, so a test can prove one lookup per page. */
    public int $nameLookups = 0;

    /**
     * How many times findById() was called. On both hosts that is a real
     * account load, and a list that makes one per row is the N+1 the set
     * shaped lookups exist to stop; an array lookup here would hide it, so
     * it is counted.
     */
    public int $accountLookups = 0;

    /** Set-shaped account loads, counted separately from findById(). */
    public int $accountSetLookups = 0;

    public function add(int $id, string $name = '', string $email = '', string $username = ''): UserAccount
    {
        $account = new UserAccount(
            $id,
            $name === '' ? 'User ' . $id : $name,
            $email === '' ? sprintf('user%d@example.test', $id) : $email,
        );

        $this->users[$id] = $account;
        $this->usernames[$id] = $username === '' ? 'user' . $id : $username;

        return $account;
    }

    public function findById(int $id): ?UserAccount
    {
        ++$this->accountLookups;

        return $this->users[$id] ?? null;
    }

    public function findByEmail(string $email): ?UserAccount
    {
        foreach ($this->users as $user) {
            if (strcasecmp($user->email, $email) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function exists(int $id): bool
    {
        return isset($this->users[$id]);
    }

    public function namesFor(array $ids): array
    {
        ++$this->nameLookups;

        $names = [];

        foreach ($ids as $id) {
            if (isset($this->users[$id])) {
                $names[$id] = $this->users[$id]->name;
            }
        }

        return $names;
    }

    public function accountsFor(array $ids): array
    {
        ++$this->accountSetLookups;

        $accounts = [];

        foreach ($ids as $id) {
            if (isset($this->users[$id])) {
                $accounts[$id] = $this->users[$id];
            }
        }

        return $accounts;
    }

    public function idsMatching(string $term): array
    {
        return array_map(
            static fn (UserAccount $account): int => $account->id,
            $this->matching(
                $term,
                PHP_INT_MAX,
                fn (UserAccount $user, string $needle): bool => stripos($user->name, $needle) !== false
                    || stripos($this->usernames[$user->id] ?? '', $needle) !== false
                    || stripos($user->email, $needle) !== false,
            ),
        );
    }

    public function search(string $term, int $limit): array
    {
        return $this->matching(
            $term,
            $limit,
            fn (UserAccount $user, string $needle): bool => stripos($user->name, $needle) !== false
                || stripos($this->usernames[$user->id] ?? '', $needle) !== false
                || stripos($user->email, $needle) !== false,
        );
    }

    public function matchNamePrefixOrEmail(string $term, int $limit): array
    {
        return $this->matching(
            $term,
            $limit,
            fn (UserAccount $user, string $needle): bool => stripos($user->name, $needle) === 0
                || stripos($this->usernames[$user->id] ?? '', $needle) === 0
                || strcasecmp($user->email, $needle) === 0,
        );
    }

    /**
     * @param callable(UserAccount, string): bool $matches
     *
     * @return list<UserAccount>
     */
    private function matching(string $term, int $limit, callable $matches): array
    {
        $term = trim($term);

        if ($term === '' || $limit < 1) {
            return [];
        }

        $found = [];

        foreach ($this->users as $user) {
            if ($matches($user, $term)) {
                $found[] = $user;
            }
        }

        usort($found, static fn (UserAccount $left, UserAccount $right): int => $left->id <=> $right->id);

        return \array_slice($found, 0, $limit);
    }
}
