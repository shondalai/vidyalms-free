<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Setup;

defined('ABSPATH') || exit;

/** Bounded site enumeration with blog context restored even after failure. */
final class Sites
{
    public static function each(callable $action, ?int $networkId = null): void
    {
        if (!is_multisite()) {
            $action();
            return;
        }

        $offset = 0;
        do {
            $args = ['fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC'];
            if ($networkId !== null) {
                $args['network_id'] = $networkId;
            }
            $ids = get_sites($args);
            foreach ($ids as $id) {
                self::on((int) $id, $action);
            }
            $offset += \count($ids);
        } while (\count($ids) === 100);
    }

    public static function on(int $siteId, callable $action): void
    {
        switch_to_blog($siteId);
        try {
            $action();
        } finally {
            restore_current_blog();
        }
    }

    public static function networkActive(?int $networkId = null): bool
    {
        if (!is_multisite()) {
            return false;
        }
        $active = get_network_option($networkId ?? get_current_network_id(), 'active_sitewide_plugins', []);
        return \is_array($active) && isset($active[plugin_basename(VIDYALMS_PLUGIN_FILE)]);
    }
}
