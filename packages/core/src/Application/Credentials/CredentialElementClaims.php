<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ContributesToCredential;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ProvidesCredentialValue;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ResolvesToText;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * What the elements on a certificate contribute to its credential document.
 *
 * ContributesToCredential was probed by exactly one caller, the palette
 * listing handler, so declaring it changed what an authoring screen could
 * warn about and nothing about the document a wallet reads. That made
 * extending the credential impossible as a registration: adding a property
 * meant writing a whole second format provider duplicating the first, which
 * is the God-object-by-copy the extensibility rule exists to prevent.
 *
 * So the capability gets a call site. An element that declares a credential
 * property and can resolve itself to text (which is what ResolvesToText's own
 * docblock says it is for) puts that text at that path in the document.
 *
 * Two rules keep it safe. The claims are read from the **frozen** snapshot,
 * so they are the elements the certificate was issued with and not the ones
 * the template carries today. And a claim never overwrites a property the
 * format itself set, so an element cannot rewrite the issuer, the validity
 * window or the subject identity: it can only add.
 *
 * A missing element provider is skipped rather than thrown, which is the
 * published registry miss direction for elements everywhere else.
 */
final class CredentialElementClaims
{
    public function __construct(
        private readonly CertificateElementRegistry $elements,
        private readonly CertificateLayout $layout = new CertificateLayout(),
    ) {
    }

    /**
     * @return array<string, string|int|float> dotted credential paths to values
     */
    public function forCertificate(Certificate $certificate, \DateTimeImmutable $now): array
    {
        if ($certificate->templateSnapshot === null) {
            return [];
        }

        $tokens = TemplateSnapshot::tokensOf($certificate->contextSnapshot);
        $locale = TemplateSnapshot::stringOf($certificate->contextSnapshot, 'locale', 'en-GB');
        $timezone = TemplateSnapshot::stringOf($certificate->contextSnapshot, 'timezone', 'UTC');
        $claims = [];

        foreach (TemplateSnapshot::pagesOf($certificate->templateSnapshot) as $page) {
            foreach ($page->elements as $element) {
                $claim = $this->claimFor($page, $element, $tokens, $locale, $timezone, $now);

                if ($claim === null) {
                    continue;
                }

                [$path, $value] = $claim;

                if (!\array_key_exists($path, $claims)) {
                    $claims[$path] = $value;
                }
            }
        }

        return $claims;
    }

    /**
     * @param array<string, string> $tokens
     *
     * @return array{0: string, 1: string|int|float}|null
     */
    private function claimFor(
        CertificateTemplatePage $page,
        CertificateTemplateElement $element,
        array $tokens,
        string $locale,
        string $timezone,
        \DateTimeImmutable $now,
    ): ?array {
        if (!$this->elements->has($element->element)) {
            return null;
        }

        $provider = $this->elements->get($element->element);

        if (!$provider instanceof ContributesToCredential || !$provider instanceof ResolvesToText) {
            return null;
        }

        $path = $provider->credentialProperty();

        if ($path === '') {
            return null;
        }

        $context = ElementRenderContext::create(
            $page,
            $this->layout->boxFor($page, $element),
            $now,
            $tokens,
            $locale,
            $timezone,
        );

        // The printed line is the claim for a name, a title or a date. It is
        // not for a numeric property: Open Badges 3.0 declares creditsEarned
        // as a number and the credit elements print a human sentence, so an
        // element whose two answers differ declares the machine-readable one.
        if ($provider instanceof ProvidesCredentialValue) {
            $value = $provider->credentialValue($element->data, $context);

            return $value === null || $value === '' ? null : [$path, $value];
        }

        $text = $provider->resolveText($element->data, $context);

        return $text === null || $text === '' ? null : [$path, $text];
    }

    /**
     * Puts every claim into the document at its declared dotted path,
     * creating the intermediate maps and never replacing a value the format
     * already wrote.
     *
     * @param array<string, mixed>            $body
     * @param array<string, string|int|float> $claims
     *
     * @return array<string, mixed>
     */
    public static function mergeInto(array $body, array $claims): array
    {
        foreach ($claims as $path => $value) {
            $body = self::put($body, explode('.', $path), $value);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $segments
     *
     * @return array<string, mixed>
     */
    private static function put(array $body, array $segments, string|int|float $value): array
    {
        $head = array_shift($segments);

        if ($head === null || $head === '') {
            return $body;
        }

        if ($segments === []) {
            if (!\array_key_exists($head, $body)) {
                $body[$head] = $value;
            }

            return $body;
        }

        $child = $body[$head] ?? [];

        if (!\is_array($child)) {
            // A leaf the format already wrote is not turned into a branch:
            // an element may add to the document and never rewrite it.
            return $body;
        }

        /** @var array<string, mixed> $child */
        $body[$head] = self::put($child, $segments, $value);

        return $body;
    }
}
