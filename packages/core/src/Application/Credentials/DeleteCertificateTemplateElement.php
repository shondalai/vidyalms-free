<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;

/**
 * Removes one element from a page.
 *
 * Certificates already issued are untouched: they render from their own
 * frozen snapshot, so removing an element from a live template cannot rewrite
 * a credential somebody already holds.
 *
 * Requires manage.
 */
final class DeleteCertificateTemplateElement
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly AccessGuard $guard,
    ) {
    }

    /** @return CertificateTemplateMutationResult<null> */
    public function execute(
        Principal $principal,
        int $elementId,
        int $expectedRevision,
    ): CertificateTemplateMutationResult {
        $this->guard->require($principal, Capability::MANAGE);

        $routedElement = $this->aggregate->requireElement($elementId);
        $routedPage = $this->aggregate->requirePage($routedElement->pageId);

        return $this->aggregate->mutate(
            $routedPage->templateId,
            $expectedRevision,
            function (array $_template, array $pages, int $_revision) use ($elementId): mixed {
                $element = $this->aggregate->claimedElement($pages, $elementId);
                $page = $this->aggregate->claimedPage($pages, $element->pageId);
                $remaining = [];

                foreach ($page->elements as $sibling) {
                    if ($sibling->id !== $elementId) {
                        $remaining[] = $sibling->id;
                    }
                }

                $this->templates->deleteElement($elementId);
                $this->templates->reorderElements($page->id, $remaining);

                return null;
            },
        );
    }
}
