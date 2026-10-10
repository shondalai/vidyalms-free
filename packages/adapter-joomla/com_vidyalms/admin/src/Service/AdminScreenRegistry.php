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
 * Ordered registry of administrator SPA screens.
 *
 * @since 1.4.0
 */
final class AdminScreenRegistry
{
    /** @var array<string, AdminScreenDefinition> */
    private array $screens = [];

    public function register(AdminScreenDefinition $screen): void
    {
        if (isset($this->screens[$screen->key])) {
            throw new \LogicException('The administrator screen "' . $screen->key . '" is already registered.');
        }

        $this->screens[$screen->key] = $screen;
    }

    public function has(string $key): bool
    {
        return isset($this->screens[$key]);
    }

    public function get(string $key): ?AdminScreenDefinition
    {
        return $this->screens[$key] ?? null;
    }

    /**
     * @return list<AdminScreenDefinition>
     */
    public function all(): array
    {
        return array_values($this->screens);
    }
}
