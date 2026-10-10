<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateLayout;
use Shondalai\VidyaLMS\Core\Boot\CredentialProviders;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ContributesToCredential;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RendersToPdf;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RequiresAsset;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ResolvesToText;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TemplateContext;

/**
 * The seventeen free elements: sixteen derived from mod_customcert's nineteen
 * with the Moodle-specific ones dropped and both escape hatches kept, plus
 * the credit statement the free contact hours scheme needs.
 */
final class CertificateElementCatalogueTest extends TestCase
{
    public function testTheFreeCatalogueIncludesEditableColourPanels(): void
    {
        $keys = array_map(
            static fn (CertificateElementInterface $element): string => $element->key(),
            CredentialProviders::elements(),
        );

        sort($keys);

        self::assertSame([
            'background_image',
            'border',
            'category_name',
            'colour_panel',
            'course_field',
            'course_name',
            'credit_statement',
            'expiry_date',
            'grade',
            'image',
            'instructor_name',
            'issue_date',
            'learner_name',
            'qr_code',
            'signature_image',
            'text',
            'user_field',
            'verification_code',
        ], $keys);
        self::assertCount(18, $keys);
    }

    public function testEveryElementDeclaresItsGroupAndOrder(): void
    {
        foreach (CredentialProviders::elements() as $element) {
            // Presentation order is declared, never inherited: replace()
            // keeps a displaced key's position, so a decoration would
            // otherwise pin the free seventeen at the top of the palette.
            self::assertInstanceOf(ElementMetadataInterface::class, $element);
            self::assertNotSame('', $element->group());
            self::assertGreaterThan(0, $element->order());
        }
    }

    public function testTheCapabilityTableIsWhatTheElementsActuallyDeclare(): void
    {
        $capabilities = [];

        foreach (CredentialProviders::elements() as $element) {
            $declared = [];

            if ($element instanceof ResolvesToText) {
                $declared[] = 'text';
            }

            if ($element instanceof RequiresAsset) {
                $declared[] = 'asset';
            }

            if ($element instanceof ContributesToCredential) {
                $declared[] = 'credential';
            }

            if ($element instanceof RendersToPdf) {
                $declared[] = 'pdf';
            }

            $capabilities[$element->key()] = $declared;
        }

        self::assertSame(['text', 'credential'], $capabilities['learner_name']);
        self::assertSame(['text', 'credential'], $capabilities['course_name']);
        self::assertSame(['text', 'credential'], $capabilities['credit_statement']);
        self::assertSame(['asset'], $capabilities['image']);
        self::assertSame(['asset'], $capabilities['background_image']);
        self::assertSame(['asset'], $capabilities['signature_image']);
        self::assertSame(['pdf'], $capabilities['qr_code']);
        // The one element in the catalogue with no capability at all: it is
        // decoration, it carries no meaning, and it says so.
        self::assertSame([], $capabilities['border']);
        self::assertSame([], $capabilities['colour_panel']);
    }

    public function testAnImageWithoutAlternativeTextIsRefusedAtSave(): void
    {
        $image = $this->element('image');
        $context = ElementValidationContext::create('image');

        $findings = $image->validate(['path' => 'media/logo.png'], $context);
        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->blocks());

        self::assertSame([], $image->validate(['path' => 'media/logo.png', 'alt' => 'Our seal'], $context));
    }

    public function testAQrCodeWithoutAlternativeTextIsRefusedAtSave(): void
    {
        $qr = $this->element('qr_code');

        $findings = $qr->validate([], ElementValidationContext::create('qr_code'));

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->blocks());
    }

    public function testAnUnresolvedPlaceholderIsAWarningRatherThanARefusal(): void
    {
        $text = $this->element('text');
        $context = ElementValidationContext::create('text', null, null, ['user.full_name']);

        $findings = $text->validate(['text' => 'Hello {user.full_name} of {cohort.title}'], $context);

        // The published miss direction for a token is that it is left intact,
        // so a template written for a richer build degrades visibly rather
        // than refusing to save.
        self::assertCount(1, $findings);
        self::assertFalse($findings[0]->blocks());
        self::assertSame('cohort.title', $findings[0]->parameters['token']);
    }

    public function testTheCreditStatementRefusesToBeOfferedWithoutAScheme(): void
    {
        $credit = $this->element('credit_statement');

        // mod_customcert's can_add(): an element that renders an empty line
        // on every certificate a site issues is worse than one that is not in
        // the palette.
        self::assertFalse($credit->isAvailable(TemplateContext::create()));
        self::assertTrue($credit->isAvailable(TemplateContext::create(
            1,
            'elements',
            'elements',
            'CertificateOfCompletion',
            null,
            [],
            ['contact_hours'],
        )));
    }

    public function testATokenElementLeavesAnUnresolvedPlaceholderVisible(): void
    {
        $learner = $this->element('learner_name');
        $html = $learner->renderHtml($learner->defaultPayload(), $this->renderContext([]));

        // A visible placeholder is a bug report and a blank is a silent one.
        self::assertStringContainsString('{user.full_name}', $html);
    }

    public function testEveryTextBearingElementEscapesWhatItDraws(): void
    {
        $context = $this->renderContext([
            'user.full_name' => "Mallory <script>alert('x')</script>",
            'course.title' => 'Weaving',
        ]);

        foreach (['learner_name', 'text', 'verification_code'] as $key) {
            $element = $this->element($key);
            $payload = $element->defaultPayload();

            if ($key === 'text') {
                $payload['text'] = '{user.full_name}';
            }

            $html = $element->renderHtml($payload, $context);
            self::assertStringNotContainsString('<script>', $html, $key . ' must escape what it draws');
        }
    }

    public function testTheExpiryElementPrintsNoExpiryLineWhenThereIsNoExpiry(): void
    {
        $expiry = $this->element('expiry_date');

        // An expiry line printed on a credential with no expiry is a defect a
        // relying party has to interpret, so nothing visible is drawn. The
        // block itself stays: an element that returns the empty string is
        // indistinguishable from an element whose provider was removed, and
        // the renderer's whole rule is that a certificate never quietly
        // loses a line.
        $issued = $expiry->renderHtml($expiry->defaultPayload(), $this->renderContext([]));

        self::assertNotSame('', $issued);
        self::assertStringContainsString('data-vy-empty="no_expiry"', $issued);
        self::assertStringContainsString('data-vy-element="expiry_date"', $issued);
        self::assertSame('', trim(strip_tags($issued)), 'nothing visible is printed on the credential');

        $preview = $expiry->renderHtml(
            $expiry->defaultPayload(),
            $this->renderContext([], ElementRenderContext::PURPOSE_PREVIEW),
        );
        // The preview leaves the unresolved placeholder intact, which is the
        // published miss direction for a token and tells the author exactly
        // which one did not resolve.
        self::assertStringContainsString('{certificate.expires_at}', $preview);
    }

    public function testTheEscapeHatchesAcceptABareFieldOrAFullPlaceholder(): void
    {
        $context = $this->renderContext(['user.employee_number' => 'E-4471']);

        foreach (['employee_number', 'user.employee_number'] as $field) {
            $element = $this->element('user_field');
            $html = $element->renderHtml(['field' => $field] + $element->defaultPayload(), $context);

            self::assertStringContainsString('E-4471', $html);
        }
    }

    private function element(string $key): CertificateElementInterface
    {
        foreach (CredentialProviders::elements() as $element) {
            if ($element->key() === $key) {
                return $element;
            }
        }

        self::fail('No free element is registered under ' . $key . '.');
    }

    /**
     * @param array<string, string> $tokens
     */
    private function renderContext(
        array $tokens,
        string $purpose = ElementRenderContext::PURPOSE_ISSUE,
    ): ElementRenderContext {
        $page = new CertificateTemplatePage(1, 1);
        $element = new CertificateTemplateElement(1, 1, 'text');

        return ElementRenderContext::create(
            $page,
            (new CertificateLayout())->boxFor($page, $element),
            new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC')),
            $tokens,
            'en-GB',
            'UTC',
            $purpose,
        );
    }
}
