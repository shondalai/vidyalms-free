<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\WordPress\Admin\AdminApps;

defined('ABSPATH') || exit;

/** Guest-safe fragment landing. The server never reads an invitation token. */
final class OrganisationLanding
{
    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'render'], 0);
    }

    /**
     * The landing on the site's own scheme, like every other learner page.
     *
     * It used to force https, so on an http site the learner shell's My
     * organizations link was cross-origin: SPA navigation had to hand it to
     * the browser, which switched scheme with a full reload. home_url() picks
     * https whenever the request or the configured home URL uses it.
     */
    public static function url(): string
    {
        return add_query_arg('vidyalms_organisations', '1', home_url('/'));
    }

    public static function render(): void
    {
        // Read-only view selection; no state change. Access remains checked by the authenticated core route.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['vidyalms_organisations']) || $_GET['vidyalms_organisations'] !== '1') {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        status_header(200);
        $config = AdminApps::shared() + [
            'screen' => 'organisations',
            'urls' => [
                'home' => Rewrites::catalogueUrl(),
                'myLearning' => Rewrites::myLearningUrl(),
                'course' => str_replace('vidyalms-course-placeholder', '__VIDYALMS_SLUG__', Rewrites::courseUrl('vidyalms-course-placeholder')),
                'player' => str_replace('vidyalms-course-placeholder', '__VIDYALMS_SLUG__', Rewrites::learnUrl('vidyalms-course-placeholder')),
                // A fresh tab signs in; the original fragment page reloads for a fresh nonce.
                'login' => wp_login_url(self::url()),
            ],
        ];
        AdminApps::enqueue('vidyalms-app-credentials', 'credentials');
        Templates::enqueue();
        Templates::pageHeader();
        LearnerLayout::begin('organisations');
        echo '<main class="vidyalms"><div id="vidyalms-credentials" data-vy-config="'
            . esc_attr((string) wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            . '"><noscript>' . esc_html__('Enable JavaScript to manage organisation membership.', 'vidyalms')
            . '</noscript></div></main>';
        LearnerLayout::end();
        Templates::pageFooter();
        exit;
    }
}
