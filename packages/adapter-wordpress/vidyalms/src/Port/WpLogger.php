<?php

declare(strict_types=1);

/**
 * VidyaLMS: the PSR-3 logger the Ports bundle requires.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Psr\Log\LoggerInterface;

defined('ABSPATH') || exit;

/**
 * The thirteenth service of the Ports bundle. Not a core port: the core takes a
 * plain PSR-3 LoggerInterface, and WordPress has no logger of its own.
 *
 * Every record is offered to the vidyalms_log action first, so a logging plugin
 * can take it, and is written to the PHP error log only when WP_DEBUG is on.
 * Writing unconditionally would fill the log of every site that installs this
 * plugin, which is what the WordPress guidelines are about.
 *
 * PSR-3 is implemented directly rather than through AbstractLogger, so the
 * plugin depends on the psr/log interface file alone.
 * Message parameters remain untyped so a host's PSR/log 1 interface and the
 * typed PSR/log 2/3 interfaces can all be implemented without a load-time fatal.
 */
final class WpLogger implements LoggerInterface
{
    public const ACTION = 'vidyalms_log';

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param mixed             $level
     * @param array<mixed>      $context
     */
    public function log($level, $message, array $context = []): void
    {
        $line = self::interpolate((string) $message, $context);
        $name = \is_scalar($level) ? (string) $level : 'info';

        do_action('vidyalms_log', $name, $line, $context);

        if (!\defined('WP_DEBUG') || WP_DEBUG !== true) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf('[VidyaLMS] %s: %s', strtoupper($name), $line));
    }

    /**
     * PSR-3 {placeholder} substitution. An exception in the context is a stack
     * trace, not a placeholder, so only stringable values are substituted.
     *
     * @param array<mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        $replacements = [];

        foreach ($context as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            if ($value === null || \is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
