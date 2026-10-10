<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

/**
 * One page of the template list, with the total the pager needs.
 *
 * A page object rather than a bare array, for the reason
 * CertificateRecordPage is one: a list route answers four things and three of
 * them are numbers, so a handler that unpacked an array would be one
 * transposition away from paging by the total.
 */
final class CertificateTemplateListing
{
    /**
     * @param list<CertificateTemplateView> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }
}
