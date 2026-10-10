<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Application\Access\AccessGrant;
use Shondalai\VidyaLMS\Core\Application\Access\LearnerEnrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Http\Handler\Presenter;

/**
 * The wire shapes of why a person may take a course, and of the fact that
 * they are taking it.
 */
final class AccessPresenter
{
    public const SHAPE_ENTITLEMENT = 'entitlement';
    public const SHAPE_ACCESS_GRANT = 'access_grant';
    public const SHAPE_ENROLMENT_RECORD = 'enrolment_record';

    public function __construct(
        private readonly Decorated $decorated,
        private readonly SharedPresenter $shared,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function entitlement(Entitlement $entitlement): array
    {
        return $this->decorated->apply(self::SHAPE_ENTITLEMENT, [
            'id' => $entitlement->id(),
            'user_id' => $entitlement->userId,
            'course_id' => $entitlement->courseId,
            'source' => $entitlement->source,
            'source_ref' => $entitlement->sourceRef,
            'status' => $entitlement->status,
            'granted_by' => $entitlement->grantedBy,
            'starts_at' => Presenter::dateTime($entitlement->startsAt),
            'expires_at' => Presenter::dateTime($entitlement->expiresAt),
        ], $entitlement);
    }

    /**
     * @return array<string, mixed>
     */
    public function accessGrant(AccessGrant $grant): array
    {
        return $this->decorated->apply(self::SHAPE_ACCESS_GRANT, [
            'entitlement' => $this->entitlement($grant->entitlement),
            'enrolment' => $grant->enrolment === null ? null : Presenter::enrolment($grant->enrolment),
        ], $grant);
    }

    /**
     * @return array<string, mixed>
     */
    public function enrolmentRecord(LearnerEnrolment $row): array
    {
        $enrolment = $row->record->enrolment;

        return $this->decorated->apply(self::SHAPE_ENROLMENT_RECORD, [
            'id' => $enrolment->id(),
            'user' => $this->shared->learnerRef($enrolment->userId, $row->learnerName),
            'course' => [
                'id' => $enrolment->courseId,
                'title' => $row->record->courseTitle,
                'slug' => $row->record->courseSlug,
            ],
            'status' => $enrolment->status,
            'progress_percent' => $enrolment->progressPercent,
            'enrolled_at' => Presenter::dateTime($enrolment->enrolledAt),
            'completed_at' => Presenter::dateTime($enrolment->completedAt),
            'last_activity_at' => Presenter::dateTime($enrolment->lastActivityAt),
            'entitlement' => $this->entitlement($row->record->entitlement),
        ], $row);
    }
}
