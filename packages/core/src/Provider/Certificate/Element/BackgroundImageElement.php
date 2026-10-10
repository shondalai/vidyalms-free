<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RequiresAsset;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;

/**
 * The whole sheet, behind everything else.
 *
 * It ignores its own box on purpose: a background is the page, and letting an
 * author position it would produce a background that is not one. It carries
 * no alt text for the same reason a CSS background carries none, and it is
 * decorative by definition; an image that says something goes in the image
 * element, which requires an alternative.
 */
final class BackgroundImageElement extends AbstractCertificateElement implements RequiresAsset
{
    public function key(): string
    {
        return 'background_image';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_MEDIA;
    }

    public function order(): int
    {
        return 20;
    }

    public function defaultPayload(): array
    {
        return ['path' => '', 'fit' => 'cover', 'opacity' => 1.0];
    }

    public function formSchema(): array
    {
        return [
            'fields' => [
                'path' => [
                    'type' => 'string',
                    'source' => 'storage',
                    'label' => 'vidyalms.certificate_element.field.path',
                ],
                'fit' => [
                    'type' => 'string',
                    'enum' => ['cover', 'contain', 'stretch'],
                    'label' => 'vidyalms.certificate_element.field.fit',
                ],
                'opacity' => ['type' => 'number', 'label' => 'vidyalms.certificate_element.field.opacity'],
            ],
        ];
    }

    public function validate(array $payload, ElementValidationContext $context): array
    {
        $path = $payload['path'] ?? null;

        return \is_string($path) && trim($path) !== '' ? [] : [$this->missing('path')];
    }

    public function assetPaths(array $payload): array
    {
        $path = $payload['path'] ?? null;

        return \is_string($path) && $path !== '' ? [$path] : [];
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $path = $payload['path'] ?? null;

        if (!\is_string($path) || $path === '') {
            return $this->absent($context, 'no_path');
        }

        $fit = \is_string($payload['fit'] ?? null) ? $payload['fit'] : 'cover';
        $objectFit = $fit === 'stretch' ? 'fill' : $fit;
        $opacity = \is_float($payload['opacity'] ?? null) || \is_int($payload['opacity'] ?? null)
            ? (float) $payload['opacity']
            : 1.0;

        return '<img class="vy-cert-background" src="' . $this->escape($context->assetUrl($path)) . '" alt=""'
            . ' style="position: absolute; inset: 0; width: 100%; height: 100%;'
            . ' object-fit: ' . $this->escape($objectFit) . ';'
            . ' opacity: ' . rtrim(rtrim(number_format($opacity, 2, '.', ''), '0'), '.') . ';">';
    }
}
