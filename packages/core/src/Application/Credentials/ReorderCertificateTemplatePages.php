<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;

/**
 * Replaces the page order of one template with the whole ordered list of ids.
 *
 * A list is one write and one conflict, while N sequence patches are N races.
 * A list that is not exactly the template's pages is refused naming what was
 * missing and what was extra, because a partial reorder would silently drop a
 * page from the render.
 *
 * Requires manage.
 */
final class ReorderCertificateTemplatePages
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return CertificateTemplateMutationResult<list<CertificateTemplatePageView>>
     */
    public function execute(Principal $principal, int $templateId, array $body): CertificateTemplateMutationResult
    {
        $this->guard->require($principal, Capability::MANAGE);

        $precondition = new CertificateTemplateFields($body);
        $expectedRevision = $precondition->expectedRevision();
        $precondition->check();

        return $this->aggregate->mutate(
            $templateId,
            $expectedRevision,
            function (array $_template, array $pages, int $_revision) use ($body, $templateId): array {
                $fields = new CertificateTemplateFields($body);
                $ids = $fields->idList('ids');
                $known = array_map(static fn (CertificateTemplatePage $page): int => $page->id, $pages);

                CertificateTemplateOrder::check($fields, 'ids', $ids, $known);
                $fields->check();
                $this->templates->reorderPages($templateId, $ids);

                return array_map(
                    fn (CertificateTemplatePage $page): CertificateTemplatePageView => $this->views->pageOf($page),
                    $this->templates->pagesOf($templateId),
                );
            },
        );
    }
}
