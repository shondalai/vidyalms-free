<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateChecks;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTokens;
use Shondalai\VidyaLMS\Core\Boot\CredentialProviders;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Provider\Token\CertificateTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverRegistry;

/**
 * The one sibling rule in this area: the verification URL has to reach the
 * page as real selectable text and not only inside a QR code, which is SC
 * 1.1.1 and is why the check is at the template rather than at an element.
 *
 * The check used to test for the presence of an element key, which is not the
 * rule and never was. A verification_code element carrying show_url false
 * draws the code and suppresses exactly the selectable URL the rule exists to
 * require, so a template holding a qr_code beside a silenced verification_code
 * passed a check written to refuse that template.
 */
final class CertificateTemplateChecksTest extends TestCase
{
    public function testAQrCodeAloneIsRefused(): void
    {
        $findings = $this->findingsFor([$this->element('qr_code', ['alt' => 'Scan to verify'])]);

        self::assertTrue($this->blocks($findings));
    }

    public function testAVerificationCodeShowingItsUrlSatisfiesTheRule(): void
    {
        $findings = $this->findingsFor([
            $this->element('qr_code', ['alt' => 'Scan to verify']),
            $this->element('verification_code', ['show_url' => true]),
        ]);

        self::assertFalse($this->blocks($findings));
    }

    public function testAVerificationCodeHidingItsUrlDoesNotSatisfyTheRule(): void
    {
        $findings = $this->findingsFor([
            $this->element('qr_code', ['alt' => 'Scan to verify']),
            $this->element('verification_code', ['show_url' => false]),
        ]);

        self::assertTrue($this->blocks($findings), 'a silenced URL passed the readable-verification check');
    }

    public function testATextElementCountsOnlyWhenItNamesTheVerificationUrl(): void
    {
        $prose = $this->findingsFor([
            $this->element('qr_code', ['alt' => 'Scan to verify']),
            $this->element('text', ['text' => 'Congratulations on your achievement.']),
        ]);

        self::assertTrue($this->blocks($prose), 'any prose at all satisfied a rule about an address');

        $named = $this->findingsFor([
            $this->element('qr_code', ['alt' => 'Scan to verify']),
            $this->element('text', ['text' => 'Verify at {certificate.verification_url}']),
        ]);

        self::assertFalse($this->blocks($named));
    }

    public function testAnElementDeclaringTheCapabilitySatisfiesTheRuleWithNoCoreEdit(): void
    {
        // The check read two providers' private payload keys by literal, so
        // the paid rich text element, which emits the URL as selectable text
        // under its own key, was refused publication by the free build.
        $findings = $this->findingsFor(
            [
                $this->element('qr_code', ['alt' => 'Scan to verify']),
                $this->element(VerifyingProbeElement::KEY, ['body' => 'Verify at {certificate.verification_url}']),
            ],
            [new VerifyingProbeElement()],
        );

        self::assertFalse($this->blocks($findings));

        $silent = $this->findingsFor(
            [
                $this->element('qr_code', ['alt' => 'Scan to verify']),
                $this->element(VerifyingProbeElement::KEY, ['body' => 'Congratulations.']),
            ],
            [new VerifyingProbeElement()],
        );

        self::assertTrue($this->blocks($silent));
    }

    /**
     * @param list<CertificateTemplateElement>                                                   $elements
     * @param list<\Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementInterface> $extra
     *
     * @return list<PublicationFinding>
     */
    private function findingsFor(array $elements, array $extra = []): array
    {
        $registry = new CertificateElementRegistry();

        foreach (CredentialProviders::elements() as $element) {
            $registry->register($element);
        }

        foreach ($extra as $element) {
            $registry->register($element);
        }

        $tokens = new TokenResolverRegistry();
        $tokens->register(new CertificateTokenResolver('https://example.test/verify'));

        $checks = new CertificateTemplateChecks($registry, new CertificateTokens($tokens));
        $page = (new CertificateTemplatePage(1, 1))->withElements($elements);

        return $checks->run(['id' => 1, 'format' => 'elements'], [$page]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function element(string $key, array $payload): CertificateTemplateElement
    {
        return new CertificateTemplateElement(0, 1, $key, $payload, 1);
    }

    /**
     * @param list<PublicationFinding> $findings
     */
    private function blocks(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->code === CertificateTemplateChecks::CODE_NO_READABLE_VERIFICATION) {
                return true;
            }
        }

        return false;
    }
}
