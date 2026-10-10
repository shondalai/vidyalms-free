<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * Resolves the person and the course behind a set of certificates, in a
 * fixed number of lookups rather than one pair per row.
 *
 * Holds no authorization; every caller is a use case that already applied
 * its own rule.
 */
final class CertificateRecords
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly UsersPort $users,
    ) {
    }

    /**
     * @param list<Certificate> $certificates
     *
     * @return list<CertificateRecord>
     */
    public function forCertificates(array $certificates, \DateTimeImmutable $now): array
    {
        if ($certificates === []) {
            return [];
        }

        $courseIds = array_values(array_unique(array_map(
            static fn (Certificate $certificate): int => $certificate->courseId,
            $certificates,
        )));

        $courses = [];

        foreach ($this->courses->byIds($courseIds) as $course) {
            $courses[$course->id] = $course;
        }

        $userIds = [];

        foreach ($certificates as $certificate) {
            $userIds[$certificate->userId] = true;
        }

        // One users lookup for the page, the same rule the roster follows: a
        // list of a hundred certificates is not a hundred reads of the host
        // user table.
        $names = $this->users->namesFor(array_keys($userIds));
        $records = [];

        foreach ($certificates as $certificate) {
            $course = $courses[$certificate->courseId] ?? null;

            $records[] = new CertificateRecord(
                $certificate,
                $names[$certificate->userId] ?? null,
                $course instanceof Course ? $course : null,
                CertificateRecord::statusOf($certificate, $now),
            );
        }

        return $records;
    }
}
