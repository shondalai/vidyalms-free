<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * What narrows the site-wide certificate list at the database.
 *
 * Two of the six statuses are not stored columns: expired and not yet valid
 * are the clock's answer against expires_at and valid_from, so the criteria
 * carries the moment it is judged against and the repository turns that into
 * predicates. Nothing is ever rewritten to make an expiry true.
 *
 * "valid" survives as a filter value beside "active" because it is what the
 * admin screen has always sent and what the contract still names; it means
 * the same thing.
 *
 * The search matches a verification code exactly or a person, and a person
 * lives in the host CMS, so the caller resolves the term through the users
 * port and passes the ids here.
 */
final class CertificateCriteria
{
    /** The derived convenience value, equal to active. */
    public const STATUS_VALID = 'valid';

    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Every value the filter accepts: the six of the status enumeration plus
     * the convenience value.
     *
     * @return list<string>
     */
    public static function allowedStatuses(): array
    {
        return array_merge(CredentialStatus::allValues(), [self::STATUS_VALID]);
    }

    /**
     * @param list<int>|null $userIds null means every user; an empty list
     *                                means the term matched nobody
     */
    public function __construct(
        public readonly \DateTimeImmutable $asOf,
        public readonly ?int $courseId = null,
        public readonly ?string $status = null,
        public readonly ?string $code = null,
        public readonly ?array $userIds = null,
        public readonly ?int $templateId = null,
    ) {
    }

    /**
     * @param list<int> $userIds
     */
    public function searchingFor(string $code, array $userIds): self
    {
        return new self($this->asOf, $this->courseId, $this->status, $code, $userIds, $this->templateId);
    }
}
