<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTokens;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCreditRepository;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\CertificatePalettePresenter;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ContributesToCredential;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\TemplateContext;

/**
 * GET /manage/certificate-templates/{id}/element-types
 *
 * The element palette, enumerated from the registry rather than from a
 * constant, which is the acceptance criterion the whole design turns on: a
 * third-party element appears here the day it registers, with core files
 * edited zero and schema migrations zero.
 *
 * Availability is per template, so an element that has nothing to say on this
 * one is offered as unavailable with its reason rather than silently missing:
 * the credit statement on a template no course carries credit under is the
 * case, and an author who cannot see why it is absent files a bug.
 *
 * The response carries the token vocabulary beside the elements, because the
 * designer's autocomplete and the save-time placeholder check both read the
 * same registry and a client that had to assemble it twice would drift.
 */
final class ListCertificateElementTypesHandler implements HandlerInterface
{
    public function __construct(
        private readonly CertificateElementRegistry $elements,
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTokens $tokens,
        private readonly CourseCreditRepository $courseCredits,
        private readonly AccessGuard $guard,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        // The route is under /manage and reads repositories directly, so
        // there is no use case behind it to carry the check. Without this it
        // answered the whole element catalogue, the token vocabulary and
        // which paid packages are installed to any caller, and its 404 on a
        // missing template made it an unauthenticated existence oracle for
        // template ids.
        $this->guard->require($request->principal, Capability::MANAGE);

        $templateId = $request->intPathParam('id');
        $template = $this->templates->findById($templateId);

        if ($template === null) {
            throw ApiProblem::notFound();
        }

        $context = TemplateContext::create(
            $templateId,
            \is_string($template['format'] ?? null) ? $template['format'] : 'html',
            \is_string($template['renderer'] ?? null) ? $template['renderer'] : 'html',
            \is_string($template['achievement_type'] ?? null)
                ? $template['achievement_type']
                : 'CertificateOfCompletion',
            \is_string($template['locale'] ?? null) ? $template['locale'] : null,
            $this->elementKeysOn($templateId),
            $this->courseCredits->schemesInUse(),
        );

        $items = [];

        foreach ($this->elements->all() as $element) {
            $items[] = $this->describe($element, $context);
        }

        // Declared order, never registration order: a decoration through
        // replace() keeps the displaced key's position, which would otherwise
        // pin the free seventeen at the top and split their families.
        usort(
            $items,
            static fn (array $a, array $b): int => [$a['group'], $a['order'], $a['key']]
                <=> [$b['group'], $b['order'], $b['key']],
        );

        return ApiResponse::ok([
            'items' => $items,
            'tokens' => (new CertificatePalettePresenter($this->translator))->tokens($this->tokens->families()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(CertificateElementInterface $element, TemplateContext $context): array
    {
        $capabilities = [];

        foreach (CertificateElementRegistry::CAPABILITIES as $interface => $name) {
            if ($element instanceof $interface) {
                $capabilities[] = $name;
            }
        }

        return [
            'key' => $element->key(),
            'label' => $this->translator->translate($element->label()),
            'group' => $element instanceof ElementMetadataInterface
                ? $element->group()
                : ElementMetadataInterface::GROUP_CONTENT,
            'order' => $element instanceof ElementMetadataInterface ? $element->order() : 1000,
            'available' => $element->isAvailable($context),
            'groups' => (new CertificatePalettePresenter($this->translator))->groups(
                $element->key(),
                $element->label(),
                $element->formSchema(),
                $element->defaultPayload(),
            ),
            'default_payload' => Presenter::freeFormObject($element->defaultPayload()),
            // The version this build writes, and every version it can read.
            // A stored payload above the supported set is a miss and is
            // treated exactly as a missing provider is, which is the case a
            // plain presence check cannot see.
            'current_version' => $element->currentShape(),
            'supported_payload_versions' => $element instanceof SupportsPayloadVersions
                ? $element->supportedPayloadVersions()
                : [$element->currentShape()],
            'capabilities' => $capabilities,
            'contributes_to' => $element instanceof ContributesToCredential
                ? $element->credentialProperty()
                : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function elementKeysOn(int $templateId): array
    {
        $keys = [];

        foreach ($this->templates->pagesOf($templateId) as $page) {
            foreach ($page->elements as $element) {
                $keys[] = $element->element;
            }
        }

        return array_values(array_unique($keys));
    }
}
