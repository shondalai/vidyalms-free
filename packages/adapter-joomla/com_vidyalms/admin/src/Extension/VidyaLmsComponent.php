<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Categories\CategoryServiceTrait;
use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\HTML\HTMLRegistryAwareTrait;

/**
 * The com_vidyalms bootstrap.
 *
 * The learning engine lives in the core and is resolved from the global
 * container through KernelFactory. Joomla's generic category item-count links
 * cannot target the SPA's URL-backed course filters yet, so this shell keeps
 * those misleading badges suppressed until a truthful destination exists.
 *
 * @since 1.0.0
 */
final class VidyaLmsComponent extends MVCComponent implements RouterServiceInterface, CategoryServiceInterface
{
    use CategoryServiceTrait;
    use HTMLRegistryAwareTrait;
    use RouterServiceTrait;

    /**
     * Do not add count_* properties: com_categories turns each one into a link
     * to a native list view, while VidyaLMS courses are filtered by SPA hash
     * state. Rendering a numeric badge would therefore promise navigation that
     * currently opens the dashboard and ignores the category.
     *
     * @param list<\stdClass> $items
     */
    public function countItems(array $items, string $section): void
    {
        // Intentionally empty; see the method contract above.
    }
}
