<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\BorderElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\ColourPanelElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\CourseNameElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\IssueDateElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\LearnerNameElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\QrCodeElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\TextElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\VerificationCodeElement;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateTemplateBlueprint;

/**
 * Editable starting points made only from shipped free elements.
 *
 * No kernel registration, seeding, persistence or publication happens here.
 * The caller supplies a translator configured for the SAME explicit locale
 * it passes here. Printed copy is materialized once, ready to edit and freeze.
 * Fonts are local fallback stacks, not a claim that PDF fonts are embedded.
 * A4 is exact in today's integer geometry; Letter is deliberately not offered.
 *
 * Provider defaults are included in the complete source rather than deferred
 * until cloning. Digest fixtures guard against changing version-one artwork
 * accidentally when an element's defaults or a catalogue string changes.
 *
 * @phpstan-import-type ElementSource from CertificateTemplateBlueprint
 */
final class CoreCertificateBlueprints
{
    private const PREFIX = 'vidyalms.certificate_blueprint.';
    private const SERIF = 'Georgia, "Times New Roman", serif';
    private const SANS = 'Arial, Helvetica, sans-serif';
    private const DISPLAY = '"Bricolage Grotesque", Arial, Helvetica, sans-serif';

    public function __construct(
        private readonly TranslatorPort $translator,
        private readonly string $locale,
    ) {
        if (preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/D', $locale) !== 1) {
            throw new \InvalidArgumentException('Certificate blueprint source needs an explicit language tag.');
        }
    }

    /** @return list<CertificateTemplateBlueprint> */
    public function all(): array
    {
        return [
            $this->executive(), $this->professional(), $this->academic(),
            $this->aureate(), $this->evergreen(), $this->atelier(),
            $this->prototype('prototype_classic'),
            $this->prototype('prototype_modern'),
            $this->prototype('prototype_executive'),
        ];
    }

    private function executive(): CertificateTemplateBlueprint
    {
        $ink = '#20344b';
        $muted = '#586577';

        return $this->blueprint('executive_classic', 'executive', [
            $this->border('double', 3, '#a58b56', 8),
            $this->border('solid', 1, '#ded6c5', 12),
            $this->text('{site.name}', 28, 22, 241, 12, 12, 600, $ink, self::SANS, 'centre'),
            $this->text($this->copy('completion_title'), 25, 42, 247, 18, 28, 400, $ink, self::SERIF, 'centre'),
            $this->text($this->copy('presented_to'), 35, 67, 227, 9, 11, 400, $muted, self::SANS, 'centre'),
            $this->type(new LearnerNameElement(), 25, 82, 247, 28, 32, 400, $ink, self::SERIF, 'centre'),
            $this->text($this->copy('completed_course'), 35, 112, 227, 9, 11, 400, $muted, self::SANS, 'centre'),
            $this->type(new CourseNameElement(), 30, 126, 237, 23, 21, 600, $ink, self::SERIF, 'centre'),
            $this->type(new IssueDateElement(), 30, 157, 143, 10, 11, 400, $ink, self::SANS, 'left', [
                'label' => $this->copy('issued_on'),
            ]),
            $this->verification(30, 173, 199, 16, $muted),
            $this->qr(239, 160),
        ]);
    }

    private function professional(): CertificateTemplateBlueprint
    {
        $ink = '#183b43';
        $muted = '#4d666b';

        return $this->blueprint('modern_professional', 'professional', [
            $this->border('solid', 2, '#b7cbc9', 8),
            $this->text('{site.name}', 22, 22, 253, 12, 13, 700, $ink, self::SANS),
            $this->text($this->copy('completion_title'), 22, 47, 253, 19, 29, 700, $ink, self::SANS),
            $this->text($this->copy('presented_to'), 23, 75, 251, 9, 11, 400, $muted, self::SANS),
            $this->type(new LearnerNameElement(), 22, 89, 253, 27, 31, 700, $ink, self::SANS),
            $this->text($this->copy('completed_course'), 23, 119, 251, 9, 11, 400, $muted, self::SANS),
            $this->type(new CourseNameElement(), 22, 132, 253, 23, 20, 600, $ink, self::SANS),
            $this->type(new IssueDateElement(), 23, 165, 185, 10, 11, 600, $ink, self::SANS, 'left', [
                'label' => $this->copy('issued_on'),
            ]),
            $this->verification(23, 180, 207, 16, $muted),
            $this->qr(245, 166),
        ]);
    }

    private function academic(): CertificateTemplateBlueprint
    {
        $ink = '#302e39';
        $muted = '#625e6c';

        return $this->blueprint('minimal_academic', 'academic', [
            $this->text('{site.name}', 28, 20, 241, 18, 18, 600, $ink, self::SERIF, 'centre'),
            $this->text($this->copy('completion_title'), 28, 47, 241, 16, 22, 400, $ink, self::SANS, 'centre'),
            $this->text($this->copy('presented_to'), 35, 72, 227, 9, 11, 400, $muted, self::SANS, 'centre'),
            $this->type(new LearnerNameElement(), 26, 86, 245, 26, 30, 400, $ink, self::SERIF, 'centre'),
            $this->text($this->copy('completed_course'), 35, 116, 227, 9, 11, 400, $muted, self::SANS, 'centre'),
            $this->type(new CourseNameElement(), 30, 130, 237, 23, 20, 400, $ink, self::SERIF, 'centre'),
            $this->type(new IssueDateElement(), 28, 162, 192, 10, 11, 400, $ink, self::SANS, 'left', [
                'label' => $this->copy('issued_on'),
            ]),
            $this->verification(28, 178, 199, 17, $muted),
            $this->qr(241, 166),
        ]);
    }

    private function aureate(): CertificateTemplateBlueprint
    {
        $ink = '#192c40';
        $gold = '#926b2e';
        $muted = '#596575';

        return $this->blueprint('aureate', 'executive', [
            $this->border('solid', 20, $ink, 4),
            $this->border('double', 3, '#c3a265', 12),
            $this->text('{site.name}', 28, 24, 241, 11, 14, 700, $ink, self::DISPLAY, 'centre'),
            $this->text($this->copy('certificate_title'), 28, 44, 241, 21, 40, 400, $ink, self::SERIF, 'centre'),
            $this->text($this->copy('completion_kicker'), 28, 67, 241, 8, 10, 600, $gold, self::DISPLAY, 'centre'),
            $this->text($this->copy('presented_to'), 28, 83, 241, 8, 10, 400, $muted, self::DISPLAY, 'centre'),
            $this->type(new LearnerNameElement(), 26, 95, 245, 23, 34, 400, $gold, self::SERIF, 'centre'),
            $this->text($this->copy('completed_course'), 28, 122, 241, 8, 10, 400, $muted, self::DISPLAY, 'centre'),
            $this->type(new CourseNameElement(), 30, 135, 237, 20, 19, 600, $ink, self::DISPLAY, 'centre'),
            $this->type(new IssueDateElement(), 28, 165, 180, 9, 10, 600, $ink, self::DISPLAY, 'left', [
                'label' => $this->copy('issued_on'),
            ]),
            $this->verification(28, 179, 197, 15, $muted),
            $this->qr(241, 163),
        ]);
    }

    private function evergreen(): CertificateTemplateBlueprint
    {
        $ink = '#164e45';
        $muted = '#536b65';

        return $this->blueprint('evergreen', 'professional', [
            $this->border('solid', 20, '#d4e5dc', 4),
            $this->border('solid', 1, '#47816e', 10),
            $this->text('{site.name}', 25, 25, 247, 11, 14, 700, $ink, self::DISPLAY),
            $this->text($this->copy('certificate_title'), 24, 48, 248, 23, 43, 700, $ink, self::DISPLAY),
            $this->text($this->copy('completion_kicker'), 26, 73, 246, 8, 11, 600, '#326f59', self::DISPLAY),
            $this->text($this->copy('presented_to'), 26, 94, 246, 8, 10, 400, $muted, self::DISPLAY),
            $this->type(new LearnerNameElement(), 24, 106, 248, 24, 34, 700, $ink, self::DISPLAY),
            $this->text($this->copy('completed_course'), 26, 134, 246, 8, 10, 400, $muted, self::DISPLAY),
            $this->type(new CourseNameElement(), 25, 147, 208, 18, 18, 600, $ink, self::DISPLAY),
            $this->type(new IssueDateElement(), 26, 171, 194, 9, 10, 600, $ink, self::DISPLAY, 'left', [
                'label' => $this->copy('issued_on'),
            ]),
            $this->verification(26, 183, 199, 15, $muted),
            $this->qr(244, 169),
        ]);
    }

    private function atelier(): CertificateTemplateBlueprint
    {
        $ink = '#352b2a';
        $terracotta = '#a9432d';
        $muted = '#75605a';

        return $this->blueprint('atelier', 'academic', [
            $this->border('solid', 20, '#f1d8c9', 4),
            $this->border('solid', 1, '#bb6f54', 10),
            $this->text('{site.name}', 26, 26, 243, 12, 15, 700, $ink, self::DISPLAY),
            $this->text($this->copy('editorial_title'), 25, 55, 109, 39, 31, 700, $terracotta, self::DISPLAY),
            $this->text($this->copy('milestone'), 27, 104, 90, 25, 14, 400, $muted, self::SERIF),
            $this->text($this->copy('presented_to'), 146, 61, 123, 9, 10, 400, $muted, self::DISPLAY),
            $this->type(new LearnerNameElement(), 144, 77, 125, 38, 30, 400, $ink, self::SERIF),
            $this->text($this->copy('completed_course'), 146, 122, 123, 8, 10, 400, $muted, self::DISPLAY),
            $this->type(new CourseNameElement(), 144, 137, 125, 23, 18, 600, $terracotta, self::DISPLAY),
            $this->type(new IssueDateElement(), 27, 166, 186, 9, 10, 600, $ink, self::DISPLAY, 'left', [
                'label' => $this->copy('issued_on'),
            ]),
            $this->verification(27, 182, 198, 15, $muted),
            $this->qr(241, 167),
        ]);
    }

    /** The approved prototype's three compositions, with real personalized fields. */
    private function prototype(string $slug): CertificateTemplateBlueprint
    {
        $modern = $slug === 'prototype_modern';
        $dark = $slug === 'prototype_executive';
        $accent = $modern ? '#c64c22' : ($dark ? '#cbb67c' : '#b58b46');
        $ink = $dark ? '#edf0f5' : '#29342f';
        $muted = $dark ? '#edf0f5' : '#6d756e';
        $background = $dark ? '#202c3b' : ($modern ? '#ffffff' : '#fffdf8');
        $alignment = $modern ? 'left' : 'centre';
        $font = $modern ? self::DISPLAY : self::SERIF;
        $elements = [$this->panel('page_background', 0, 0, 297, 210, $background)];

        if ($modern) {
            $elements[] = $this->panel('accent_band', 0, 0, 5, 210, $accent);
            $elements[] = $this->panel('accent_band', 0, 207, 297, 3, $accent);
        } else {
            $elements[] = $this->border('solid', 1, $accent, 8);
            $elements[] = $this->border('solid', 1, $accent, 10);
        }

        $content = [
            'issuer' => $this->text('{site.name}', 30, 17, 237, 10, 13, 700, $accent, self::DISPLAY, $alignment),
            'heading' => $this->text($this->copy('achievement_title'), 30, 36, 237, 21, 30, $modern ? 700 : 400, $ink, $font, $alignment),
            'introduction' => $this->text($this->copy('proudly_presented'), 30, 71, 237, 10, 13, 400, $muted, self::DISPLAY, $alignment),
            'recipient' => $this->type(new LearnerNameElement(), 30, 82, 237, 29, 32, $modern ? 700 : 400, $ink, $font, $alignment),
            'completion' => $this->text($this->copy('completed_course'), 30, 116, 237, 9, 14, 400, $muted, self::DISPLAY, $alignment),
            'course' => $this->type(new CourseNameElement(), 30, 125, 237, 21, 14, 400, $muted, self::DISPLAY, $alignment),
            'signature_rule' => $this->panel('signature_rule', $modern ? 30 : 110, 168, $modern ? 205 : 77, 1, $accent, 1),
            'signatory' => $this->text(
                '{site.name}',
                $modern ? 30 : 64,
                173,
                $modern ? 205 : 169,
                9,
                12,
                400,
                $muted,
                self::DISPLAY,
                $alignment,
            ),
            'date' => $this->type(new IssueDateElement(), 30, 185, 237, 7, 9, 400, $muted, self::DISPLAY, $alignment, [
                'label' => $this->copy('issued_on'),
            ]),
            'verification' => $this->type(new VerificationCodeElement(), 30, 194, 237, 8, 7, 400, $muted, self::DISPLAY, $alignment, [
                'show_url' => true,
            ]),
            'qr' => $this->element(new QrCodeElement(), 252, 165, 18, 18, 'left', [
                'token' => 'certificate.verification_url', 'alt' => $this->copy('qr_alt'), 'size_mm' => 18,
            ]),
        ];
        foreach ($content as $label => $element) {
            $element['name'] = $this->copy('layer.' . $label);
            $elements[] = $element;
        }

        return $this->blueprint($slug, $dark ? 'executive' : ($modern ? 'professional' : 'academic'), $elements);
    }

    /** @return ElementSource */
    private function panel(string $label, int $x, int $y, int $width, int $height, string $colour, ?int $rule = null): array
    {
        $element = $this->element(new ColourPanelElement(), $x, $y, $width, $height, 'left', [
            'colour' => $colour, 'rule_width_pt' => $rule,
        ]);
        $element['name'] = $this->copy('layer.' . $label);

        return $element;
    }

    /** @param list<ElementSource> $elements */
    private function blueprint(string $slug, string $category, array $elements): CertificateTemplateBlueprint
    {
        foreach ($elements as $index => &$element) {
            $element['sequence'] = $index + 1;
        }
        unset($element);

        return new CertificateTemplateBlueprint(
            key: 'vidyalms.certificate.' . $slug,
            version: 1,
            schemaVersion: 1,
            nameKey: self::PREFIX . $slug . '.name',
            descriptionKey: self::PREFIX . $slug . '.description',
            categoryKey: self::PREFIX . 'category.' . $category,
            paperPreset: 'a4_landscape',
            requiredElements: array_values(array_unique(array_column($elements, 'element'))),
            requiredTokens: [
                'site.name', 'user.full_name', 'course.title', 'certificate.issued_at',
                'certificate.code_display', 'certificate.verification_url',
            ],
            source: [
                'name' => $this->copy($slug . '.name'),
                'format' => 'elements',
                'renderer' => 'elements',
                'achievement_type' => 'CertificateOfCompletion',
                'locale' => $this->locale,
                'body' => '',
                'expiry_mode' => 'never',
                'expiry_days' => null,
                'expiry_at' => null,
                'pages' => [[
                    'sequence' => 1,
                    'width' => 297,
                    'height' => 210,
                    'orientation' => 'landscape',
                    'margin_top' => 15,
                    'margin_right' => 15,
                    'margin_bottom' => 15,
                    'margin_left' => 15,
                    'background' => null,
                    'elements' => $elements,
                ]],
            ],
        );
    }

    private function copy(string $suffix): string
    {
        return $this->translator->translate(self::PREFIX . $suffix);
    }

    /** @return ElementSource */
    private function text(
        string $text,
        int $x,
        int $y,
        int $width,
        int $height,
        int $size,
        int $weight,
        string $colour,
        string $font,
        string $alignment = 'left',
    ): array {
        return $this->type(new TextElement(), $x, $y, $width, $height, $size, $weight, $colour, $font, $alignment, [
            'text' => $text, 'max_width_mm' => $width,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return ElementSource
     */
    private function type(
        CertificateElementInterface $provider,
        int $x,
        int $y,
        int $width,
        int $height,
        int $size,
        int $weight,
        string $colour,
        string $font,
        string $alignment = 'left',
        array $payload = [],
    ): array {
        return $this->element($provider, $x, $y, $width, $height, $alignment, $payload + [
            'font' => ['family' => $font, 'size' => $size, 'weight' => $weight],
            'colour' => $colour,
            'line_height' => 1.2,
        ]);
    }

    /** @return ElementSource */
    private function verification(int $x, int $y, int $width, int $height, string $colour): array
    {
        return $this->type(new VerificationCodeElement(), $x, $y, $width, $height, 9, 400, $colour, self::SANS, 'left', [
            'show_url' => true,
        ]);
    }

    /** @return ElementSource */
    private function qr(int $x, int $y): array
    {
        return $this->element(new QrCodeElement(), $x, $y, 28, 28, 'left', [
            'token' => 'certificate.verification_url',
            'alt' => $this->copy('qr_alt'),
            'size_mm' => 28,
        ]);
    }

    /** @return ElementSource */
    private function border(string $style, int $weight, string $colour, int $inset): array
    {
        // BorderElement draws a page-relative frame, not a positioned rule.
        // Its full-page envelope states that actual behavior truthfully.
        return $this->element(new BorderElement(), 0, 0, 297, 210, 'left', [
            'style' => $style, 'width_pt' => $weight, 'colour' => $colour, 'inset_mm' => $inset, 'radius_mm' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return ElementSource
     */
    private function element(
        CertificateElementInterface $provider,
        int $x,
        int $y,
        int $width,
        int $height,
        string $alignment,
        array $payload,
    ): array {
        return [
            'element' => $provider->key(),
            'name' => $this->translator->translate($provider->label()),
            'data' => array_replace($provider->defaultPayload(), $payload),
            'data_version' => $provider->currentShape(),
            'sequence' => 1,
            'posx' => $x,
            'posy' => $y,
            'width' => $width,
            'height' => $height,
            'refpoint' => 'top_left',
            'alignment' => $alignment,
        ];
    }
}
