<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;

/**
 * Assembles what an authoring surface reads about a template, in the one
 * place every certificate-template route reads it from.
 *
 * It is the read side of the aggregate and nothing else: no rule, no write,
 * no authorization. The use cases own those. It exists because the same three
 * facts (does a provider exist for this key, what version does it declare
 * now, which package supplied it) are needed by the detail route, both list
 * routes, every write's response and the preview, and computing them in six
 * places is how a list and the row it opens come to disagree about whether an
 * element is available.
 *
 * `CertificateRecords` is the same shape one context over, which is why this
 * follows its naming rather than inventing one.
 */
final class CertificateTemplateViews
{
    /**
     * The ledger's name for the certificate element seam, so "which package
     * registered this element" is answerable before a package is removed
     * rather than after.
     */
    public const ELEMENT_EXTENSION_POINT = 'certificate_element';

    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateElementRegistry $elements,
        private readonly CertificateTemplateChecks $checks,
        private readonly ?ProviderPackages $packages = null,
    ) {
    }

    /**
     * The list shape: the row and the two counts, without the aggregate.
     *
     * @param array<string, mixed> $row
     */
    public function summary(array $row, int $pageCount, int $elementCount): CertificateTemplateView
    {
        return new CertificateTemplateView($row, [], $pageCount, $elementCount, [], $this->lockedFields($row));
    }

    /**
     * The whole aggregate in one answer, with the template-level checks run
     * over it. Three levels in one response, because the designer needs all
     * three to draw anything.
     *
     * @param array<string, mixed> $row
     */
    public function detail(array $row): CertificateTemplateView
    {
        $default = $this->templates->findPublishedDefault();
        $row['default_selection'] = [
            'id' => $default === null ? null : (int) $default['id'],
            'revision' => $default === null ? null : (int) $default['revision'],
        ];
        $pages = $this->templates->pagesOf((int) ($row['id'] ?? 0));
        $views = [];
        $elementCount = 0;

        foreach ($pages as $page) {
            $views[] = $this->pageOf($page);
            $elementCount += \count($page->elements);
        }

        return new CertificateTemplateView(
            $row,
            $views,
            \count($pages),
            $elementCount,
            $this->checks->run($row, $pages),
            $this->lockedFields($row),
        );
    }

    /**
     * One page and the elements already loaded onto it.
     */
    public function pageOf(CertificateTemplatePage $page): CertificateTemplatePageView
    {
        return new CertificateTemplatePageView($page, array_map(
            fn (CertificateTemplateElement $element): CertificateTemplateElementView => $this->element($element),
            $page->elements,
        ));
    }

    /**
     * One page whose elements are read now. The page routes address a page by
     * its own id and never carry its elements with them.
     */
    public function page(CertificateTemplatePage $page): CertificateTemplatePageView
    {
        return $this->pageOf($page->withElements($this->templates->elementsOf($page->id)));
    }

    public function element(CertificateTemplateElement $element): CertificateTemplateElementView
    {
        if (!$this->elements->has($element->element)) {
            return new CertificateTemplateElementView(
                $element,
                false,
                $element->dataVersion,
                $this->packageOf($element->element),
            );
        }

        $provider = $this->elements->get($element->element);
        $supported = $provider instanceof SupportsPayloadVersions
            ? $provider->supportedPayloadVersions()
            : [$provider->currentShape()];

        return new CertificateTemplateElementView(
            $element,
            // A resolvable key carrying a payload version this build cannot
            // read is a miss and is treated exactly as a missing provider,
            // which is the case a plain has() check cannot see.
            \in_array($element->dataVersion, $supported, true),
            $provider->currentShape(),
            $this->packageOf($element->element),
        );
    }

    /**
     * The fields the criteria lock froze on first issuance. Empty until the
     * first certificate was issued from the template, which is the moment
     * locked_at is written.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public function lockedFields(array $row): array
    {
        $lockedAt = $row['locked_at'] ?? null;

        return \is_string($lockedAt) && $lockedAt !== '' ? CertificateTemplateFields::LOCKED_FIELDS : [];
    }

    private function packageOf(string $elementKey): ?string
    {
        return $this->packages?->packageOf(self::ELEMENT_EXTENSION_POINT, $elementKey);
    }
}
