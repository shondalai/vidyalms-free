<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;

/**
 * Retires a template. Nothing is deleted.
 *
 * The state becomes "archived" and the rows stay: certificates issued from it
 * keep verifying, and their frozen snapshots keep rendering with the template
 * gone from every picker. A template with issued certificates is retired and
 * never destroyed, which is the same argument the frozen snapshot makes one
 * level down.
 *
 * The one default template cannot be archived while it is the default,
 * because issuance would then have nothing to resolve and the seeder would
 * mint a second one beside it.
 *
 * Requires manage.
 */
final class ArchiveCertificateTemplate
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly AccessGuard $guard,
    ) {
    }

    public function execute(Principal $principal, int $templateId, int $expectedRevision): CertificateTemplateView
    {
        $this->guard->require($principal, Capability::MANAGE);

        $mutation = $this->aggregate->mutate(
            $templateId,
            $expectedRevision,
            function (array $template, array $_pages, int $_revision) use ($templateId): CertificateTemplateView {
                if ((int) ($template['is_default'] ?? 0) === 1) {
                    throw ConflictException::with(
                        'The site default template cannot be archived.',
                        ['reason' => 'certificate_template.is_default'],
                    );
                }

                $this->templates->update($templateId, ['state' => CertificateTemplateFields::STATE_ARCHIVED]);

                return $this->views->detail($this->aggregate->require($templateId));
            },
        );

        if (!$mutation->value instanceof CertificateTemplateView) {
            throw new \RuntimeException('The certificate template archive returned no detail.');
        }

        return $mutation->value;
    }
}
