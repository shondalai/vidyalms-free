<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ContributesToCredential;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * When it lapses, mapping onto the credential validUntil.
 *
 * A certificate that never expires prints no expiry line, because an expiry
 * line printed on a credential with no expiry is a defect a relying party has
 * to interpret. It draws an empty block rather than nothing at all: the
 * element was authored onto the page and a document that silently loses it
 * cannot be told apart from a document whose element provider was removed. A
 * preview draws the note, so an author can see where it will sit.
 */
final class ExpiryDateElement extends TokenTextElement implements ContributesToCredential
{
    public function key(): string
    {
        return 'expiry_date';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_DATES;
    }

    public function order(): int
    {
        return 20;
    }

    public function credentialProperty(): string
    {
        return 'validUntil';
    }

    protected function tokenName(): string
    {
        return 'certificate.expires_at';
    }

    protected function defaultSize(): int
    {
        return 14;
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $value = $context->token($this->tokenName());

        if (($value === null || $value === '') && !$context->isPreview()) {
            return $this->absent($context, 'no_expiry');
        }

        return parent::renderHtml($payload, $context);
    }
}
