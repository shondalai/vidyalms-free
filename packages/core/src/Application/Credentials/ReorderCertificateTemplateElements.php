<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;

/**
 * Replaces the element order on one page with the whole ordered list of ids.
 *
 * Sequence is draw order, so this is what puts a border behind a name rather
 * than over it. A list that is not exactly the page's elements is refused
 * naming what was missing and what was extra.
 *
 * Requires manage.
 */
final class ReorderCertificateTemplateElements
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
     * @return CertificateTemplateMutationResult<list<CertificateTemplateElementView>>
     */
    public function execute(Principal $principal, int $pageId, array $body): CertificateTemplateMutationResult
    {
        $this->guard->require($principal, Capability::MANAGE);

        $routedPage = $this->aggregate->requirePage($pageId);
        $precondition = new CertificateTemplateFields($body);
        $expectedRevision = $precondition->expectedRevision();
        $precondition->check();

        return $this->aggregate->mutate(
            $routedPage->templateId,
            $expectedRevision,
            function (array $_template, array $pages, int $_revision) use ($body, $pageId): array {
                $page = $this->aggregate->claimedPage($pages, $pageId);
                $fields = new CertificateTemplateFields($body);
                $ids = $fields->idList('ids');
                $known = array_map(
                    static fn (CertificateTemplateElement $element): int => $element->id,
                    $page->elements,
                );

                CertificateTemplateOrder::check($fields, 'ids', $ids, $known);
                $fields->check();
                $this->templates->reorderElements($page->id, $ids);

                return $this->views->page($this->aggregate->requirePage($pageId))->elements;
            },
        );
    }
}
