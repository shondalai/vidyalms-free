<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Factory;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\Integration;

/** Presentation only. Native URLs retain the host's routing and access checks. */
final class LearnerLayout
{
    /** Unrelated menu items must not supply a "Home" title to a learner page. */
    public static function pageParameters(Registry $params, string $view): Registry
    {
        $app = Factory::getApplication();
        $active = $app instanceof SiteApplication ? $app->getMenu()->getActive() : null;
        if (($active->query['option'] ?? '') === 'com_vidyalms' && ($active->query['view'] ?? '') === $view) {
            return $params;
        }
        $params = clone $params;
        foreach (['page_title', 'page_heading', 'show_page_heading', 'menu-meta_description'] as $key) {
            $params->remove($key);
        }
        return $params;
    }

    public static function begin(string $active): void
    {
        $configuration = KernelFactory::configuration();
        $navigation = $configuration->get('learner_navigation', 'top') === 'sidebar' ? 'sidebar' : 'top';
        $name = trim((string) $configuration->get('academy_name', '')) ?: Text::_('COM_VIDYALMS_LEARNING_SPACE');
        $name = trim((string) $configuration->get('learner_header_title', '')) ?: $name;
        $tagline = trim((string) $configuration->get('learner_header_tagline', '')) ?: Text::_('COM_VIDYALMS_YOUR_LEARNING');
        $display = (string) $configuration->get('learner_header_display', 'full');
        $display = in_array($display, ['full', 'navigation', 'hidden'], true) ? $display : 'full';
        $density = $configuration->get('learner_header_density', 'comfortable') === 'compact' ? 'compact' : 'comfortable';
        $logo = (string) $configuration->get('learner_header_logo_url', '');
        $logoSize = max(24, min(80, (int) $configuration->get('learner_header_logo_size', 42)));
        $user = Factory::getApplication()->getIdentity();
        $isGuest = $user === null || $user->guest;
        $home = $isGuest ? 'catalogue' : 'mylearning';
        $links = [
            'catalogue' => ['COM_VIDYALMS_EXPLORE_COURSES', 'catalogue'],
        ];
        // My learning already provides the sign-in welcome page for guests.
        $links += $isGuest ? [
            'signin' => ['COM_VIDYALMS_GET_STARTED', 'mylearning'],
        ] : [
            'mylearning' => ['COM_VIDYALMS_MYLEARNING_TITLE', 'mylearning'],
            'achievements' => ['COM_VIDYALMS_ACHIEVEMENTS_TITLE', 'achievements'],
            'organisations' => ['COM_VIDYALMS_ORGANISATIONS_TITLE', 'organisations'],
            'preferences' => ['COM_VIDYALMS_LEARNER_PREFERENCES', 'preferences'],
            'aiactivity' => ['COM_VIDYALMS_AI_ACTIVITY_TITLE', 'aiactivity'],
        ];
        if ($isGuest && in_array($active, SignInGate::PAGES, true)) {
            $active = 'signin';
        }
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?>
        <div class="vidyalms vy-learner-shell" data-vy-learner-shell
             data-vy-navigation="<?php echo $navigation; ?>"
             data-vy-header="<?php echo $display; ?>" data-vy-header-density="<?php echo $density; ?>"
             data-vy-header-divider="<?php echo $configuration->get('learner_header_divider', true) ? 'true' : 'false'; ?>"
             style="--vy-learner-logo-size:<?php echo $logoSize; ?>px"
             data-vy-site-key="<?php echo $escape(Uri::root()); ?>"
             data-vy-loading-label="<?php echo $escape(Text::_('COM_VIDYALMS_LOADING_PAGE')); ?>"
             data-vy-leave-confirm="<?php echo $escape(Text::_('COM_VIDYALMS_LEAVE_UNSAVED')); ?>">
            <?php if ($display === 'full') : ?>
            <header class="vy-learning-brandbar">
                <a class="vy-learning-brand" data-vy-page="<?php echo $escape($home); ?>" href="<?php echo $escape(Route::_('index.php?option=com_vidyalms&view=' . $home, false)); ?>">
                    <?php if ($configuration->get('learner_header_show_logo', true)) : ?>
                        <?php if ($logo !== '') : ?>
                            <img class="vy-learning-logo" src="<?php echo $escape($logo); ?>" alt="" height="<?php echo $logoSize; ?>" referrerpolicy="no-referrer">
                        <?php else : ?>
                            <span class="vy-learning-mark" aria-hidden="true">V</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <span><?php echo $escape($name); ?><?php if ($configuration->get('learner_header_show_tagline', true)) : ?><small><?php echo $escape($tagline); ?></small><?php endif; ?></span>
                </a>
            </header>
            <?php endif; ?>
            <?php if ($display !== 'hidden') : ?>
            <nav class="vy-learning-navigation" aria-label="<?php echo $escape(Text::_('COM_VIDYALMS_LEARNER_NAVIGATION')); ?>">
                <?php foreach ($links as $id => [$label, $view]) : ?>
                    <a data-vy-page="<?php echo $escape($id); ?>" href="<?php echo $escape(Route::_('index.php?option=com_vidyalms&view=' . $view, false)); ?>"<?php echo $id === $active ? ' aria-current="page"' : ''; ?>><?php echo Text::_($label); ?></a>
                <?php endforeach; ?>
                <?php if (!$isGuest && Integration::available()) : ?>
                    <a href="<?php echo $escape(Route::_('index.php?option=com_easycommerce&view=account', false) . '#/account'); ?>"><?php echo Text::_('COM_VIDYALMS_SHOP_ACCOUNT'); ?></a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
            <div class="vy-learning-content" tabindex="-1">
        <?php
    }

    public static function end(): void
    {
        echo '</div></div>';
    }
}
