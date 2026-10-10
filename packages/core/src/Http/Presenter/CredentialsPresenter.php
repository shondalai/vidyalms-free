<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecord;
use Shondalai\VidyaLMS\Core\Http\Handler\Presenter;

/**
 * The wire shape of a certificate as an administrator sees it: the document,
 * who it names, and the one word that says whether it still stands.
 */
final class CredentialsPresenter
{
    public const SHAPE_CERTIFICATE_RECORD = 'certificate_record';

    public function __construct(
        private readonly Decorated $decorated,
        private readonly SharedPresenter $shared,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function certificateRecord(CertificateRecord $record, ?string $verificationBaseUrl): array
    {
        $course = $record->course;

        return $this->decorated->apply(
            self::SHAPE_CERTIFICATE_RECORD,
            // The record already carries the resolved status, so the moment
            // it was resolved against is the one this shape reports: two
            // clocks in one payload is how a list and its row disagree.
            array_replace(Presenter::certificate($record->certificate), [
                'learner' => $this->shared->learnerRef($record->certificate->userId, $record->learnerName),
                'course' => $course === null
                    ? ['id' => $record->certificate->courseId, 'title' => '', 'slug' => '']
                    : $this->shared->courseRef($course),
                'status' => $record->status,
                'template_id' => $record->certificate->templateId,
                'template_revision' => $record->certificate->templateRevision,
                'revocation_reason' => $record->certificate->revocationReason,
                'verification_url' => $verificationBaseUrl === null || $verificationBaseUrl === ''
                    ? null
                    : \Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink::append($verificationBaseUrl, $record->certificate->code),
            ]),
            $record,
        );
    }
}
