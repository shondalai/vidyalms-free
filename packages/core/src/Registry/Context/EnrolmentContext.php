<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * What an enrolment rule may read: who is asking, the whole course row, the
 * clock and a database handle for a rule that has its own tables.
 *
 * The course row is passed whole rather than as the two columns the free
 * rule happens to read, because a rule replaced by a site policy or a paid
 * package may read any column, and a caller that hand-built a partial row
 * would offer a button the POST refuses.
 */
final class EnrolmentContext
{
    /**
     * @param array<string, mixed> $course
     */
    private function __construct(
        public readonly Principal $principal,
        public readonly array $course,
        public readonly \DateTimeImmutable $now,
        public readonly DatabasePort $database,
        public readonly bool $siteAllowsSelfEnrolment,
    ) {
    }

    /**
     * @param array<string, mixed> $course
     */
    public static function create(
        Principal $principal,
        array $course,
        \DateTimeImmutable $now,
        DatabasePort $database,
        bool $siteAllowsSelfEnrolment = true,
    ): self {
        return new self($principal, $course, $now, $database, $siteAllowsSelfEnrolment);
    }

    public function courseId(): int
    {
        $id = $this->course['id'] ?? 0;

        return \is_numeric($id) ? (int) $id : 0;
    }
}
