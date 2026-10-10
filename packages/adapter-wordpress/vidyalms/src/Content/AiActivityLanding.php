<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\WordPress\Admin\AdminApps;

defined('ABSPATH') || exit;

/**
 * The learner's own AI activity, which is the Article 15 answer this product
 * offers as a screen rather than as a paragraph.
 *
 * It mounts the credential bundle under the screen key `ai-activity` rather
 * than starting a fourth entry: the audience, the session and the asset are the
 * same as the holder's own credentials page, and a new bundle would be a new
 * asset on both hosts for one screen.
 *
 * The page is personal, so it is never cached and never indexed, and a
 * signed-out reader is shown the sign-in gate and never the list: the route it
 * reads answers for one signed-in person and for nobody else.
 */
final class AiActivityLanding
{
    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'render'], 1);
    }

    public static function url(): string
    {
        return add_query_arg('vidyalms_ai_activity', '1', home_url('/'));
    }

    public static function render(): void
    {
        // Read-only view selection; no state change. Access remains checked by the authenticated core route.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['vidyalms_ai_activity']) || $_GET['vidyalms_ai_activity'] !== '1') {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        DocumentTitle::register(__('My AI activity', 'vidyalms'));

        if (!is_user_logged_in()) {
            // A signed-out reader gets the sign-in gate, never the app, its REST nonce or its config.
            status_header(200);
            Templates::enqueue();
            Templates::pageHeader();
            LearnerLayout::begin('aiactivity');
            echo '<main class="vidyalms">';
            SignInGate::render(SignInGate::context('aiactivity', self::url(), __('My AI activity', 'vidyalms')));
            echo '</main>';
            LearnerLayout::end();
            Templates::pageFooter();

            exit;
        }

        status_header(200);
        $config = AdminApps::shared() + [
            'screen' => 'ai-activity',
            'routes' => AdminApps::servedRoutes(),
            'urls' => [
                'home' => Rewrites::catalogueUrl(),
                'myLearning' => Rewrites::myLearningUrl(),
                'preferences' => Rewrites::preferencesUrl(),
            ],
        ];
        AdminApps::enqueue('vidyalms-app-credentials', 'credentials');
        Templates::enqueue();
        Templates::pageHeader();
        LearnerLayout::begin('aiactivity');
        echo '<main class="vidyalms"><div id="vidyalms-credentials" data-vy-config="'
            . esc_attr((string) wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            . '"><noscript>' . esc_html__('Enable JavaScript to view your AI activity.', 'vidyalms')
            . '</noscript></div></main>';
        LearnerLayout::end();
        Templates::pageFooter();

        exit;
    }
}
