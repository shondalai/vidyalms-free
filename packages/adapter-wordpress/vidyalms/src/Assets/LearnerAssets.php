<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Assets;

defined('ABSPATH') || exit;

/** Explicitly identifies mount assets that may load during learner navigation. */
final class LearnerAssets
{
    /** @var array<string, true> */
    private static array $handles = [];

    public static function mark(string $handle): void
    {
        if (self::$handles === []) {
            add_filter('wp_script_attributes', [self::class, 'scriptAttributes']);
            add_filter('script_loader_tag', [self::class, 'tag'], 20, 2);
            add_filter('style_loader_tag', [self::class, 'tag'], 20, 2);
        }
        self::$handles[$handle] = true;
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public static function scriptAttributes(array $attributes): array
    {
        $id = (string) ($attributes['id'] ?? '');
        foreach (array_keys(self::$handles) as $handle) {
            if ($id === $handle . '-js-module' || $id === $handle . '-js') {
                $attributes['data-vy-spa-asset'] = 'true';
            }
        }
        return $attributes;
    }

    public static function tag(string $tag, string $handle): string
    {
        if (!isset(self::$handles[$handle])) {
            return $tag;
        }
        $processor = new \WP_HTML_Tag_Processor($tag);
        if ($processor->next_tag()) {
            $processor->set_attribute('data-vy-spa-asset', 'true');
        }
        return $processor->get_updated_html();
    }
}
