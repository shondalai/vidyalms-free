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
 * The bundles contributed for one rendered mount.
 *
 * Same three rules as every registry in the core, for the same reasons:
 * register() refuses a duplicate name so an override is never an accident of
 * plugin ordering, replace() insists the name is already there and hands back
 * what it displaced so the replacement can carry the old bundle's mounts
 * forward, and there is no unregister(), because a bundle that must not load
 * is replaced by one that does not, which keeps the name resolvable for
 * whoever declared a dependency on it.
 *
 * @since 1.0.0
 */
final class MountAssetRegistry
{
    /** @var array<string, MountAssetBundle> keyed by asset name, in registration order */
    private array $bundles = [];

    public function register(MountAssetBundle $bundle): void
    {
        if (isset($this->bundles[$bundle->name])) {
            throw new \InvalidArgumentException(
                'The mount asset "' . $bundle->name . '" is already registered. Use replace() to override it.',
            );
        }

        $this->bundles[$bundle->name] = $bundle;
    }

    /**
     * Overrides a bundle another extension registered and returns what it
     * displaced, so the replacement can delegate rather than restate.
     */
    public function replace(MountAssetBundle $bundle): MountAssetBundle
    {
        if (!isset($this->bundles[$bundle->name])) {
            throw new \InvalidArgumentException(
                'The mount asset "' . $bundle->name . '" is not registered, so there is nothing to replace.',
            );
        }

        $displaced = $this->bundles[$bundle->name];

        $this->bundles[$bundle->name] = $bundle;

        return $displaced;
    }

    public function has(string $name): bool
    {
        return isset($this->bundles[$name]);
    }

    public function get(string $name): MountAssetBundle
    {
        if (!isset($this->bundles[$name])) {
            throw new \InvalidArgumentException('No mount asset is registered under "' . $name . '".');
        }

        return $this->bundles[$name];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->bundles);
    }

    /**
     * The bundles that belong on this mount, in registration order.
     *
     * @return list<MountAssetBundle>
     */
    public function bundlesFor(MountAssetContext $context): array
    {
        $applicable = [];

        foreach ($this->bundles as $bundle) {
            if ($bundle->appliesTo($context)) {
                $applicable[] = $bundle;
            }
        }

        return $applicable;
    }
}
