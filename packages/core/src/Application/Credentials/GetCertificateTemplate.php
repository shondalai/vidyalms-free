<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * One template with its pages and their elements, and what the template-level
 * checks say about it.
 *
 * The whole aggregate in one answer, because the designer needs all three
 * levels to draw anything and three round trips would let a page arrive
 * without its elements.
 *
 * Requires manage.
 */
final class GetCertificateTemplate
{
    public function __construct(
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly AccessGuard $guard,
    ) {
    }

    public function execute(Principal $principal, int $templateId): CertificateTemplateView
    {
        $this->guard->require($principal, Capability::MANAGE);

        return $this->views->detail($this->aggregate->require($templateId));
    }
}
