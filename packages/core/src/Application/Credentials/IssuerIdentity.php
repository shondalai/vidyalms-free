<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

/**
 * Who issued this, resolved once for every surface that has to say so.
 *
 * The `certificates.issuer` settings record is the administrator's answer and
 * has been since the design named it, and nothing anywhere wrote it: no
 * adapter seeded it, no screen set it and no route accepted it. So every
 * shipped site emitted `"issuer": {"id": "", "type": ["Profile"], "name": ""}`
 * inside its credentials and `"issuer": []` on its verification page. An empty
 * string is not an IRI, `id` and `name` are required members of the Open
 * Badges 3.0 Profile, and a verifier reading the badge could not tell who
 * issued it.
 *
 * Three sources in order, so a fresh install is correct with no admin action
 * and an administrator can still override:
 *
 * 1. the `certificates.issuer` settings record, written through the settings
 *    route;
 * 2. the host facts the adapter hands the kernel at boot, `site_name`,
 *    `site_url` and `site_logo_url`, which every CMS already knows about
 *    itself;
 * 3. a documented last resort. The id falls back to a URN, which is an IRI
 *    that is honestly not dereferenceable rather than an empty string that is
 *    not an IRI at all, and the name falls back to the URL's host and then to
 *    a translated "not named yet".
 *
 * **Never the request.** The issuer is composed from kernel options for the
 * same reason the credential base URL is: issuance and bulk reissue are
 * scheduled work, a task has no request behind it, and a credential minted
 * from a CLI run would otherwise carry an issuer nobody can resolve, for ever,
 * inside a document that cannot be edited once it is signed.
 */
final class IssuerIdentity
{
    public const SETTING = 'certificates.issuer';

    public const FIELD_NAME = 'name';
    public const FIELD_URL = 'url';
    public const FIELD_IMAGE = 'image';

    /** The three fields the record holds, in the order the screen shows them. */
    public const FIELDS = [self::FIELD_NAME, self::FIELD_URL, self::FIELD_IMAGE];

    /**
     * A URN rather than an empty string, and rather than a guess. It is a
     * valid IRI, it is obviously not dereferenceable, and a verifier reading
     * it learns that the site has not published an issuer address, which is
     * the true thing to learn.
     */
    public const FALLBACK_ID = 'urn:vidyalms:issuer';

    public function __construct(
        private readonly Settings $settings,
        private readonly TranslatorPort $translator,
        /** The host's own name, handed to the kernel as the `site_name` option. */
        private readonly string $defaultName = '',
        /** The host's own address, handed to the kernel as the `site_url` option. */
        private readonly string $defaultUrl = '',
        /** The host's own logo, handed to the kernel as the `site_logo_url` option. */
        private readonly string $defaultImage = '',
    ) {
    }

    /**
     * The record exactly as stored, which is what the settings screen shows
     * and what a PUT replaces. Empty means nothing has been written, and the
     * screen says so rather than showing the derived values as if an
     * administrator had typed them.
     *
     * @return array<string, string>
     */
    public function stored(): array
    {
        $record = $this->settings->getArray(self::SETTING, []);
        $strings = [];

        foreach (self::FIELDS as $field) {
            $value = $record[$field] ?? null;

            if (\is_string($value) && trim($value) !== '') {
                $strings[$field] = trim($value);
            }
        }

        return $strings;
    }

    /**
     * The issuer every credential and every verification page reports.
     *
     * `id` and `name` are always non-empty, which is the whole point: they
     * are required members and an empty one makes the document fail its own
     * schema while claiming conformance.
     *
     * @return array{id: string, name: string, url: string, image: string}
     */
    public function resolve(): array
    {
        $stored = $this->stored();

        $url = $stored[self::FIELD_URL] ?? ($this->defaultUrl === '' ? '' : rtrim($this->defaultUrl, '/'));
        $image = $stored[self::FIELD_IMAGE] ?? $this->defaultImage;
        $name = $stored[self::FIELD_NAME]
            ?? ($this->defaultName !== '' ? $this->defaultName : self::hostOf($url));

        if ($name === '') {
            $name = $this->translator->translate('vidyalms.credential.unnamed_issuer');
        }

        return [
            'id' => $url === '' ? self::FALLBACK_ID : $url,
            'name' => $name,
            'url' => $url,
            'image' => $image,
        ];
    }

    /**
     * The resolved record as a map with the empty members dropped, which is
     * the shape a credential format and the verification payload both want:
     * a field the site has not supplied is absent rather than blank.
     *
     * @return array<string, string>
     */
    public function asMap(): array
    {
        return array_filter($this->resolve(), static fn (string $value): bool => $value !== '');
    }

    /**
     * Replaces the record. Only the three declared fields are stored, so a
     * caller cannot use the settings route to write arbitrary keys under a
     * name the credential surface reads.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, string> what was stored
     */
    public function save(array $record): array
    {
        $clean = [];
        $errors = [];

        foreach (self::FIELDS as $field) {
            $value = $record[$field] ?? null;

            if (!\is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);

            // The url becomes the credential's issuer IRI and the href on the
            // public verification page; the image becomes the src of every
            // rendered certificate's logo. Neither is a free string. A
            // javascript: url reached the Joomla template's href because
            // htmlspecialchars restricts no scheme, and an arbitrary image
            // host is the same third-party beacon the element asset resolver
            // refuses, on a page whose disclosure is bounded to name, course,
            // issuer, dates and status.
            if (($field === self::FIELD_URL || $field === self::FIELD_IMAGE) && !self::isWebAddress($value)) {
                $errors[$field] = 'An http or https address is required.';
                continue;
            }

            $clean[$field] = $value;
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        $this->settings->set(self::SETTING, $clean);

        return $clean;
    }

    /**
     * Whether a stored issuer address is one a browser and a relying party
     * may both follow.
     */
    public static function isWebAddress(string $value): bool
    {
        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $scheme = parse_url($value, \PHP_URL_SCHEME);

        if (!\is_string($scheme) || !\in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $host = parse_url($value, \PHP_URL_HOST);

        return \is_string($host) && $host !== '';
    }

    /**
     * The host part of an address, which is a better issuer name than nothing
     * and is the name a relying party would read off the URL anyway.
     */
    private static function hostOf(string $url): string
    {
        if ($url === '') {
            return '';
        }

        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) ? $host : '';
    }
}
