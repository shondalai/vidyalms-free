<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ContributesToCredential;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;

/**
 * When it was issued. It maps onto the credential validFrom, which is the VC
 * 2.0 name that replaced issuanceDate.
 *
 * The date is formatted through the locale port before it reaches this
 * element, because Y-m-d is a machine format and a certificate is a human
 * artefact.
 */
final class IssueDateElement extends TokenTextElement implements ContributesToCredential
{
    public function key(): string
    {
        return 'issue_date';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_DATES;
    }

    public function order(): int
    {
        return 10;
    }

    public function credentialProperty(): string
    {
        return 'validFrom';
    }

    protected function tokenName(): string
    {
        return 'certificate.issued_at';
    }

    protected function defaultSize(): int
    {
        return 14;
    }
}
