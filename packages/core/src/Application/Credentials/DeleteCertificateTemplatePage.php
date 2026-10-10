<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;

/**
 * Removes a page and the elements on it, in one transaction.
 *
 * An element with no page has no paper to be drawn on and would be
 * unreachable rather than merely unused, so the two deletes are one write.
 * Certificates already issued are untouched: they render from their own
 * frozen snapshot and never from the live template.
 *
 * The last page of a template in the "elements" format cannot be removed,
 * because the template's whole content is its pages and the designer would
 * then be looking at nothing with nowhere to drop.
 *
 * Requires manage.
 */
final class DeleteCertificateTemplatePage
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
        int $pageId,
        int $expectedRevision,
    ): CertificateTemplateMutationResult {
        $this->guard->require($principal, Capability::MANAGE);

        // Routing information only; the claimed graph below is authoritative.
        $routedPage = $this->aggregate->requirePage($pageId);

        return $this->aggregate->mutate(
            $routedPage->templateId,
            $expectedRevision,
            function (array $template, array $pages, int $_revision) use ($pageId): mixed {
                $page = $this->aggregate->claimedPage($pages, $pageId);

                if (
                    \count($pages) <= 1
                    && ($template['format'] ?? null) === CertificateTemplateFields::FORMAT_ELEMENTS
                ) {
                    throw ConflictException::with(
                        'The last page of an elements template cannot be removed.',
                        ['reason' => 'certificate_template.last_page'],
                    );
                }

                $remaining = [];

                foreach ($pages as $existing) {
                    if ($existing->id !== $pageId) {
                        $remaining[] = $existing->id;
                    }
                }

                $this->templates->deletePage($page->id);
                $this->templates->reorderPages($page->templateId, $remaining);

                return null;
            },
        );
    }
}
