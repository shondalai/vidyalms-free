<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;

/**
 * Everything CertificateRendererInterface requires and nothing else, which is
 * exactly what a third party written before the capability existed looks
 * like.
 */
final class PlainCertificateRenderer implements CertificateRendererInterface
{
    public const KEY = 'x_acme_plain';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Acme plain renderer';
    }

    public function render(CertificateRenderContext $context): string
    {
        return $context->body();
    }
}
