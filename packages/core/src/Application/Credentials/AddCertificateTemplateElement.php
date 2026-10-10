<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;

/**
 * Adds one element to a page.
 *
 * The payload is validated by the element provider and stored wrapped with
 * its version, so a later shape change has an upgrade path rather than a blob
 * nobody can read. Omitted, it is the provider's own default payload, which
 * may be incomplete while the author configures the new element. Publication
 * checks require complete content before the design can be used for issuance.
 *
 * Sequence is draw order, so appending is what puts the new element in front
 * of what is already there. The whole page is renumbered afterwards, because
 * a gap or a duplicate makes draw order the id order by accident.
 *
 * Requires manage.
 */
final class AddCertificateTemplateElement
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly CertificateElementRule $rule,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return CertificateTemplateMutationResult<CertificateTemplateElementView>
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
            function (
                array $template,
                array $pages,
                int $_revision,
            ) use (
                $body,
                $pageId,
            ): CertificateTemplateElementView {
                $page = $this->aggregate->claimedPage($pages, $pageId);
                $fields = new CertificateTemplateFields($body);
                if (count($page->elements) >= CertificateStudio::MAX_ELEMENTS_PER_PAGE) {
                    $fields->reject('elements', 'A certificate page supports at most 100 elements.');
                }
                $element = $this->rule->read($fields, $template, $page);
                $position = $fields->has('sequence') ? $element->sequence : \count($page->elements) + 1;

                $fields->check();

                $elementId = $this->templates->insertElement($page->id, $element);
                $this->templates->reorderElements(
                    $page->id,
                    self::ordered($page->elements, $elementId, $position),
                );

                return $this->views->element($this->aggregate->requireElement($elementId));
            },
        );
    }

    /**
     * @param list<CertificateTemplateElement> $existing
     *
     * @return list<int>
     */
    private static function ordered(array $existing, int $newElementId, int $position): array
    {
        $ids = array_map(static fn (CertificateTemplateElement $element): int => $element->id, $existing);
        $index = max(0, min($position - 1, \count($ids)));

        array_splice($ids, $index, 0, [$newElementId]);

        return array_values($ids);
    }
}
