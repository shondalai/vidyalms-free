<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\Catalogue\HtmlView $this */

$language = Factory::getApplication()->getLanguage();
$heading  = (string) $this->params->get('page_heading', $this->params->get('page_title', ''));

if ($heading === '') {
    $heading = Text::_('COM_VIDYALMS_CATALOGUE_TITLE');
}
\VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::begin('catalogue');
?>
<div class="vidyalms vidyalms-catalogue">
    <p class="vy-page-kicker"><?php echo Text::_('COM_VIDYALMS_DISCOVERY_EYEBROW'); ?></p>
    <?php if ((int) $this->params->get('show_page_heading', 1) === 1) : ?>
        <h1 class="vidyalms-page-title"><?php echo $this->escape($heading); ?></h1>
    <?php endif; ?>

    <p class="vy-page-description"><?php echo Text::_('COM_VIDYALMS_DISCOVERY_INTRO'); ?></p>
    <form data-vy-page-form class="vidyalms-filters" action="<?php echo $this->formAction; ?>" method="get" role="search">
        <div class="vidyalms-field">
            <label class="vidyalms-label" for="vidyalms-filter-q"><?php echo Text::_('COM_VIDYALMS_CATALOGUE_SEARCH_LABEL'); ?></label>
            <input
                class="vidyalms-input"
                type="search"
                id="vidyalms-filter-q"
                name="q"
                value="<?php echo $this->escape($this->search); ?>"
                placeholder="<?php echo $this->escape(Text::_('COM_VIDYALMS_CATALOGUE_SEARCH_PLACEHOLDER')); ?>">
        </div>

        <?php if ($this->categoryOptions !== []) : ?>
            <div class="vidyalms-field">
                <label class="vidyalms-label" for="vidyalms-filter-category"><?php echo Text::_('COM_VIDYALMS_CATALOGUE_CATEGORY_LABEL'); ?></label>
                <select class="vidyalms-input" id="vidyalms-filter-category" name="category">
                    <option value="0"><?php echo Text::_('COM_VIDYALMS_CATALOGUE_CATEGORY_ANY'); ?></option>
                    <?php foreach ($this->categoryOptions as $option) : ?>
                        <option value="<?php echo (int) $option->value; ?>"<?php echo (int) $option->value === $this->category ? ' selected' : ''; ?>>
                            <?php echo $this->escape((string) $option->text); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="vidyalms-field vidyalms-field-actions">
            <button class="vidyalms-button" type="submit"><?php echo Text::_('COM_VIDYALMS_CATALOGUE_SEARCH_SUBMIT'); ?></button>
        </div>

        <input type="hidden" name="option" value="com_vidyalms">
        <input type="hidden" name="view" value="catalogue">
        <?php if ($this->itemId > 0) : ?>
            <input type="hidden" name="Itemid" value="<?php echo $this->itemId; ?>">
        <?php endif; ?>
    </form>

    <p class="vidyalms-result-count" role="status">
        <?php echo Text::plural('COM_VIDYALMS_CATALOGUE_COUNT_N', (int) $this->page->total); ?>
    </p>

    <?php if ($this->page->items === []) : ?>
        <p class="vidyalms-empty"><?php echo Text::_('COM_VIDYALMS_CATALOGUE_EMPTY'); ?></p>
    <?php else : ?>
        <ul class="vidyalms-grid">
            <?php foreach ($this->page->items as $course) : ?>
                <?php
                $url   = Route::_('index.php?option=com_vidyalms&view=course&slug=' . urlencode($course->slug->value));
                $price = $this->prices[$course->id] ?? null;
                $level = (string) ($course->level ?? '');
                $key   = 'COM_VIDYALMS_LEVEL_' . strtoupper($level);
                ?>
                <li class="vidyalms-grid-item">
                    <article class="vidyalms-card">
                        <?php if (!empty($course->image)) : ?>
                            <?php $image = HTMLHelper::cleanImageURL((string) $course->image); ?>
                            <div class="vidyalms-card-media">
                                <?php echo HTMLHelper::_('image', $image->url, '', ['class' => 'vidyalms-card-image', 'loading' => 'lazy']); ?>
                            </div>
                        <?php else : ?>
                            <div class="vy-course-art" aria-hidden="true"><small><?php echo Text::_('COM_VIDYALMS_COURSE_INITIAL_ART'); ?></small><strong><?php echo $this->escape(mb_substr($course->title, 0, 1)); ?></strong></div>
                        <?php endif; ?>

                        <h2 class="vidyalms-card-title">
                            <a href="<?php echo $url; ?>"><?php echo $this->escape($course->title); ?></a>
                        </h2>

                        <?php if (!empty($course->summary)) : ?>
                            <p class="vidyalms-card-summary"><?php echo $this->escape((string) $course->summary); ?></p>
                        <?php endif; ?>

                        <p class="vidyalms-card-meta">
                            <?php if ($level !== '') : ?>
                                <span class="vidyalms-badge">
                                    <span class="vidyalms-badge-label"><?php echo Text::_('COM_VIDYALMS_COURSE_LEVEL'); ?></span>
                                    <?php echo $this->escape($language->hasKey($key) ? Text::_($key) : $level); ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($course->priceMode === 'free') : ?>
                                <span class="vidyalms-badge vidyalms-badge-free"><?php echo Text::_('COM_VIDYALMS_COURSE_FREE'); ?></span>
                            <?php elseif ($price !== null) : ?>
                                <span class="vidyalms-badge vidyalms-badge-price">
                                    <span class="vidyalms-badge-label"><?php echo Text::_('COM_VIDYALMS_COURSE_PRICE'); ?></span>
                                    <?php echo $this->escape($price->formatted ?? ($price->currency . ' ' . number_format($price->amountMinor / 100, 2))); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                        <a class="vy-course-link" href="<?php echo $url; ?>"><?php echo Text::_('COM_VIDYALMS_VIEW_COURSE'); ?></a>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($this->pagination->pagesTotal > 1) : ?>
            <div class="vidyalms-pagination">
                <?php echo $this->pagination->getPagesLinks(); ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::end(); ?>
