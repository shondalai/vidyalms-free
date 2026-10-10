<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Registry\Context\AssetPath;

/**
 * The paper fields of a page request, read over what the page already holds.
 *
 * A create and a patch differ only in the base: a create reads over the
 * defaults, a patch over the stored page. Written twice they drift, and the
 * one that drifts is the patch, which is how a margin an author never touched
 * comes back as ten.
 *
 * Nothing here is clamped to a sensible sheet. A 10mm page is a mistake the
 * designer shows, and silently correcting it would change what a frozen
 * snapshot renders.
 */
final class CertificateTemplatePaper
{
    private function __construct()
    {
    }

    public static function read(
        CertificateTemplateFields $fields,
        CertificateTemplatePage $base,
    ): CertificateTemplatePage {
        return new CertificateTemplatePage(
            $base->id,
            $base->templateId,
            $base->sequence,
            $fields->optionalDimension('width') ?? $base->width,
            $fields->optionalDimension('height') ?? $base->height,
            $fields->optionalEnum('orientation', CertificateTemplateFields::ORIENTATIONS) ?? $base->orientation,
            $fields->optionalInt('margin_top', 0) ?? $base->marginTop,
            $fields->optionalInt('margin_right', 0) ?? $base->marginRight,
            $fields->optionalInt('margin_bottom', 0) ?? $base->marginBottom,
            $fields->optionalInt('margin_left', 0) ?? $base->marginLeft,
            $fields->has('background') ? self::background($fields) : $base->background,
            $base->elements,
        );
    }

    /**
     * The background is a path inside the site's own asset root, never a URL.
     *
     * The renderer already refuses anything else, so without this an author
     * would type an absolute URL, see it accepted, and find the certificate
     * drawing no background with nothing to explain why. An absolute URL is
     * also the beacon the element asset resolver exists to refuse: every
     * render and every preview would fetch from a third-party host on an
     * artefact whose disclosure is bounded to name, course, issuer, dates and
     * status.
     */
    private static function background(CertificateTemplateFields $fields): ?string
    {
        $value = $fields->nullableString('background', 1024);

        if ($value === null) {
            return null;
        }

        if (!AssetPath::isRelative($value)) {
            $fields->reject('background', 'A path inside the site asset root is required.');

            return null;
        }

        return $value;
    }
}
