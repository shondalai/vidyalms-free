<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TemplateContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * An element that draws nothing, which is what eleven shipped elements did
 * whenever their value was absent.
 */
final class SilentElement implements CertificateElementInterface
{
    public const KEY = 'silent';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Silent';
    }

    public function defaultPayload(): array
    {
        return [];
    }

    public function formSchema(): array
    {
        return [];
    }

    public function isAvailable(TemplateContext $context): bool
    {
        return true;
    }

    public function validate(array $payload, ElementValidationContext $context): array
    {
        return [];
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        return '';
    }

    public function currentShape(): int
    {
        return 1;
    }

    public function upgrade(array $payload, int $from, UpgradeContext $context): array
    {
        return $payload;
    }
}
