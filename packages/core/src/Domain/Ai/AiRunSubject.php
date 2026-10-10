<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * One person whose data a run carried.
 *
 * Written by free code inside the same transactional() call as the run row,
 * because a subject row that can arrive later is a subject row that can be
 * missing when the right-to-know view asks.
 *
 * The role distinction is not decoration. A subject is a person whose data was
 * sent; a contributor is a person whose authored material was sent. Both have to
 * be findable, and only one of them is answering an Article 15 request about
 * their own submission.
 */
final class AiRunSubject
{
    public const ROLE_SUBJECT = 'subject';
    public const ROLE_CONTRIBUTOR = 'contributor';

    private function __construct(
        public readonly int $userId,
        public readonly string $role,
    ) {
    }

    public static function subject(int $userId): self
    {
        return new self($userId, self::ROLE_SUBJECT);
    }

    public static function contributor(int $userId): self
    {
        return new self($userId, self::ROLE_CONTRIBUTOR);
    }

    /**
     * The distinct set a run should write, deduplicated with subject winning
     * over contributor for the same person.
     *
     * A learner who both submitted the work and authored a comment inside it is
     * one row, and it is the row that says their data was sent: the unique index
     * is on (run_id, user_id) alone, so a caller that produced both would
     * otherwise take the arbitrary one that reached the insert first.
     *
     * @param list<self> $subjects
     *
     * @return list<self>
     */
    public static function distinct(array $subjects): array
    {
        $byUser = [];

        foreach ($subjects as $entry) {
            $existing = $byUser[$entry->userId] ?? null;

            if ($existing !== null && $existing->role === self::ROLE_SUBJECT) {
                continue;
            }

            $byUser[$entry->userId] = $entry;
        }

        ksort($byUser);

        return array_values($byUser);
    }
}
