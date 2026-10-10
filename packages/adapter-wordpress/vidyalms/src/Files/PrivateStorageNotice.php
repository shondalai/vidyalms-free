<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Files;

use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety;
use Shondalai\VidyaLMS\WordPress\Plugin;

defined('ABSPATH') || exit;

/** Read-only administrator preflight; never creates folders during page rendering. */
final class PrivateStorageNotice
{
    public static function render(): void
    {
        $screen = get_current_screen();
        if ($screen === null || !str_contains((string) $screen->id, 'vidyalms')) {
            return;
        }
        if (!current_user_can('manage_options') && !current_user_can('vidyalms_manage')) {
            return;
        }
        try {
            $root = dirname(Plugin::storage()->privatePath('.storage-health'));
            if (LocalStorageSafety::writableDirectory($root)) {
                return;
            }
        } catch (\Throwable) {
            // No filesystem paths or exception details are exposed in the notice.
        }
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'VidyaLMS could not set up private storage outside your public website. Private uploads, imports and certificate downloads need a writable folder there; public course images still work. Ask your hosting provider to define VIDYALMS_PRIVATE_DIR in wp-config.php before WordPress loads. Use the same location for scheduled workers and include it in PHP open_basedir. Copy existing private files before changing the location. Setup examples are in the adapter README.',
            'vidyalms',
        );
        echo '</p></div>';
    }
}
