<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * What a preview produced, and what the template-level checks said about it.
 *
 * The findings travel with the render rather than behind a second route,
 * because the rule they carry is the one an author is most likely to break
 * while looking at a preview: a template whose only verification affordance
 * is a QR code looks finished and ships a certificate whose verification URL
 * no screen reader can reach.
 */
final class CertificatePreviewResult
{
    /**
     * @param list<PublicationFinding> $findings
     * @param list<string>             $unresolvedTokens
     */
    public function __construct(
        public readonly string $mediaType,
        public readonly ?string $html,
        public readonly ?string $dataUrl,
        public readonly array $findings,
        public readonly array $unresolvedTokens,
    ) {
    }
}
