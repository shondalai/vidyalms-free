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

/**
 * One administrator SPA screen contributed to VidyaLMS.
 *
 * The host owns only the route, title and toolbar icon. The JavaScript bundle
 * that registered the same key owns everything rendered inside the mount.
 *
 * @since 1.4.0
 */
final class AdminScreenDefinition
{
    /** @param list<string> $requiredAnyActions */
    public function __construct(
        public readonly string $key,
        public readonly string $query,
        public readonly string $titleKey,
        public readonly string $icon,
        public readonly array $requiredAnyActions = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $key) !== 1) {
            throw new \InvalidArgumentException('An administrator screen key must be lower-case kebab-case.');
        }

        if ($query === '' || str_starts_with($query, '?') || str_contains($query, '#')) {
            throw new \InvalidArgumentException('An administrator screen query must be a non-empty query fragment.');
        }

        if ($titleKey === '' || $icon === '') {
            throw new \InvalidArgumentException('An administrator screen needs a title key and an icon.');
        }
    }
}
