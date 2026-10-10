<?php

declare(strict_types=1);

/**
 * VidyaLMS: the three learner shortcodes.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\WordPress\Setup\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The catalogue, the learner dashboard and certificate verification, placeable
 * on any page. They render the same template parts as the routed pages, so a
 * site that prefers its own page structure to /courses and /my-learning gets
 * identical markup and identical behaviour.
 *
 * A shortcode returns its markup and never echoes: WordPress inserts the return
 * value where the shortcode stood.
 */
final class Shortcodes
{
    public const CATALOGUE = 'vidyalms_catalogue';
    public const MY_LEARNING = 'vidyalms_my_learning';
    public const CERTIFICATE_VERIFY = 'vidyalms_certificate_verify';
    public const UNSUBSCRIBE = 'vidyalms_unsubscribe';
    public const PREFERENCES = 'vidyalms_notification_preferences';

    public static function register(): void
    {
        add_shortcode(self::CATALOGUE, [self::class, 'catalogue']);
        add_shortcode(self::MY_LEARNING, [self::class, 'myLearning']);
        add_shortcode(self::CERTIFICATE_VERIFY, [self::class, 'certificateVerify']);
        add_shortcode(self::UNSUBSCRIBE, [self::class, 'unsubscribe']);
        add_shortcode(self::PREFERENCES, [self::class, 'notificationPreferences']);
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public static function catalogue($atts = []): string
    {
        $atts = shortcode_atts(
            [
                // The site setting is the default; a stated attribute wins.
                'per_page' => Settings::cataloguePerPage(),
                'category' => 0,
                'search' => '',
                'show_search' => 'yes',
                'heading' => '',
            ],
            self::attributes($atts),
            self::CATALOGUE,
        );

        $args = [
            'per_page' => (int) $atts['per_page'],
            'show_search' => $atts['show_search'],
            'heading' => (string) $atts['heading'],
        ];

        // Only a stated attribute overrides the request: left out, the search
        // box and the pager keep working on whatever page the shortcode is on.
        if ((string) $atts['search'] !== '') {
            $args['search'] = (string) $atts['search'];
        }

        if ((int) $atts['category'] > 0) {
            $args['category'] = (int) $atts['category'];
        }

        return Templates::render('part-catalogue', Templates::catalogueContext($args));
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public static function myLearning($atts = []): string
    {
        $atts = shortcode_atts(
            ['show_certificates' => 'yes'],
            self::attributes($atts),
            self::MY_LEARNING,
        );

        return Templates::render(
            'part-my-learning',
            Templates::myLearningContext(['show_certificates' => $atts['show_certificates']]),
        );
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public static function certificateVerify($atts = []): string
    {
        NotificationPages::forbidCaching();
        $atts = shortcode_atts(
            [
                'code' => '',
                'show_form' => 'yes',
            ],
            self::attributes($atts),
            self::CERTIFICATE_VERIFY,
        );

        $code = (string) $atts['code'];

        if ($code === '') {
            $code = (string) get_query_var(Rewrites::QUERY_CODE, '');
        }

        $context = Templates::certificateContext($code);
        $context['show_form'] = $atts['show_form'] !== 'no' && $atts['show_form'] !== '0';

        return Templates::render('part-certificate', $context);
    }

    /**
     * The unsubscribe form, for a site that prefers its own page structure to
     * /unsubscribe. It renders the part the routed page renders, so the two
     * cannot behave differently.
     *
     * The page it sits on must not be cached. The routed page says so itself;
     * a shortcode cannot know which page it is on until it runs, so it says
     * the same thing at the moment it renders rather than leaving the site
     * owner to remember.
     *
     * @param array<string, mixed>|string $atts
     */
    public static function unsubscribe($atts = []): string
    {
        NotificationPages::forbidCaching();

        return Templates::render('part-unsubscribe', NotificationPages::unsubscribeContext());
    }

    /**
     * The preference centre, for the same reason.
     *
     * A guest is never redirected here: a shortcode runs inside a page the
     * site owner chose and may not take it over. A guest gets the same sign-in
     * gate the routed page shows, and the core is never asked for a guest's
     * rows.
     *
     * @param array<string, mixed>|string $atts
     */
    public static function notificationPreferences($atts = []): string
    {
        NotificationPages::forbidCaching();

        if (!is_user_logged_in()) {
            return Templates::render('part-notification-preferences', ['signin' => SignInGate::context('preferences', Rewrites::preferencesUrl())]);
        }

        return Templates::render('part-notification-preferences', NotificationPages::preferencesContext());
    }

    /**
     * WordPress hands an empty string rather than an empty array when a
     * shortcode carries no attributes.
     *
     * @param array<string, mixed>|string $atts
     *
     * @return array<string, mixed>
     */
    private static function attributes($atts): array
    {
        return \is_array($atts) ? $atts : [];
    }
}
