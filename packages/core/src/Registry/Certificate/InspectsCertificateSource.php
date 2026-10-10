<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;

/** Publication checks and immutable mechanical inputs for a binary renderer. */
interface InspectsCertificateSource
{
    /**
     * @param array<string, mixed> $template
     * @param list<CertificateTemplatePage> $pages
     * @return list<ValidationFinding>
     */
    public function sourceFindings(array $template, array $pages): array;

    /** @return array<string, mixed> */
    public function renderProvenance(): array;

    /**
     * Merge this into the row passed to TemplateSnapshot::freezeTemplate.
     * Reads bounded local image bytes now; issued rendering never reads them again.
     * @param array<string, mixed> $template
     * @param list<CertificateTemplatePage> $pages
     * @return array<string, mixed>
     */
    public function prepareSource(array $template, array $pages): array;
}
