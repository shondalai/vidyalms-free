<?php

declare(strict_types=1);

/**
 * VidyaLMS: the unsubscribe landing page and the preference centre.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

defined('ABSPATH') || exit;

/**
 * Two pages that are not posts and are not part of the learner surface.
 *
 * ## The unsubscribe page
 *
 * One URL serves the human link and the RFC 8058 one-click POST, because that
 * is what the header pair says: a mail client POSTs to the URI in
 * List-Unsubscribe, and a person who clicks the link GETs the same URI.
 * Splitting them would put an address a receiver cannot use in the header.
 *
 * Four rules this page exists to keep:
 *
 * - **A GET acts on nothing.** It renders a form. Mail security scanners
 *   follow links, and a GET that unsubscribed would turn a scanner into an
 *   opt-out, which is the whole reason RFC 8058 specifies a POST.
 * - **Nothing beyond the token is required.** No login, no nonce, no address
 *   to confirm and no fee: CAN-SPAM bars demanding any of them, and neither
 *   PECR nor CASL gives a shorter window in which to honour the request. The
 *   signed token is the authorization and the core verifies it.
 * - **The page is never cached.** It is a guest page keyed to one person's
 *   token, so a cached copy would show one recipient's subscription to the
 *   next visitor. nocache_headers() covers the browser and any proxy, and
 *   DONOTCACHEPAGE is the constant every WordPress page cache honours.
 * - **The POST answers with the outcome and never a redirect.** RFC 8058
 *   forbids the sender returning an HTTPS redirect to a one-click POST, which
 *   also rules out the redirect-after-post this plugin uses everywhere else.
 *
 * ## The preference centre
 *
 * The whole list rather than one signed category, so this one does require a
 * session and does carry a nonce. A guest is shown the sign-in gate; the core
 * answers 401 to anyone who arrives without a session, so the rule is not
 * decided twice.
 *
 * Both are server rendered rather than React mounts, for the reason the design
 * gives: they are ordinary pages under the site's theme, inheriting its
 * accessibility conformance, and they have to work in whatever browser a mail
 * client opens.
 */
final class NotificationPages
{
    /**
     * The exact body RFC 8058 section 3.1 specifies, and the only one a mail
     * client sends. It is compared verbatim, because a receiver builds it
     * verbatim from the List-Unsubscribe-Post header value.
     */
    public const ONE_CLICK_FIELD = 'List-Unsubscribe';

    public const ONE_CLICK_VALUE = 'One-Click';

    public const PREFERENCES_NONCE = 'vidyalms_preferences';

    private const FREQUENCIES = ['immediate', 'daily', 'weekly', 'never'];

    /**
     * Set once per request by handleRequest(), so the template does not repeat
     * the call and a POST is not acted on twice.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $context = null;

    /**
     * The two page states, built once per request. A routed page and a
     * shortcode can both ask for one in the same request, and each build acts
     * on the POST.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $unsubscribe = null;

    /** @var array<string, mixed>|null */
    private static ?array $preferences = null;

    public static function register(): void
    {
        // Before Templates::handleRequest at 5, so the one-click POST is
        // answered and the request ended before anything else looks at it.
        add_action('template_redirect', [self::class, 'handleRequest'], 4);
        add_filter('template_include', [self::class, 'chooseTemplate'], 25);
        add_filter('wp_robots', [self::class, 'robots'], 20);
        add_filter('document_title_parts', [self::class, 'documentTitle'], 20);
    }

    /**
     * The whole decision for both pages, taken before any template runs.
     */
    public static function handleRequest(): void
    {
        $route = Rewrites::currentRoute();

        if ($route === Rewrites::ROUTE_UNSUBSCRIBE) {
            self::forbidCaching();
            self::$context = self::unsubscribeContext();

            return;
        }

        if ($route === Rewrites::ROUTE_PREFERENCES) {
            self::forbidCaching();

            if (!is_user_logged_in()) {
                // The gate replaces the redirect; the core is never asked for a guest's rows.
                self::$context = ['signin' => SignInGate::context('preferences', Rewrites::preferencesUrl())];

                return;
            }

            self::$context = self::preferencesContext();
        }
    }

    public static function chooseTemplate(string $template): string
    {
        $found = '';

        switch (Rewrites::currentRoute()) {
            case Rewrites::ROUTE_UNSUBSCRIBE:
                $found = Templates::locate('unsubscribe');
                break;

            case Rewrites::ROUTE_PREFERENCES:
                $found = Templates::locate('notification-preferences');
                break;
        }

        return $found !== '' ? $found : $template;
    }

    /**
     * Neither page belongs in an index: one discloses which category a person
     * is subscribed to, the other is one person's own settings.
     *
     * @param array<string, mixed> $robots
     *
     * @return array<string, mixed>
     */
    public static function robots(array $robots): array
    {
        $route = Rewrites::currentRoute();

        if ($route === Rewrites::ROUTE_UNSUBSCRIBE || $route === Rewrites::ROUTE_PREFERENCES) {
            unset($robots['index']);
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
        }

        return $robots;
    }

    /**
     * @param array<string, mixed> $parts
     *
     * @return array<string, mixed>
     */
    public static function documentTitle(array $parts): array
    {
        switch (Rewrites::currentRoute()) {
            case Rewrites::ROUTE_UNSUBSCRIBE:
                $parts['title'] = __('Unsubscribe', 'vidyalms');
                break;

            case Rewrites::ROUTE_PREFERENCES:
                $parts['title'] = __('Notification preferences', 'vidyalms');
                break;
        }

        return $parts;
    }

    /**
     * The context handleRequest() already built, so a template render cannot
     * act on a POST a second time.
     *
     * @return array<string, mixed>
     */
    public static function context(): array
    {
        return self::$context ?? [];
    }

    /**
     * The unsubscribe page's state. A GET previews and writes nothing; a POST
     * acts and its answer replaces the preview.
     *
     * @return array<string, mixed>
     */
    public static function unsubscribeContext(): array
    {
        // One request acts once. The routed page builds this from
        // template_redirect and the shortcode asks for it again while
        // rendering, so without the memo a page carrying the shortcode on the
        // unsubscribe route would post the same token to the core twice.
        if (self::$unsubscribe !== null) {
            return self::$unsubscribe;
        }

        $token = Rewrites::sanitizeToken((string) get_query_var(Rewrites::QUERY_TOKEN, ''));
        $path  = '/notifications/unsubscribe/' . rawurlencode($token);

        $context = [
            'token' => $token,
            'acted' => false,
            'result' => null,
            'form_action' => Rewrites::unsubscribeUrl($token),
        ];

        if ($token === '') {
            return self::$unsubscribe = $context;
        }

        if (!self::isPost()) {
            $context['result'] = CoreRoute::call('GET', $path, self::principal());

            return self::$unsubscribe = $context;
        }

        $channels = self::postedChannels();
        $outcome  = CoreRoute::call(
            'POST',
            $path,
            self::principal(),
            $channels === [] ? [] : ['channels' => $channels]
        );

        $context['acted']  = true;
        $context['result'] = $outcome;

        if (self::isOneClick()) {
            // A mail client reads a status code and nothing else, so it is
            // answered with the contract's own JSON rather than a rendered
            // page it will discard. Still not a redirect, which RFC 8058
            // forbids.
            status_header($outcome->status);
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');

            echo wp_json_encode($outcome->data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

            exit;
        }

        return self::$unsubscribe = $context;
    }

    /**
     * The preference centre's state. A POST replaces the caller's rows and the
     * page is then redrawn from the core's answer rather than from what it
     * posted.
     *
     * @return array<string, mixed>
     */
    public static function preferencesContext(): array
    {
        // One request writes once, for the same reason.
        if (self::$preferences !== null) {
            return self::$preferences;
        }

        $path = '/me/notification-preferences';

        $context = [
            'saved' => false,
            'save_error' => '',
            'submitted_rows' => [],
            'centre' => null,
            'form_action' => Rewrites::preferencesUrl(),
            'nonce_field' => wp_nonce_field(self::PREFERENCES_NONCE, '_wpnonce', true, false),
        ];

        if (self::isPost()) {
            $nonce = isset($_POST['_wpnonce']) && \is_string($_POST['_wpnonce'])
                ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
                : '';

            if (wp_verify_nonce($nonce, self::PREFERENCES_NONCE) === false) {
                $context['save_error'] = __('Your session has expired. Try again.', 'vidyalms');
            } else {
                $context['submitted_rows'] = self::postedRows();
                $outcome = CoreRoute::call(
                    'PUT',
                    $path,
                    self::principal(),
                    ['rows' => $context['submitted_rows']]
                );

                if ($outcome->ok()) {
                    $context['saved']  = true;
                    $context['centre'] = $outcome;
                } else {
                    $context['save_error'] = $outcome->detail() !== ''
                        ? $outcome->detail()
                        : __('Your preferences could not be saved.', 'vidyalms');
                }
            }
        }

        if (!$context['centre'] instanceof CoreRouteResult) {
            $context['centre'] = CoreRoute::call('GET', $path, self::principal());
        }

        return self::$preferences = $context;
    }

    private static function principal(): Principal
    {
        $userId = get_current_user_id();

        return $userId > 0 ? Principal::user($userId) : Principal::guest();
    }

    private static function isPost(): bool
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && \is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';

        return $method === 'POST';
    }

    private static function isOneClick(): bool
    {
        // Unsubscribe uses a purpose-scoped signed token; RFC 8058 mail clients cannot supply a WordPress nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $value = isset($_POST[self::ONE_CLICK_FIELD]) && \is_string($_POST[self::ONE_CLICK_FIELD])
            // Unsubscribe uses a purpose-scoped signed token; RFC 8058 mail clients cannot supply a WordPress nonce.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ? sanitize_text_field(wp_unslash($_POST[self::ONE_CLICK_FIELD]))
            : '';

        return $value === self::ONE_CLICK_VALUE;
    }

    /**
     * @return list<string>
     */
    private static function postedChannels(): array
    {
        // Unsubscribe uses a purpose-scoped signed token; RFC 8058 mail clients cannot supply a WordPress nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw = isset($_POST['channels']) && \is_array($_POST['channels'])
            // Unsubscribe uses a purpose-scoped signed token; RFC 8058 mail clients cannot supply a WordPress nonce.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ? array_map('sanitize_text_field', wp_unslash($_POST['channels']))
            : [];

        $channels = [];

        foreach ($raw as $key => $channel) {
            // Sanitization can coerce scalars; only originally string values are channel choices.
            // Unsubscribe uses a purpose-scoped signed token; RFC 8058 mail clients cannot supply a WordPress nonce.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (!\is_string($_POST['channels'][$key] ?? null)) {
                continue;
            }

            if (preg_match('/^[a-z0-9_.-]{1,64}$/', $channel) === 1) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * The rows the form posted, filtered to shapes the contract accepts.
     *
     * A frequency the contract does not name is dropped rather than sent: the
     * core answers 422 and writes nothing for the whole list, so one mangled
     * field would otherwise lose every other change on the form.
     *
     * @return list<array{category_key: string, channel_key: string, frequency: string}>
     */
    private static function postedRows(): array
    {
        // preferencesContext verifies PREFERENCES_NONCE before calling this private input helper.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw = isset($_POST['preference']) && \is_array($_POST['preference'])
            // preferencesContext verifies PREFERENCES_NONCE before calling this private input helper.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ? array_map('sanitize_text_field', wp_unslash($_POST['preference']))
            : [];

        $rows = [];

        foreach ($raw as $key => $frequency) {
            // preferencesContext verifies PREFERENCES_NONCE before calling this private input helper.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (!\is_string($key) || !\is_string($_POST['preference'][$key] ?? null)) {
                continue;
            }

            $key = sanitize_text_field(wp_unslash($key));

            if (!\in_array($frequency, self::FREQUENCIES, true)) {
                continue;
            }

            // The form names each control "preference[<category>|<channel>]",
            // because a nested name would arrive as an array of arrays and the
            // pair is one control's identity rather than two.
            $parts = explode('|', $key, 2);

            if (\count($parts) !== 2) {
                continue;
            }

            [$category, $channel] = $parts;

            if (preg_match('/^[a-z0-9_.-]{1,64}$/', $category) !== 1) {
                continue;
            }

            if (preg_match('/^[a-z0-9_.-]{1,64}$/', $channel) !== 1) {
                continue;
            }

            $rows[] = [
                'category_key' => $category,
                'channel_key'  => $channel,
                'frequency'    => $frequency,
            ];
        }

        return $rows;
    }

    /**
     * Both pages carry one person's state, so neither may be kept anywhere.
     * DONOTCACHEPAGE is the constant WP Super Cache, W3 Total Cache, LiteSpeed
     * Cache and WP Rocket all read; nocache_headers() covers the browser and
     * any proxy in front of them.
     *
     * Public because the shortcodes need it too: a shortcode cannot know which
     * page it is on until it runs, so it refuses caching at the moment it
     * renders rather than leaving the site owner to remember.
     */
    public static function forbidCaching(): void
    {
        if (!\defined('DONOTCACHEPAGE')) {
            \define('DONOTCACHEPAGE', true);
        }

        nocache_headers();

        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow', true);
            header('Referrer-Policy: no-referrer', true);
        }
    }
}
