<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Boot;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateLayout;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\BackgroundImageElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\BorderElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\ColourPanelElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\CategoryNameElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\CourseFieldElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\CourseNameElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\CreditStatementElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\ExpiryDateElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\GradeElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\ImageElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\InstructorNameElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\IssueDateElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\LearnerNameElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\QrCodeElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\SignatureImageElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\TextElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\UserFieldElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\VerificationCodeElement;
use Shondalai\VidyaLMS\Core\Provider\Certificate\ElementsCertificateRenderer;
use Shondalai\VidyaLMS\Core\Provider\Certificate\PdfFallbackCertificateRenderer;
use Shondalai\VidyaLMS\Core\Provider\Credential\OpenBadges2AssertionFormat;
use Shondalai\VidyaLMS\Core\Provider\Credential\OpenBadges2BakedPngFormat;
use Shondalai\VidyaLMS\Core\Provider\Credential\VidyaLmsJsonFormat;
use Shondalai\VidyaLMS\Core\Provider\Credit\ContactHoursScheme;
use Shondalai\VidyaLMS\Core\Provider\Expiry\ExpiryPolicies;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\CertificateReissuedTrigger;
use Shondalai\VidyaLMS\Core\Provider\Token\CreditTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\Registries;

/**
 * Everything the free build registers into the three credential registries,
 * in one place.
 *
 * One list rather than several scattered through the kernel, for the reason
 * the assessment list already gives: a generator that has to describe the
 * shipped catalogue has to register exactly what the kernel registers, or the
 * generated artefact describes a site nobody runs.
 *
 * Registration order is deliberately not palette order. Presentation order is
 * declared through the element metadata interface, because replace() keeps a
 * displaced key's position and decorating a shipped element would otherwise
 * pin the free seventeen at the top of the list and split their families
 * across it.
 *
 * The elements renderer is registered under its own key beside the html one
 * rather than replacing it. Replacing html would silently reinterpret every
 * stored row that names it, which is exactly what the never-changing-key rule
 * exists to prevent; a template opts into the element model through its
 * format column instead.
 */
final class CredentialProviders
{
    private function __construct()
    {
    }

    public static function registerInto(
        Registries $registries,
        TranslatorPort $translator,
        ?StoragePort $storage = null,
        ?string $badgeImagePath = null,
        ?\Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort $pdf = null,
    ): void {
        $elements = new ElementsCertificateRenderer(
            $registries->certificateElements,
            $translator,
            new CertificateLayout(),
        );
        $registries->certificateRenderers->register($elements);

        if ($pdf !== null) {
            $registries->certificateRenderers->register(new \Shondalai\VidyaLMS\Core\Provider\Certificate\PdfCertificateRenderer(
                $registries->certificateElements,
                $translator,
                $pdf,
                $storage,
            ));
        } else {
            // Designs published under 1.0.0, when the free build carried the
            // PDF port, still name "pdf". Without a renderer under that key
            // they stop issuing; this one draws them as HTML until a PDF port
            // is seated again.
            $registries->certificateRenderers->register(new PdfFallbackCertificateRenderer($elements, $translator));
        }

        foreach (self::elements() as $element) {
            $registries->certificateElements->register($element);
        }

        // The credit family's token resolver belongs with the credit scheme
        // and the credit element rather than with the notification token
        // block, because every host that boots the credential providers needs
        // it and none of them boots the notification block on its own. Seated
        // there it was seated nowhere: the free credit_statement element and
        // the paid credit_table both drew empty on a certificate that carried
        // credits, and the preview reported no finding because the tokens were
        // registered and merely resolved to nothing.
        $registries->tokenResolvers->register(new CreditTokenResolver());

        $registries->credentialFormats->register(new VidyaLmsJsonFormat($translator));
        $registries->credentialFormats->register(
            new OpenBadges2BakedPngFormat($translator, storage: $storage, imagePath: $badgeImagePath),
        );
        // What the baked image's assertionUrl actually resolves to. Without
        // it the badge named an address that answered an Open Badges 3.0
        // credential under ld+json and 406 under application/json, so no
        // Open Badges 2.0 verifier could verify the one portable artefact the
        // free build ships.
        $registries->credentialFormats->register(new OpenBadges2AssertionFormat($translator));

        $registries->creditSchemes->register(new ContactHoursScheme($translator));

        // The three shapes expiry_mode's own vocabulary names. They are free
        // because the issue path resolves them on every site and freezes the
        // answer, and because a registry whose only providers are paid is a
        // seam nobody tests.
        foreach (ExpiryPolicies::all($translator) as $policy) {
            $registries->expiryPolicies->register($policy);
        }

        // The one trigger this area adds. It is a separate message from
        // issuance because a learner told twice that they earned a
        // certificate reads the second as a second credential, while this one
        // says the code they are carrying no longer resolves.
        $registries->notificationTriggers->register(new CertificateReissuedTrigger());
    }

    /**
     * The seventeen: sixteen derived from mod_customcert's nineteen with the
     * Moodle-specific ones dropped and both escape hatches kept, plus the
     * credit statement the free contact hours scheme needs.
     *
     * The two escape hatches are what stop a fixed list being a ceiling,
     * which is exactly how Moodle's own userfield and coursefield earn their
     * place: without them, the site that needs an employee number needs an
     * eighteenth element and a core release.
     *
     * @return list<\Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementInterface>
     */
    public static function elements(): array
    {
        return [
            new TextElement(),
            new ImageElement(),
            new BackgroundImageElement(),
            new BorderElement(),
            new ColourPanelElement(),
            new LearnerNameElement(),
            new CourseNameElement(),
            new CategoryNameElement(),
            new IssueDateElement(),
            new ExpiryDateElement(),
            new GradeElement(),
            new VerificationCodeElement(),
            new QrCodeElement(),
            new SignatureImageElement(),
            new InstructorNameElement(),
            new UserFieldElement(),
            new CourseFieldElement(),
            new CreditStatementElement(),
        ];
    }
}
