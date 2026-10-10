<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;

/**
 * Adds a certificate template.
 *
 * Created as a draft at revision 1 and, when the format is "elements", with
 * one page at the default paper size, because a template with no page can
 * hold no element and the designer would open on nothing.
 *
 * The renderer defaults to the format rather than to "html". The two columns
 * are separate on purpose (format is the discriminator, renderer is the
 * registered provider key), and a template stored as "elements" under the
 * html renderer renders an empty body: the renderer reads a template body and
 * the content is on the pages.
 *
 * Requires manage.
 */
final class CreateCertificateTemplate
{
    /**
     * The column holds sixteen megabytes. A template body above this is a
     * paste accident rather than a document, and refusing it here is cheaper
     * than discovering it as a driver error.
     */
    public const MAX_BODY = 262144;

    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly CertificateRendererRegistry $renderers,
        private readonly CertificateExpiryRule $expiry,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function execute(Principal $principal, array $body): CertificateTemplateView
    {
        $this->guard->require($principal, Capability::MANAGE);

        $fields = new CertificateTemplateFields($body);
        $name = $fields->requiredString('name', 255);
        $templateKey = $fields->nullableString('template_key', 100);
        $format = $fields->optionalEnum('format', CertificateTemplateFields::FORMATS) ?? 'html';
        $renderer = $fields->optionalString('renderer', 30) ?? $format;
        $achievementType = $fields->optionalString('achievement_type', 50) ?? 'CertificateOfCompletion';
        $templateBody = $fields->nullableString('body', self::MAX_BODY);
        $locale = $fields->nullableString('locale', 20);
        $category = $fields->nullableString('category', 100);
        $state = $fields->optionalEnum('state', CertificateTemplateFields::STATES)
            ?? CertificateTemplateFields::STATE_DRAFT;
        $isDefault = $fields->optionalBool('is_default') ?? false;
        if ($state !== CertificateTemplateFields::STATE_DRAFT) {
            $fields->reject('state', 'Create a draft, then use the explicit publish operation.');
        }
        if ($isDefault) {
            $fields->reject('is_default', 'Use the explicit default-selection operation after publication.');
        }
        $expiryChanges = $this->expiry->changesFor($fields);

        if (!$this->renderers->has($renderer)) {
            // An author cannot choose a renderer that is not installed. A
            // template already naming one whose package was removed still
            // renders a placeholder, which is the miss direction for a
            // renderer, but creating that state deliberately is not an
            // authoring gesture.
            $fields->reject('renderer', 'One of: ' . implode(', ', $this->renderers->keys()) . '.');
        }

        if ($templateKey === EnsureDefaultTemplate::TEMPLATE_KEY) {
            // A shipped identifier no caller owns, refused whatever the
            // lookup answers. On a site that upgraded from before the column
            // existed the seeded row carries NULL, so findByKey() missed and
            // the same request that a fresh install refused succeeded there:
            // identical code, two behaviours, and the shipped key taken by a
            // template the product does not know about.
            $fields->reject('template_key', 'This key belongs to the shipped default template.');
        } elseif ($templateKey !== null && $this->templates->findByKey($templateKey) !== null) {
            $fields->reject('template_key', 'Another template already uses this key.');
        }

        if ($isDefault && $state !== CertificateTemplateFields::STATE_PUBLISHED) {
            // The default is resolved as "is_default and published", so a
            // draft carrying the flag would leave issuance with nothing to
            // resolve and the seeder would mint a second default beside it.
            $fields->reject('is_default', 'Only a published template may be the site default.');
        }

        $fields->check();

        $templateId = $this->aggregate->write(function () use (
            $name,
            $renderer,
            $templateBody,
            $isDefault,
            $state,
            $templateKey,
            $format,
            $achievementType,
            $locale,
            $category,
            $expiryChanges,
        ): int {
            $id = $this->templates->insert(
                $name,
                $renderer,
                $templateBody ?? '',
                $isDefault,
                $state,
                $templateKey,
                $format,
            );

            $this->templates->update($id, array_merge(
                ['achievement_type' => $achievementType, 'locale' => $locale, 'category' => $category],
                $expiryChanges,
            ));

            if ($isDefault) {
                $this->templates->clearDefaultExcept($id);
            }

            if ($format === CertificateTemplateFields::FORMAT_ELEMENTS) {
                // The id is the store's to assign; insertPage reads the
                // template and the paper and nothing else.
                $this->templates->insertPage(new CertificateTemplatePage(0, $id));
            }

            return $id;
        });

        return $this->views->detail($this->aggregate->require($templateId));
    }
}
