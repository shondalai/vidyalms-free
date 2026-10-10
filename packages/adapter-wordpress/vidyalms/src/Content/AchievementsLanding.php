<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\WordPress\Admin\AdminApps;

defined('ABSPATH') || exit;

/** Authenticated certificate and badge portfolio; no rewrite flush is required. */
final class AchievementsLanding
{
    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'render'], 1);
    }

    public static function url(): string
    {
        return add_query_arg('vidyalms_achievements', '1', home_url('/'));
    }

    public static function render(): void
    {
        // Read-only view selection; no state change. Access remains checked by the authenticated core route.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['vidyalms_achievements']) || $_GET['vidyalms_achievements'] !== '1') {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        DocumentTitle::register(__('Achievements', 'vidyalms'));
        if (!is_user_logged_in()) {
            // A signed-out reader gets the sign-in gate, never the app, its REST nonce or its config.
            status_header(200);
            Templates::enqueue();
            Templates::pageHeader();
            LearnerLayout::begin('achievements');
            echo '<main class="vidyalms">';
            SignInGate::render(SignInGate::context('achievements', self::url(), __('Achievements', 'vidyalms')));
            echo '</main>';
            LearnerLayout::end();
            Templates::pageFooter();
            exit;
        }
        status_header(200);
        $config = AdminApps::shared() + [
            'screen' => 'credentials',
            'routes' => AdminApps::servedRoutes(),
            'urls' => [
                'home' => Rewrites::catalogueUrl(),
                'myLearning' => Rewrites::myLearningUrl(),
                'organisations' => OrganisationLanding::url(),
                'preferences' => Rewrites::preferencesUrl(),
            ],
        ];
        AdminApps::enqueue('vidyalms-app-credentials', 'credentials');
        Templates::enqueue();
        Templates::pageHeader();
        LearnerLayout::begin('achievements');
        echo '<main class="vidyalms"><div id="vidyalms-credentials" data-vy-config="'
            . esc_attr((string) wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            . '"><noscript>' . esc_html__('Enable JavaScript to view your achievements.', 'vidyalms')
            . '</noscript></div></main>';
        LearnerLayout::end();
        Templates::pageFooter();
        exit;
    }
}
