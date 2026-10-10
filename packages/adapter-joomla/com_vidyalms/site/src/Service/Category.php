<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Categories\Categories;

/**
 * Joomla's category tree service for com_vidyalms.
 *
 * Joomla's generic item counter compares a content state to integer `1`.
 * VidyaLMS course states are domain strings, and Joomla's generated count
 * links cannot yet address the SPA's category filters. Counting therefore
 * stays disabled rather than rendering badges that navigate to the dashboard.
 *
 * @since 1.0.0
 */
final class Category extends Categories
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $options['table'] = '#__vidyalms_courses';
        $options['extension'] = 'com_vidyalms';
        $options['field'] = 'category_id';
        $options['statefield'] = 'state';
        $options['countItems'] = 0;

        parent::__construct($options);
    }
}
