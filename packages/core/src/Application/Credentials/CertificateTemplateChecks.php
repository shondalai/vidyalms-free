<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ProvidesReadableVerification;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ValidationFinding;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * The checks that run over a whole template, in the finding shape authors
 * already read.
 *
 * They exist because per-element validation cannot see siblings, and one of
 * the rules this area turns on is a sibling rule. A template whose only
 * verification affordance is a QR code passes every per-element check as
 * specified and ships a certificate no screen reader can verify: the QR
 * element's own alt text does not help, and the verification code element's
 * show_url flag only helps a template that has a verification code element in
 * the first place. So the rule is enforced here, at the template, rather than
 * hoped for at the element.
 *
 * Findings are PublicationFinding, deliberately: it is the shape the
 * authoring surfaces already render, with three levels, a stable code and a
 * translator key plus parameters rather than a sentence. The quiz-scoped
 * check registry is not reused, because its context object carries questions
 * and quiz settings and a certificate template has neither.
 */
final class CertificateTemplateChecks
{
    public const CODE_NO_READABLE_VERIFICATION = 'certificate_template.no_readable_verification';
    public const CODE_NO_ELEMENTS = 'certificate_template.no_elements';
    public const CODE_UNKNOWN_ELEMENT = 'certificate_template.unknown_element';
    public const CODE_UNSUPPORTED_PAYLOAD = 'certificate_template.unsupported_payload_version';
    public const CODE_ELEMENT_FINDING = 'certificate_template.element';

    public function __construct(
        private readonly CertificateElementRegistry $elements,
        private readonly CertificateTokens $tokens,
    ) {
    }

    /**
     * @param array<string, mixed>          $template
     * @param list<CertificateTemplatePage> $pages
     *
     * @return list<PublicationFinding>
     */
    public function run(array $template, array $pages): array
    {
        $format = \is_string($template['format'] ?? null) ? $template['format'] : 'html';

        if ($format !== 'elements') {
            // An html template is a body plus tokens: the verification hint
            // is prose the seeded template already carries, and there are no
            // elements to check.
            return [];
        }

        $findings = [];
        $keys = [];
        $elements = [];
        $available = $this->tokens->availableTokens();

        foreach ($pages as $page) {
            foreach ($page->elements as $element) {
                $keys[] = $element->element;
                $elements[] = $element;

                foreach ($this->checkElement($template, $page, $element, $available) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        if ($keys === []) {
            $findings[] = PublicationFinding::warning(
                self::CODE_NO_ELEMENTS,
                'vidyalms.certificate_template.warning.no_elements',
            );

            return $findings;
        }

        if (!$this->hasReadableVerification($elements)) {
            $findings[] = PublicationFinding::blocks(
                self::CODE_NO_READABLE_VERIFICATION,
                'vidyalms.certificate_template.error.no_readable_verification',
            );
        }

        return $findings;
    }

    /**
     * A QR code alone is not a verification affordance. The verification URL
     * has to be emitted as real selectable text, which is SC 1.1.1 and is the
     * whole reason the rule is at the template.
     *
     * The presence of the element key is not the rule and never was. A
     * verification_code element carrying show_url false draws the code and
     * suppresses exactly the selectable URL this check exists to require, so
     * a template holding a qr_code beside a silenced verification_code passed
     * a check written to refuse that template. A text element counts only
     * when its own body names the verification URL token: any text element
     * counting made the rule satisfiable by a line of prose about anything.
     *
     * Which element satisfies it is the element's own answer, not a literal
     * list here. Two provider payloads were read by key from core, so the
     * paid rich text element, which emits the URL as selectable text under
     * its own key, was refused publication by the free build, and renaming a
     * payload key would have silently made every template pass.
     *
     * @param list<CertificateTemplateElement> $elements
     */
    private function hasReadableVerification(array $elements): bool
    {
        foreach ($elements as $element) {
            if (!$this->elements->has($element->element)) {
                continue;
            }

            $provider = $this->elements->get($element->element);

            if (!$provider instanceof ProvidesReadableVerification) {
                continue;
            }

            if ($provider->emitsVerificationUrl($element->data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $template
     * @param list<string>         $availableTokens
     *
     * @return list<PublicationFinding>
     */
    private function checkElement(
        array $template,
        CertificateTemplatePage $page,
        CertificateTemplateElement $element,
        array $availableTokens,
    ): array {
        if (!$this->elements->has($element->element)) {
            return [PublicationFinding::blocks(
                self::CODE_UNKNOWN_ELEMENT,
                'vidyalms.certificate_template.error.unknown_element',
                ['element' => $element->element],
            )];
        }

        $provider = $this->elements->get($element->element);
        $supported = $provider instanceof SupportsPayloadVersions
            ? $provider->supportedPayloadVersions()
            : [$provider->currentShape()];

        if (!\in_array($element->dataVersion, $supported, true)) {
            // A resolvable key carrying an unreadable payload is a miss and
            // is treated identically to a missing provider, which is the case
            // a plain has() check cannot see.
            return [PublicationFinding::blocks(
                self::CODE_UNSUPPORTED_PAYLOAD,
                'vidyalms.certificate_template.error.unsupported_payload_version',
                ['element' => $element->element, 'version' => $element->dataVersion],
            )];
        }

        $context = ElementValidationContext::create(
            $element->element,
            (int) ($template['id'] ?? 0),
            $page->id,
            $availableTokens,
            \is_string($template['locale'] ?? null) ? $template['locale'] : 'en-GB',
        );

        $findings = [];

        foreach ($provider->validate($element->data, $context) as $finding) {
            $findings[] = $this->translate($finding, $element->element);
        }

        return $findings;
    }

    private function translate(ValidationFinding $finding, string $elementKey): PublicationFinding
    {
        $parameters = $finding->parameters + ['element' => $elementKey];

        return $finding->blocks()
            ? PublicationFinding::blocks($finding->code, $finding->messageKey, $parameters)
            : PublicationFinding::warning($finding->code, $finding->messageKey, $parameters);
    }
}
