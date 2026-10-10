<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RequiresAsset;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;

/**
 * A picture: a logo, a seal, an accreditation mark.
 *
 * The alt text is required rather than encouraged. A certificate is an
 * evidentiary artefact and its accessible form is the HTML verification page,
 * so an image with no alternative there is a failure of SC 1.1.1 on the one
 * surface that has to be accessible.
 *
 * It declares its storage path so a media sweep can see that a template still
 * uses the file, which is how a logo survives a tidy-up.
 */
class ImageElement extends AbstractCertificateElement implements RequiresAsset
{
    public function key(): string
    {
        return 'image';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_MEDIA;
    }

    public function order(): int
    {
        return 10;
    }

    public function defaultPayload(): array
    {
        return [
            'path' => '',
            'alt' => '',
            'width_mm' => null,
            'height_mm' => null,
        ];
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
                'alt' => ['type' => 'string', 'required' => true, 'label' => 'vidyalms.certificate_element.field.alt'],
                'width_mm' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'label' => 'vidyalms.certificate_element.field.width_mm',
                ],
                'height_mm' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'label' => 'vidyalms.certificate_element.field.height_mm',
                ],
            ],
        ];
    }

    public function validate(array $payload, ElementValidationContext $context): array
    {
        $findings = [];
        $path = $payload['path'] ?? null;

        if (!\is_string($path) || trim($path) === '') {
            $findings[] = $this->missing('path');
        }

        $alt = $payload['alt'] ?? null;

        if (!\is_string($alt) || trim($alt) === '') {
            $findings[] = $this->missing('alt');
        }

        return $findings;
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

        $style = '';
        $width = $payload['width_mm'] ?? null;
        $height = $payload['height_mm'] ?? null;

        if (\is_int($width) && $width > 0) {
            $style .= 'width: ' . $width . 'mm;';
        }

        if (\is_int($height) && $height > 0) {
            $style .= ' height: ' . $height . 'mm;';
        }

        $alt = \is_string($payload['alt'] ?? null) ? $payload['alt'] : '';

        $img = '<img src="' . $this->escape($context->assetUrl($path)) . '"'
            . ' alt="' . $this->escape($alt) . '"'
            . ($style === '' ? '' : ' style="' . $this->escape($style) . '"')
            . '>';

        return $this->block($context, $img);
    }
}
