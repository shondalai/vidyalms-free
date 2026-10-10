<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * What a commerce source is asked a price under.
 *
 * The shipped priceFor(array $course) has no country, no currency, no buyer
 * kind and no locale anywhere on its path, so a jurisdiction-aware price is
 * unbuildable from it. One catalogue has to render inclusive in the EU and
 * the UK and exclusive in the US (Directive 98/6/EC, Article 6(1)(e) of
 * Directive 2011/83/EU, and the UK DMCCA 2024 in force 6 April 2025), which
 * means the storefront cannot print a price until it has resolved a country.
 *
 * A private constructor with a named factory, which is the house rule: a
 * later field is additive and no registered provider breaks, while adding a
 * parameter to a shipped interface breaks every third-party implementation.
 *
 * `background` is what a webhook, a cron pass or a fan-out sets. A background
 * ask has no session and therefore no browser-supplied country, so a source
 * that guesses one from a request is asked not to.
 */
final class CommerceContext
{
    public const BUYER_CONSUMER = 'consumer';
    public const BUYER_BUSINESS = 'business';

    private function __construct(
        public readonly Principal $principal,
        /** The CMS user id, or null for a guest browsing the catalogue. */
        public readonly ?int $cmsUserId,
        public readonly string $locale,
        /** ISO 4217, or null to let the source answer in its own default. */
        public readonly ?string $requestedCurrency,
        /** ISO 3166-1 alpha-2, resolved rather than guessed where the buyer said. */
        public readonly ?string $country,
        public readonly ?string $subdivision,
        public readonly string $buyerKind,
        public readonly bool $background,
        public readonly ?string $correlationId,
        /** True when the country was inferred rather than stated, which marks the price provisional. */
        public readonly bool $countryGuessed,
    ) {
    }

    public static function create(
        Principal $principal,
        ?int $cmsUserId = null,
        string $locale = 'en-GB',
        ?string $requestedCurrency = null,
        ?string $country = null,
        ?string $subdivision = null,
        string $buyerKind = self::BUYER_CONSUMER,
        bool $background = false,
        ?string $correlationId = null,
        bool $countryGuessed = false,
    ): self {
        if ($buyerKind !== self::BUYER_CONSUMER && $buyerKind !== self::BUYER_BUSINESS) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown buyer kind "%s".', $buyerKind));
        }

        if ($requestedCurrency !== null && preg_match('/^[A-Z]{3}$/', $requestedCurrency) !== 1) {
            throw new \InvalidArgumentException('A requested currency is three uppercase ISO 4217 letters.');
        }

        if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException('A country is two uppercase ISO 3166-1 letters.');
        }

        return new self(
            $principal,
            $cmsUserId,
            $locale,
            $requestedCurrency,
            $country,
            $subdivision,
            $buyerKind,
            $background,
            $correlationId,
            $countryGuessed,
        );
    }

    /**
     * The ask a background job makes: no browser, no session, no guess.
     */
    public static function forBackground(Principal $principal, ?string $correlationId = null): self
    {
        return self::create($principal, null, 'en-GB', null, null, null, self::BUYER_CONSUMER, true, $correlationId);
    }

    /**
     * Whether a price computed under this ask may be shown as final.
     *
     * A resolved country is not enough on its own: a guessed one is exactly
     * what the visible "Prices shown for <country>, change" control exists to
     * correct.
     */
    public function jurisdictionIsSettled(): bool
    {
        return $this->country !== null && !$this->countryGuessed;
    }

    /**
     * The arguments an adapter carries into its host's own event or filter.
     *
     * Published here rather than described in prose in two adapter docblocks,
     * because the Joomla event dispatches an argument array and the WordPress
     * filter takes positional arguments, and a shop plugin on the other side
     * has to be able to read the same keys on both hosts. Scalars only: an
     * event payload crosses into plugin code the core does not own.
     *
     * @return array<string, scalar|null>
     */
    public function toEventArguments(): array
    {
        return [
            'locale' => $this->locale,
            'currency' => $this->requestedCurrency,
            'country' => $this->country,
            'subdivision' => $this->subdivision,
            'buyer_kind' => $this->buyerKind,
            'cms_user_id' => $this->cmsUserId,
            'background' => $this->background,
            'country_guessed' => $this->countryGuessed,
            'correlation_id' => $this->correlationId,
        ];
    }
}
