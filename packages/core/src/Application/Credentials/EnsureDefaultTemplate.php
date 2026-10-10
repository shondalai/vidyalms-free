<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

/**
 * Seeds the free build's certificate template. Idempotent: when a published
 * default template already exists nothing is inserted, so issuance can call
 * this on every run without ever duplicating the template.
 *
 * The body is token driven rather than hardcoded. It reaches for the design
 * tokens with a literal fallback in the same declaration, so a site that has
 * themed the product gets a certificate that matches it and a site that has
 * not gets exactly what it always got, and it carries the site logo and the
 * site name, which the first version had no slot for at all.
 *
 * Adding placeholders is backward compatible with any already-seeded
 * template, because the html renderer leaves what it cannot resolve intact.
 * That is what makes this a change rather than a migration.
 *
 * The name and the prose come from the catalogue through TranslatorPort. The
 * known tension is unchanged: prose is translated once, at first seeding, so
 * the persisted body is fixed in the site language active at that moment.
 */
final class EnsureDefaultTemplate
{
    /**
     * The stable identifier, distinct from the editable name, so this
     * template is still findable after somebody has renamed it.
     */
    public const TEMPLATE_KEY = 'vidyalms.default';

    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function execute(): int
    {
        $existing = $this->templates->findPublishedDefault();

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        try {
            return $this->templates->insert(
                $this->translator->translate('vidyalms.certificate.default_template_name'),
                'html',
                $this->defaultBody(),
                true,
                'published',
                self::TEMPLATE_KEY,
                // The seeded template stays the html format on purpose. The
                // elements renderer is free and registered, but nothing shipped
                // uses it, so a site that installs nothing sees exactly what it
                // saw before.
                'html',
            );
        } catch (\Throwable $failure) {
            $winner = $this->templates->findPublishedDefault();
            if ($winner !== null) {
                return (int) $winner['id'];
            }
            throw $failure;
        }
    }

    private function defaultBody(): string
    {
        $awardedTo = $this->translator->translate('vidyalms.certificate.awarded_to');
        $forCompleting = $this->translator->translate('vidyalms.certificate.for_completing');
        $verifyHint = $this->translator->translate('vidyalms.certificate.verify_hint');
        $issuedOn = $this->translator->translate('vidyalms.certificate.issued_on');
        $logoAlt = $this->translator->translate('vidyalms.certificate.logo_alt');

        $labels = array_map(
            static fn (string $label): string => htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            compact('awardedTo', 'forCompleting', 'verifyHint', 'issuedOn', 'logoAlt'),
        );

        return strtr('<div style="max-width: 860px; margin: 40px auto; padding: 56px;
            border: 10px double var(--vy-accent, #24405c);
            background: var(--vy-bg, #fdfcf7);
            font-family: var(--vy-font-display, Georgia, \'Times New Roman\', serif);
            color: var(--vy-accent, #24405c); text-align: center;">
    <img src="{site.logo_url}" alt="{$logoAlt}"
         style="max-height: 64px; margin: 0 auto 18px; display: block;">
    <p style="font-size: 14px; letter-spacing: 0.08em; margin: 0 0 24px;">{site.name}</p>
    <p style="font-size: 16px; margin: 0 0 10px;">{$awardedTo}</p>
    <p style="font-size: 34px; font-weight: bold; margin: 0 0 10px;">{learner_name}</p>
    <p style="font-size: 16px; margin: 0 0 10px;">{$forCompleting}</p>
    <p style="font-size: 26px; font-style: italic; margin: 0 0 28px;">{course_title}</p>
    <p style="font-size: 16px; margin: 0 0 8px;">{completed_at}</p>
    <p style="font-size: 13px; margin: 0 0 36px;">{$issuedOn} {certificate.issued_at}</p>
    <hr style="border: 0; border-top: 1px solid #b9c4cf; margin: 0 auto 20px; width: 60%;">
    <p style="font-size: 12px; margin: 0;">{$verifyHint}</p>
</div>', [
                '{$logoAlt}' => $labels['logoAlt'],
                '{$awardedTo}' => $labels['awardedTo'],
                '{$forCompleting}' => $labels['forCompleting'],
                '{$issuedOn}' => $labels['issuedOn'],
                '{$verifyHint}' => $labels['verifyHint'],
            ]);
    }
}
