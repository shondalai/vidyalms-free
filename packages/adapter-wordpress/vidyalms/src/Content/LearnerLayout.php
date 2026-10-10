<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Content;

defined('ABSPATH') || exit;

use Shondalai\VidyaLMS\WordPress\Setup\Settings;

/** Presentation only. Native URLs retain the host's routing and access checks. */
final class LearnerLayout
{
    public static function begin(string $active): void
    {
        $configuration = Settings::configuration();
        $navigation = $configuration->get('learner_navigation', 'top') === 'sidebar' ? 'sidebar' : 'top';
        $name = trim((string) $configuration->get('academy_name', '')) ?: __('Learning space', 'vidyalms');
        $name = trim((string) $configuration->get('learner_header_title', '')) ?: $name;
        $tagline = trim((string) $configuration->get('learner_header_tagline', '')) ?: __('Your learning, your pace.', 'vidyalms');
        $display = (string) $configuration->get('learner_header_display', 'full');
        $display = in_array($display, ['full', 'navigation', 'hidden'], true) ? $display : 'full';
        $density = $configuration->get('learner_header_density', 'comfortable') === 'compact' ? 'compact' : 'comfortable';
        $logo = (string) $configuration->get('learner_header_logo_url', '');
        $logoSize = max(24, min(80, (int) $configuration->get('learner_header_logo_size', 42)));
        $isGuest = !is_user_logged_in();
        $home = $isGuest ? 'catalogue' : 'mylearning';
        $homeUrl = $isGuest ? Rewrites::catalogueUrl() : Rewrites::myLearningUrl();
        $links = [
            'catalogue' => [__('Explore courses', 'vidyalms'), Rewrites::catalogueUrl()],
        ];
        // My learning already provides the sign-in welcome page for guests.
        $links += $isGuest ? [
            'signin' => [__('Get started', 'vidyalms'), Rewrites::myLearningUrl()],
        ] : [
            'mylearning' => [__('My learning', 'vidyalms'), Rewrites::myLearningUrl()],
            'achievements' => [__('Achievements', 'vidyalms'), AchievementsLanding::url()],
            'organisations' => [__('My organizations', 'vidyalms'), OrganisationLanding::url()],
            'preferences' => [__('Notification preferences', 'vidyalms'), Rewrites::preferencesUrl()],
        ];
        if ($isGuest && in_array($active, SignInGate::PAGES, true)) {
            $active = 'signin';
        }
        ?>
        <div class="vidyalms vy-learner-shell" data-vy-learner-shell
             data-vy-navigation="<?php echo esc_attr($navigation); ?>"
             data-vy-header="<?php echo esc_attr($display); ?>" data-vy-header-density="<?php echo esc_attr($density); ?>"
             data-vy-header-divider="<?php echo $configuration->get('learner_header_divider', true) ? 'true' : 'false'; ?>"
             style="--vy-learner-logo-size:<?php echo (int) $logoSize; ?>px"
             data-vy-site-key="<?php echo esc_attr(home_url('/')); ?>"
             data-vy-loading-label="<?php echo esc_attr__('Loading page…', 'vidyalms'); ?>"
             data-vy-leave-confirm="<?php echo esc_attr__('You have unsaved changes. Leave this page?', 'vidyalms'); ?>">
            <?php if ($display === 'full') : ?>
            <header class="vy-learning-brandbar">
                <a class="vy-learning-brand" data-vy-page="<?php echo esc_attr($home); ?>" href="<?php echo esc_url($homeUrl); ?>">
                    <?php if ($configuration->get('learner_header_show_logo', true)) : ?>
                        <?php if ($logo !== '') : ?>
                            <img class="vy-learning-logo" src="<?php echo esc_url($logo); ?>" alt="" height="<?php echo (int) $logoSize; ?>" referrerpolicy="no-referrer">
                        <?php else : ?>
                            <span class="vy-learning-mark" aria-hidden="true">V</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <span><?php echo esc_html($name); ?><?php if ($configuration->get('learner_header_show_tagline', true)) : ?><small><?php echo esc_html($tagline); ?></small><?php endif; ?></span>
                </a>
            </header>
            <?php endif; ?>
            <?php if ($display !== 'hidden') : ?>
            <nav class="vy-learning-navigation" aria-label="<?php echo esc_attr__('Your learning', 'vidyalms'); ?>">
                <?php foreach ($links as $id => [$label, $url]) : ?>
                    <a data-vy-page="<?php echo esc_attr($id); ?>" href="<?php echo esc_url($url); ?>"<?php echo $id === $active ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
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
