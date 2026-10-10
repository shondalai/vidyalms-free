<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

/**
 * One person's relationship with one foreign course: that they had access,
 * and which lessons they had finished.
 *
 * Access and history are one shape because in these three products they come
 * from the same place. OSCampus has no enrolment table at all in its
 * published schema: `oscampus_users_lessons` is both the record that somebody
 * was on the course and the record of what they did on it. Guru sells access
 * through `guru_buy` and records nothing else this importer can read. Shika
 * tracks lessons per user. Modelling an enrolment separately would have meant
 * inventing one for two of the three.
 *
 * ## What comes across, and why only this much
 *
 * Access becomes an entitlement, and completed lessons become lesson progress
 * through RecordLessonProgress. Nothing else does: no score, no attempt, no
 * time spent, no certificate. A migrated learner with the right access and
 * the lessons they actually finished is honest; a replayed grade is not,
 * because it would be dated to the import and would fire completion events
 * that never happened then.
 *
 * The completion timestamps go with the same reasoning applied one level
 * down: `completedLessonKeys` says which lessons were finished, and the free
 * build dates them to when the import wrote them, because RecordLessonProgress
 * has no back-dating parameter and inventing one for an importer would put a
 * migration's convenience ahead of the progress path's own rules.
 */
final class LearnerDraft
{
    /**
     * @param list<string> $completedLessonKeys the source keys of the lessons this person finished
     */
    private function __construct(
        public readonly string $key,
        public readonly int $userId,
        public readonly ?string $expiresAt,
        public readonly array $completedLessonKeys,
    ) {
    }

    /**
     * @param list<string> $completedLessonKeys
     */
    public static function of(
        string $key,
        int $userId,
        ?string $expiresAt = null,
        array $completedLessonKeys = [],
    ): self {
        return new self($key, $userId, $expiresAt, array_values(array_unique($completedLessonKeys)));
    }
}
