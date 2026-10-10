<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The elements on one page, in draw order.
 *
 * Requires manage.
 */
final class ListCertificateTemplateElements
{
    public function __construct(
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @return list<CertificateTemplateElementView>
     */
    public function execute(Principal $principal, int $pageId): array
    {
        $this->guard->require($principal, Capability::MANAGE);

        return $this->views->page($this->aggregate->requirePage($pageId))->elements;
    }
}
