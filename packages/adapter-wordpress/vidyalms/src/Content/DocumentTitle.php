<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Content;

defined('ABSPATH') || exit;

/**
 * Titles the pages this plugin prints on its own canvas.
 *
 * Achievements, AI activity and the organisations landing render at
 * template_redirect, outside any block template. A block theme gets its
 * <title> only from core's own template canvas, so those pages had none
 * (WCAG 2.4.2). Templates::pageHeader() applies the canvas rule and a landing
 * names itself here.
 */
final class DocumentTitle
{
    /** Names the current page: its own title, then the site name. */
    public static function register(string $title): void
    {
        add_filter('document_title_parts', static fn (array $parts): array => self::parts($parts, $title), 20);
    }

    /**
     * The rule of core's block template canvas, for a canvas pageHeader()
     * opens itself: exactly one title, printed whether or not the theme
     * declares title-tag support.
     */
    public static function onCanvas(): void
    {
        // A routed page came through core's block template lookup, which already hooked its own title.
        if (has_action('wp_head', '_block_template_render_title_tag') !== false) {
            return;
        }

        remove_action('wp_head', '_wp_render_title_tag', 1);
        add_action('wp_head', [self::class, 'printTag'], 1);
    }

    /** Prints the document title; esc_html() leaves the entities core's own filters produced intact. */
    public static function printTag(): void
    {
        echo '<title>' . esc_html(wp_get_document_title()) . '</title>' . "\n";
    }

    /**
     * @param array<string, string> $parts
     *
     * @return array<string, string>
     */
    public static function parts(array $parts, string $title): array
    {
        // These pages hang off the home URL, where WordPress names the site and adds its tagline instead.
        unset($parts['tagline'], $parts['page']);
        $parts['title'] = $title;
        $parts['site'] = get_bloginfo('name', 'display');

        return $parts;
    }
}
