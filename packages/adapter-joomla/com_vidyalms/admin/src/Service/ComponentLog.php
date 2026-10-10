<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;

/**
 * The component's own log file, for the failures a JSON surface may not put
 * in its answer.
 *
 * A 500 has to be recoverable from a log and nothing about it may reach the
 * client, so every JSON endpoint of this component writes here and answers a
 * bare problem document. The logger is registered on first use rather than at
 * boot, because a request that never fails should not add a handler.
 *
 * @since 1.0.0
 */
final class ComponentLog
{
    public const CATEGORY = 'com_vidyalms';

    private static bool $registered = false;

    private function __construct()
    {
    }

    public static function error(string $message): void
    {
        try {
            if (!self::$registered) {
                Log::addLogger(['text_file' => 'com_vidyalms.php'], Log::ALL, [self::CATEGORY]);
                self::$registered = true;
            }

            Log::add($message, Log::ERROR, self::CATEGORY);
        } catch (\Throwable) {
            // Logging a failure must never become a second failure.
        }
    }
}
