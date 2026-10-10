<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * Collects the learner ids a listing mentions and resolves all of them in
 * one users call.
 *
 * The core cannot join into the host's user table, so a display name is a
 * port call. A queue that resolves a name the moment it builds a row makes
 * one such call per learner, which is a hundred round trips into the CMS for
 * a hundred-row queue. Naming the ids first and asking once is the whole
 * point of UsersPort::namesFor().
 */
final class LearnerNames
{
    /** @var array<int, true> */
    private array $wanted = [];

    /** @var array<int, string> */
    private array $names = [];

    public function __construct(
        private readonly UsersPort $users,
    ) {
    }

    public function want(int $userId): void
    {
        if ($userId > 0) {
            $this->wanted[$userId] = true;
        }
    }

    public function resolve(): void
    {
        if ($this->wanted === []) {
            return;
        }

        $this->names = $this->users->namesFor(array_map(\intval(...), array_keys($this->wanted)));
        $this->wanted = [];
    }

    /**
     * Null when the CMS account is gone, which is a different fact from a
     * person with an empty name.
     */
    public function of(int $userId): ?string
    {
        return $this->names[$userId] ?? null;
    }
}
