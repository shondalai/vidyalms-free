<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Credentials\RenderCertificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Renders one certificate and records where the artefact landed.
 *
 * It used to write the document to a hard-coded certificates/{id}.html path
 * and discard the path the storage port returned, and nothing recorded it, so
 * the learner had no download and the designed View action had nothing to
 * point at. It also meant a renderer producing bytes would have had them
 * written to an HTML path with the wrong media type.
 *
 * Now the extension comes from the artefact's own media type, the checksum is
 * computed over the bytes that were stored, and the path, checksum and
 * renderer key go back onto the row.
 */
final class RenderCertificateTask implements ScheduledTaskHandlerInterface
{
    public const KEY = 'vidyalms.certificate.render';

    public function __construct(
        private readonly RenderCertificate $render,
        private readonly StoragePort $storage,
        private readonly string $verificationBaseUrl,
        private readonly ?CertificateRepository $certificates = null,
        private readonly ?ClockPort $clock = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $certificateId = $run->int('certificate_id');

        // Issuance queues this job with runAt now, so an ordinary sequence
        // (issue, revoke the same day, cron runs) reaches a render path that
        // refuses a withdrawn credential. The refusal is right for a request
        // and wrong for a queued job: the exception path of a task handler is
        // reserved for programmer error, and letting it escape leaves a
        // permanently failing job row that every retry re-drives. The same
        // happens to every predecessor's pending render after a bulk reissue.
        $skip = $this->skipReason($certificateId);

        if ($skip !== null) {
            return 'certificate=' . $certificateId . ' skipped=' . $skip;
        }

        // The unguarded path: issuance authorised this render when it created
        // the row, and a queue row's principal is the runner's rather than
        // the holder's, so guarding here would refuse every job the issue
        // path queues.
        $artefact = $this->render->forScheduledTask($certificateId, $this->verificationBaseUrl);

        $path = 'certificates/' . $certificateId . '.' . $artefact->extension();
        $this->storage->putPrivate($path, $artefact->bytes);

        $checksum = $artefact->checksum();

        if ($this->certificates !== null && $this->clock !== null) {
            $this->certificates->markRendered($certificateId, $path, $checksum, $this->clock->now());
        }

        return 'certificate=' . $certificateId
            . ' path=' . $path
            . ' type=' . $artefact->mediaType
            . ' checksum=' . substr($checksum, 0, 12);
    }

    /**
     * Why this queued render is no longer wanted, or null when it still is.
     *
     * A row that has gone since the job was queued is not a programmer error
     * either; the render path would answer not found and the job would fail
     * for ever for the same reason.
     */
    private function skipReason(int $certificateId): ?string
    {
        if ($this->certificates === null) {
            return null;
        }

        $certificate = $this->certificates->findById($certificateId);

        if ($certificate === null) {
            return 'missing';
        }

        $status = $certificate->statusAt($this->clock?->now() ?? $certificate->issuedAt);

        return match ($status) {
            CredentialStatus::Revoked, CredentialStatus::Superseded => $status->value,
            default => null,
        };
    }
}
