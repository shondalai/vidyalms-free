<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;

/**
 * The pages of one template, in sequence, each carrying its elements in draw
 * order.
 *
 * Requires manage.
 */
final class ListCertificateTemplatePages
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @return list<CertificateTemplatePageView>
     */
    public function execute(Principal $principal, int $templateId): array
    {
        $this->guard->require($principal, Capability::MANAGE);
        $this->aggregate->require($templateId);

        return array_map(
            fn ($page): CertificateTemplatePageView => $this->views->pageOf($page),
            $this->templates->pagesOf($templateId),
        );
    }
}
