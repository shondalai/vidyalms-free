<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Scheduler\InspectsScheduledTasks;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;

final class GetCertificateReissueStatus
{
    public function __construct(private readonly SchedulerPort $scheduler, private readonly AccessGuard $guard)
    {
    }

    /** @return array<string, mixed> */
    public function execute(Principal $principal, int $templateId, ?int $revision): array
    {
        $this->guard->require($principal, Capability::MANAGE);
        $key = $revision === null ? null : ReissueCertificatesForTemplate::dedupeKeyFor($templateId, $revision);
        $group = null;
        if ($this->scheduler instanceof InspectsScheduledTasks) {
            $group = $key === null ? $this->scheduler->latestTaskGroup('reissue:template:' . $templateId . ':')
                : $this->scheduler->taskGroup($key);
        }
        if ($group === null) {
            if ($revision === null) {
                return ['template_id' => $templateId, 'revision' => null, 'state' => 'idle', 'retention_days' => null,
                    'available' => $this->scheduler instanceof InspectsScheduledTasks, 'percent' => null];
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Retained certificate reissue run', $templateId . ':' . $revision);
        }
        $revision ??= (int) substr((string) $group['key'], strlen('reissue:template:' . $templateId . ':'));
        $url = '/manage/certificate-templates/' . $templateId . '/reissue?revision=' . $revision;
        return ['template_id' => $templateId, 'revision' => $revision,
            'status_url' => $url, 'audit_url' => $url, 'available' => true,
            'retry_safe' => true, 'retry_policy' => 'Retry only failed or cancelled tasks; completed and live tasks are retained.',
            'cancellation_policy' => 'Stop further selection; already dispatched certificate jobs may finish.',
        ] + $group;
    }
}
