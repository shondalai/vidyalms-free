<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Setup;

defined('ABSPATH') || exit;

/** Connection-owned lock: atomic, per site, and released on connection loss. */
final class SchemaLock
{
    public static function run(callable $work): bool
    {
        global $wpdb;
        // Include the database because advisory lock names are server-wide.
        $name = 'vidyalms:' . hash('sha256', (string) $wpdb->dbname . ':' . $wpdb->prefix);
        $name = substr($name, 0, 64);
        // Connection-scoped schema locks require live SQL; WordPress provides no equivalent locking API.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            return false;
        }
        try {
            $work();
        } finally {
            // Connection-scoped schema locks require live SQL; WordPress provides no equivalent locking API.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
        return true;
    }
}
