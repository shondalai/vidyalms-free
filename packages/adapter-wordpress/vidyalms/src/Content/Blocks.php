<?php

declare(strict_types=1);

/**
 * VidyaLMS: the three learner blocks, server rendered.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

use WP_Block_Type_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The same three surfaces as the shortcodes, registered as blocks with a
 * render_callback so the markup is produced on the server.
 *
 * Server rendering is not an implementation detail here: a catalogue saved into
 * post content would be a snapshot of prices and courses that goes stale the
 * moment anything changes, and a learner dashboard saved into post content
 * would be one learner's data served to everyone. The block stores its settings
 * and nothing else; every value on the page is read at request time through a
 * core use case, exactly as the routed page reads it.
 */
final class Blocks
{
    public const CATALOGUE = 'vidyalms/catalogue';
    public const MY_LEARNING = 'vidyalms/my-learning';
    public const CERTIFICATE_VERIFY = 'vidyalms/certificate-verify';

    public static function register(): void
    {
        add_action('init', [self::class, 'registerBlocks'], 20);
    }

    public static function registerBlocks(): void
    {
        if (!\function_exists('register_block_type')) {
            return;
        }

        wp_register_script('vidyalms-blocks', plugins_url('assets/js/blocks.js', dirname(__DIR__, 2) . '/vidyalms.php'),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n'],
            \Shondalai\VidyaLMS\WordPress\Plugin::VERSION, true);
        wp_set_script_translations('vidyalms-blocks', 'vidyalms');

        self::registerBlock(
            self::CATALOGUE,
            __('Course catalogue', 'vidyalms'),
            __('The published VidyaLMS courses, with search and pagination.', 'vidyalms'),
            [
                // Zero means "use the site setting", which is what an editor
                // who has not touched the control should get.
                'perPage' => ['type' => 'number', 'default' => 0],
                'category' => ['type' => 'number', 'default' => 0],
                'showSearch' => ['type' => 'boolean', 'default' => true],
                'heading' => ['type' => 'string', 'default' => ''],
            ],
            [self::class, 'catalogue'],
        );

        self::registerBlock(
            self::MY_LEARNING,
            __('My learning', 'vidyalms'),
            __('The signed in learner\'s courses, progress and certificates.', 'vidyalms'),
            [
                'showCertificates' => ['type' => 'boolean', 'default' => true],
            ],
            [self::class, 'myLearning'],
        );

        self::registerBlock(
            self::CERTIFICATE_VERIFY,
            __('Certificate verification', 'vidyalms'),
            __('A public form that checks a certificate code and shows the result.', 'vidyalms'),
            [
                'code' => ['type' => 'string', 'default' => ''],
                'showForm' => ['type' => 'boolean', 'default' => true],
            ],
            [self::class, 'certificateVerify'],
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function catalogue(array $attributes = []): string
    {
        $arguments = [
            'category' => (int) ($attributes['category'] ?? 0),
            'show_search' => self::boolAttribute($attributes, 'showSearch', true) ? 'yes' : 'no',
            'heading' => (string) ($attributes['heading'] ?? ''),
        ];

        $perPage = (int) ($attributes['perPage'] ?? 0);

        if ($perPage > 0) {
            $arguments['per_page'] = $perPage;
        }

        $markup = Shortcodes::catalogue($arguments);

        return self::wrap($markup, 'vidyalms-block-catalogue');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function myLearning(array $attributes = []): string
    {
        $markup = Shortcodes::myLearning([
            'show_certificates' => self::boolAttribute($attributes, 'showCertificates', true) ? 'yes' : 'no',
        ]);

        return self::wrap($markup, 'vidyalms-block-my-learning');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function certificateVerify(array $attributes = []): string
    {
        $markup = Shortcodes::certificateVerify([
            'code' => (string) ($attributes['code'] ?? ''),
            'show_form' => self::boolAttribute($attributes, 'showForm', true) ? 'yes' : 'no',
        ]);

        return self::wrap($markup, 'vidyalms-block-certificate-verify');
    }

    /**
     * @param array<string, array<string, mixed>> $attributes
     * @param callable                            $render
     */
    private static function registerBlock(
        string $name,
        string $title,
        string $description,
        array $attributes,
        $render,
    ): void {
        if (WP_Block_Type_Registry::get_instance()->is_registered($name)) {
            return;
        }

        register_block_type($name, [
            'api_version' => 3,
            'editor_script' => 'vidyalms-blocks',
            'title' => $title,
            'description' => $description,
            'category' => 'widgets',
            'icon' => 'welcome-learn-more',
            'keywords' => [
                __('course', 'vidyalms'),
                __('learning', 'vidyalms'),
                __('lms', 'vidyalms'),
            ],
            'textdomain' => 'vidyalms',
            'attributes' => $attributes,
            'supports' => [
                'html' => false,
                'align' => ['wide', 'full'],
            ],
            'render_callback' => $render,
        ]);
    }

    private static function wrap(string $markup, string $class): string
    {
        if ($markup === '') {
            return '';
        }

        $attributes = \function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['class' => $class])
            : 'class="' . esc_attr($class) . '"';

        return '<div ' . $attributes . '>' . $markup . '</div>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function boolAttribute(array $attributes, string $key, bool $default): bool
    {
        if (!\array_key_exists($key, $attributes)) {
            return $default;
        }

        return filter_var($attributes[$key], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
