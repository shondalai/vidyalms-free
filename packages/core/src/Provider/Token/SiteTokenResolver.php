<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Token;

use Shondalai\VidyaLMS\Core\Application\Credentials\IssuerIdentity;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\DescribesNotificationTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\NotificationTokenType;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * The site's own identity, from settings rather than from a host constant, so
 * a message rendered by a scheduled task with no request behind it says the
 * same thing as one rendered inside a request.
 *
 * The three `site.*` rows are the override, not the source. Nothing outside
 * the tests ever wrote one, so the seeded free template rendered the literal
 * strings `{site.name}` and `<img src="{site.logo_url}">` on every shipped
 * install: the template was right and the wiring was missing. The issuer
 * identity is the same site fact under the name the rest of the credential
 * surface already reads, resolved over the host facts the adapter hands the
 * kernel, so a fresh install renders a correct certificate with no admin
 * action.
 */
final class SiteTokenResolver implements TokenResolverInterface, DescribesNotificationTokens
{
    public const PREFIX = 'site';

    public const SETTING_NAME = 'site.name';
    public const SETTING_URL = 'site.url';
    public const SETTING_LOGO = 'site.logo_url';

    public function __construct(
        private readonly Settings $settings,
        /**
         * Where site.name, site.url and site.logo_url resolve from when the
         * site.* rows are unset. Absent, they resolve from those rows alone,
         * which is what the token layer did before anything wrote them.
         */
        private readonly ?IssuerIdentity $issuer = null,
    ) {
    }

    public function key(): string
    {
        return self::PREFIX;
    }

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function label(): string
    {
        return 'vidyalms.token.site';
    }

    public function tokens(): array
    {
        return ['site.name', 'site.url', 'site.logo_url'];
    }

    public function notificationTokenTypes(): array
    {
        return [
            'site.name' => NotificationTokenType::Text,
            'site.url' => NotificationTokenType::Url,
            'site.logo_url' => NotificationTokenType::Url,
        ];
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        $value = match ($token) {
            'site.name' => $this->settings->getString(self::SETTING_NAME, ''),
            'site.url' => $this->settings->getString(self::SETTING_URL, ''),
            'site.logo_url' => $this->settings->getString(self::SETTING_LOGO, ''),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        if ($value === '') {
            $value = $this->fromIssuer($token);
        }

        return $value === '' ? null : $value;
    }

    private function fromIssuer(string $token): string
    {
        if ($this->issuer === null) {
            return '';
        }

        $issuer = $this->issuer->resolve();

        return match ($token) {
            'site.name' => $issuer['name'],
            'site.url' => $issuer['url'],
            'site.logo_url' => $issuer['image'],
            default => '',
        };
    }
}
