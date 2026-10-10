<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access;

/**
 * What narrows an enrolment listing at the database.
 *
 * A person's name lives in the host CMS, so a search on it cannot be a
 * WHERE clause over our tables. It becomes one anyway: the caller asks the
 * users port who matches the term and passes the ids here, which turns a
 * CMS-side search into an ordinary IN over enrolments and keeps the page cut
 * at the database. Only a sort by name still needs every name at once, and
 * that one carries a documented ceiling.
 */
final class EnrolmentCriteria
{
    /**
     * @param list<int>|null $userIds null means every user; an empty list
     *                                means no user matched and the listing is
     *                                empty
     */
    public function __construct(
        public readonly ?int $courseId = null,
        public readonly ?string $status = null,
        public readonly ?int $userId = null,
        public readonly ?\DateTimeImmutable $expiringAfter = null,
        public readonly ?\DateTimeImmutable $expiringBefore = null,
        public readonly ?array $userIds = null,
    ) {
    }

    /**
     * @param list<int> $userIds
     */
    public function narrowedToUsers(array $userIds): self
    {
        return new self(
            $this->courseId,
            $this->status,
            $this->userId,
            $this->expiringAfter,
            $this->expiringBefore,
            $userIds,
        );
    }
}
