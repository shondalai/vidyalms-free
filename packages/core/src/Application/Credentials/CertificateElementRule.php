<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCreditRepository;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TemplateContext;

/**
 * What an element write is checked against, in the one place both element
 * writes read it from.
 *
 * The split it enforces is the load-bearing decision of the whole model:
 * sequence, position, size, reference point and alignment are host columns
 * because the designer and the layout service own them, while everything
 * element-specific lives inside the versioned payload because it belongs to
 * the element provider. So the columns are checked here against the
 * vocabulary the layout service implements, and the payload is handed to the
 * provider, which is the only thing that knows what its own fields mean.
 *
 * An element key no provider registers is refused naming every key this build
 * has, because the usual cause is a package that failed to install and a bare
 * "invalid element" sends an administrator looking in the wrong place.
 *
 * A payload version above the one the provider declares is refused rather
 * than stored, because a stored payload nothing can read is a miss that no
 * later check can distinguish from an uninstalled package.
 */
final class CertificateElementRule
{
    public function __construct(
        private readonly CertificateElementRegistry $elements,
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTokens $tokens,
        private readonly CourseCreditRepository $courseCredits,
    ) {
    }

    /**
     * What an element reads when it is asked whether it may be offered here.
     *
     * @param array<string, mixed> $template
     */
    public function contextFor(array $template): TemplateContext
    {
        $templateId = (int) ($template['id'] ?? 0);
        $keys = [];

        foreach ($this->templates->pagesOf($templateId) as $page) {
            foreach ($page->elements as $element) {
                $keys[] = $element->element;
            }
        }

        return TemplateContext::create(
            $templateId,
            \is_string($template['format'] ?? null) ? $template['format'] : 'html',
            \is_string($template['renderer'] ?? null) ? $template['renderer'] : 'html',
            \is_string($template['achievement_type'] ?? null)
                ? $template['achievement_type']
                : 'CertificateOfCompletion',
            \is_string($template['locale'] ?? null) ? $template['locale'] : null,
            array_values(array_unique($keys)),
            $this->courseCredits->schemesInUse(),
        );
    }

    /**
     * The element this write would store.
     *
     * @param array<string, mixed> $template
     */
    public function read(
        CertificateTemplateFields $fields,
        array $template,
        CertificateTemplatePage $page,
        ?CertificateTemplateElement $base = null,
    ): CertificateTemplateElement {
        // The type is required on create and ignored on update, because an
        // element never changes its type: a text element that became a QR
        // code would carry a payload the new provider cannot read.
        $key = $base->element ?? $fields->requiredString('element', 50);

        if (!$this->elements->has($key)) {
            $fields->reject('element', 'One of: ' . implode(', ', $this->elements->keys()) . '.');
            $fields->check();
        }

        $provider = $this->elements->get($key);

        if ($base === null && !$provider->isAvailable($this->contextFor($template))) {
            // The palette's own answer for this template, not a site-wide
            // fact: the credit statement has nothing to say on a template
            // whose courses carry no credit scheme, and offering it there
            // produces an empty line on every certificate the site issues.
            $fields->reject('element', 'This element is not offered on this template.');
            $fields->check();
        }

        $supported = $provider instanceof SupportsPayloadVersions
            ? $provider->supportedPayloadVersions()
            : [$provider->currentShape()];

        $payload = $fields->has('data')
            ? $fields->payload('data') ?? $provider->defaultPayload()
            : ($base->data ?? $provider->defaultPayload());

        $version = $fields->optionalInt('data_version', 1)
            ?? ($base->dataVersion ?? $provider->currentShape());

        if (!\in_array($version, $supported, true)) {
            $fields->reject('data_version', 'This build stores: ' . implode(', ', $supported) . '.');
        }

        // Palette additions use trusted provider defaults, which can be
        // incomplete until the author edits them (for example text or an
        // image path). Layout-only edits must also work for those drafts.
        // Validate submitted content here; publication checks validate the
        // complete graph before it can become an issued certificate.
        if ($fields->isValid() && ($fields->has('data') || $fields->has('data_version'))) {
            $context = ElementValidationContext::create(
                $key,
                (int) ($template['id'] ?? 0),
                $page->id,
                $this->tokens->availableTokens(),
                \is_string($template['locale'] ?? null) ? $template['locale'] : 'en-GB',
            );

            foreach ($provider->validate($payload, $context) as $finding) {
                if ($finding->blocks()) {
                    $fields->reject('data', $finding->messageKey);
                }
            }
        }

        return new CertificateTemplateElement(
            $base->id ?? 0,
            $page->id,
            $key,
            $payload,
            $version,
            $fields->optionalInt('sequence', 1) ?? $base->sequence ?? 1,
            $fields->has('name') ? $fields->nullableString('name', 255) : $base?->name,
            $fields->optionalInt('posx', 0) ?? $base->posX ?? 0,
            $fields->optionalInt('posy', 0) ?? $base->posY ?? 0,
            $fields->has('width') ? $fields->nullableInt('width', 1) : $base?->width,
            $fields->has('height') ? $fields->nullableInt('height', 1) : $base?->height,
            $fields->optionalEnum('refpoint', CertificateTemplateFields::REFPOINTS)
                ?? $base->refPoint
                ?? CertificateTemplateElement::REFPOINT_TOP_LEFT,
            $fields->optionalEnum('alignment', CertificateTemplateFields::ALIGNMENTS)
                ?? $base->alignment
                ?? 'left',
        );
    }
}
