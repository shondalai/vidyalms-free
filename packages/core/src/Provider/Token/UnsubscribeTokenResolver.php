<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Token;

use Shondalai\VidyaLMS\Core\Application\Notification\UnsubscribeTokens;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\DescribesNotificationTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\NotificationTokenType;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * The recipient's own unsubscribe link, for the body of the message.
 *
 * The RFC 8058 header pair is the machine mechanism; a visible link in the
 * body is a separate requirement, and one bulk receiver names it explicitly
 * alongside authentication and the header pair. Without a resolver a template
 * body had no way to carry one at all, so the free build shipped a lawful
 * header and an unlawful body.
 *
 * It answers null rather than a broken link when the site has no https base
 * URL configured, which leaves the placeholder intact: a visible placeholder
 * is a bug report and a blank is a silent one. The send path refuses the
 * message on the same fact, so a body with a live placeholder is not a
 * message anybody receives.
 *
 * The category comes from the render context's parameter bag, which is where
 * the sender stamps it, so one token serves every category rather than one
 * token per category.
 */
final class UnsubscribeTokenResolver implements TokenResolverInterface, DescribesNotificationTokens
{
    public const PREFIX = 'unsubscribe';

    public const SETTING_BASE_URL = 'notifications.unsubscribe_base_url';
    public const SETTING_MAILTO = 'notifications.unsubscribe_mailto';

    public const PARAMETER_CATEGORY = 'category_key';

    public function __construct(
        private readonly Settings $settings,
        private readonly UnsubscribeTokens $tokens,
        /**
         * What the host says its own unsubscribe page is. The stored setting
         * overrides it, for a site that publishes the page elsewhere.
         */
        private readonly string $hostBaseUrl = '',
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
        return 'vidyalms.token.unsubscribe';
    }

    public function tokens(): array
    {
        return ['unsubscribe.url', 'unsubscribe.mailto'];
    }

    public function notificationTokenTypes(): array
    {
        return [
            'unsubscribe.url' => NotificationTokenType::Url,
            // A mailto address is intentionally not accepted in authorable
            // action or asset URL fields, which are HTTPS-only.
            'unsubscribe.mailto' => NotificationTokenType::Text,
        ];
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        return match ($token) {
            'unsubscribe.url' => $this->url($context),
            'unsubscribe.mailto' => $this->mailto(),
            default => null,
        };
    }

    private function url(TokenContext $context): ?string
    {
        $userId = $context->recipientUserId;
        $category = $context->string(self::PARAMETER_CATEGORY);

        if ($userId === null || $userId < 1 || $category === null || $category === '') {
            return null;
        }

        $base = $this->baseUrl();

        if ($base === '' || !str_starts_with($base, 'https://')) {
            return null;
        }

        return \Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink::append($base, $this->tokens->issue($userId, $category));
    }

    /**
     * The stored override first, then what the host said.
     */
    public function baseUrl(): string
    {
        $stored = rtrim($this->settings->getString(self::SETTING_BASE_URL, ''), '/');

        return $stored !== '' ? $stored : rtrim($this->hostBaseUrl, '/');
    }

    private function mailto(): ?string
    {
        $mailto = $this->settings->getString(self::SETTING_MAILTO, '');

        return str_starts_with($mailto, 'mailto:') ? $mailto : null;
    }
}
