<?php

declare(strict_types=1);

/**
 * VidyaLMS: the signed-out gate on the learner pages.
 *
 * The credential form is the host's own sign-in page; this part only invites
 * the learner to it. A theme may replace it with vidyalms/part-signin.php.
 *
 * @var array<string, mixed> $context Built by SignInGate::context().
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

defined( 'ABSPATH' ) || exit;

$vidyalms_context = isset($context) && \is_array($context) ? $context : [];

$vidyalms_text       = static fn (string $vidyalms_key): string => \is_string($vidyalms_context[$vidyalms_key] ?? null) ? $vidyalms_context[$vidyalms_key] : '';
$vidyalms_page_kicker = $vidyalms_text('page_kicker');
$vidyalms_heading    = $vidyalms_text('heading');
$vidyalms_register   = $vidyalms_text('register_url');
$vidyalms_reset      = $vidyalms_text('reset_url');
$vidyalms_steps      = \is_array($vidyalms_context['steps'] ?? null) ? $vidyalms_context['steps'] : [];
$vidyalms_title_id    = wp_unique_id('vy-signin-title-');
$vidyalms_steps_id    = wp_unique_id('vy-signin-steps-');
?>
<?php if ($vidyalms_heading !== '' || $vidyalms_page_kicker !== '') : ?>
    <header class="vidyalms-page-header">
        <?php if ($vidyalms_page_kicker !== '') : ?>
            <p class="vy-page-kicker"><?php echo esc_html($vidyalms_page_kicker); ?></p>
        <?php endif; ?>
        <?php if ($vidyalms_heading !== '') : ?>
            <h1 class="vidyalms-page-title"><?php echo esc_html($vidyalms_heading); ?></h1>
        <?php endif; ?>
    </header>
<?php endif; ?>
<section class="vy-signin" data-vy-signin="<?php echo esc_attr($vidyalms_text('page')); ?>" aria-labelledby="<?php echo esc_attr($vidyalms_title_id); ?>">
    <div class="vy-signin-card">
        <div class="vy-signin-body">
            <p class="vy-page-kicker vy-signin-kicker"><?php echo esc_html($vidyalms_text('kicker')); ?></p>
            <h2 class="vy-signin-title" id="<?php echo esc_attr($vidyalms_title_id); ?>"><?php echo esc_html($vidyalms_text('title')); ?></h2>
            <p class="vy-signin-lede"><?php echo esc_html($vidyalms_text('lede')); ?> <span class="vy-signin-account"><?php echo esc_html($vidyalms_text('account')); ?></span></p>
            <div class="vy-signin-actions" data-no-swup>
                <a class="vidyalms-button vidyalms-button-primary vy-signin-primary" data-vy-signin-action="sign-in" href="<?php echo esc_url($vidyalms_text('sign_in_url')); ?>"><?php esc_html_e('Sign in', 'vidyalms'); ?></a>
                <?php if ($vidyalms_register !== '') : ?>
                    <a class="vidyalms-button vy-signin-secondary" data-vy-signin-action="register" href="<?php echo esc_url($vidyalms_register); ?>"><?php esc_html_e('Create an account', 'vidyalms'); ?></a>
                <?php endif; ?>
            </div>
            <?php if ($vidyalms_reset !== '') : ?>
                <ul class="vy-signin-help" data-no-swup>
                    <li><a data-vy-signin-action="reset" href="<?php echo esc_url($vidyalms_reset); ?>"><?php esc_html_e('Forgot your password?', 'vidyalms'); ?></a></li>
                </ul>
            <?php endif; ?>
            <p class="vy-signin-explore">
                <a class="vy-signin-link" data-vy-signin-action="explore" href="<?php echo esc_url($vidyalms_text('catalogue_url')); ?>"><?php esc_html_e('Explore courses', 'vidyalms'); ?><span class="vy-signin-arrow" aria-hidden="true">&#8599;</span></a>
            </p>
        </div>
        <div class="vy-signin-steps">
            <h3 class="vy-signin-steps-title" id="<?php echo esc_attr($vidyalms_steps_id); ?>"><?php echo esc_html($vidyalms_text('steps_title')); ?></h3>
            <ol class="vy-signin-step-list" aria-labelledby="<?php echo esc_attr($vidyalms_steps_id); ?>">
                <?php foreach ($vidyalms_steps as $vidyalms_step) : ?>
                    <?php if (!\is_array($vidyalms_step)) { continue; } ?>
                    <li class="vy-signin-step">
                        <span class="vy-signin-step-name"><?php echo esc_html((string) ($vidyalms_step['name'] ?? '')); ?></span>
                        <span class="vy-signin-step-detail"><?php echo esc_html((string) ($vidyalms_step['detail'] ?? '')); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
</section>
