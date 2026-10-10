<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ContributesToCredential;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;

/**
 * Who the credential names. It mirrors the credential subject, so the
 * designer can say that removing it breaks the credential and not only the
 * picture.
 */
final class LearnerNameElement extends TokenTextElement implements ContributesToCredential
{
    public function key(): string
    {
        return 'learner_name';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_IDENTITY;
    }

    public function order(): int
    {
        return 10;
    }

    public function credentialProperty(): string
    {
        return 'credentialSubject.identifier';
    }

    protected function tokenName(): string
    {
        return 'user.full_name';
    }

    protected function defaultSize(): int
    {
        return 34;
    }

    protected function defaultWeight(): int
    {
        return 700;
    }
}
