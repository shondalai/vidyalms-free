<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * The signed-out gate on the learner pages. The credential form is the host's
 * own sign-in page; this layout only invites the learner to it. A template
 * overrides it at html/layouts/com_vidyalms/signin/gate.php.
 *
 * @var array<string, mixed> $displayData Built by SignInGate::context().
 */
$data   = \is_array($displayData ?? null) ? $displayData : [];
$text   = static fn (string $key): string => \is_string($data[$key] ?? null) ? $data[$key] : '';
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$steps  = \is_array($data['steps'] ?? null) ? $data['steps'] : [];
?>
<?php if ($text('heading') !== '' || $text('page_kicker') !== '') : ?>
    <header class="vidyalms-page-header">
        <?php if ($text('page_kicker') !== '') : ?>
            <p class="vy-page-kicker"><?php echo $escape($text('page_kicker')); ?></p>
        <?php endif; ?>
        <?php if ($text('heading') !== '') : ?>
            <h1 class="vidyalms-page-title"><?php echo $escape($text('heading')); ?></h1>
        <?php endif; ?>
    </header>
<?php endif; ?>
<section class="vy-signin" data-vy-signin="<?php echo $escape($text('page')); ?>" aria-labelledby="vy-signin-title">
    <div class="vy-signin-card">
        <div class="vy-signin-body">
            <p class="vy-page-kicker vy-signin-kicker"><?php echo $escape($text('kicker')); ?></p>
            <h2 class="vy-signin-title" id="vy-signin-title"><?php echo $escape($text('title')); ?></h2>
            <p class="vy-signin-lede"><?php echo $escape($text('lede')); ?> <span class="vy-signin-account"><?php echo $escape($text('account')); ?></span></p>
            <div class="vy-signin-actions" data-no-swup>
                <a class="vidyalms-button vidyalms-button-primary vy-signin-primary" data-vy-signin-action="sign-in" href="<?php echo $escape($text('sign_in_url')); ?>"><?php echo $escape(Text::_('COM_VIDYALMS_LOGIN')); ?></a>
                <?php if ($text('register_url') !== '') : ?>
                    <a class="vidyalms-button vy-signin-secondary" data-vy-signin-action="register" href="<?php echo $escape($text('register_url')); ?>"><?php echo $escape(Text::_('COM_VIDYALMS_SIGNIN_REGISTER')); ?></a>
                <?php endif; ?>
            </div>
            <ul class="vy-signin-help" data-no-swup>
                <li><a data-vy-signin-action="reset" href="<?php echo $escape($text('reset_url')); ?>"><?php echo $escape(Text::_('COM_VIDYALMS_SIGNIN_RESET')); ?></a></li>
                <?php if ($text('remind_url') !== '') : ?>
                    <li><a data-vy-signin-action="remind" href="<?php echo $escape($text('remind_url')); ?>"><?php echo $escape(Text::_('COM_VIDYALMS_SIGNIN_REMIND')); ?></a></li>
                <?php endif; ?>
            </ul>
            <p class="vy-signin-explore">
                <a class="vy-signin-link" data-vy-signin-action="explore" href="<?php echo $escape($text('catalogue_url')); ?>"><?php echo $escape(Text::_('COM_VIDYALMS_EXPLORE_COURSES')); ?><span class="vy-signin-arrow" aria-hidden="true">&#8599;</span></a>
            </p>
        </div>
        <div class="vy-signin-steps">
            <h3 class="vy-signin-steps-title" id="vy-signin-steps"><?php echo $escape($text('steps_title')); ?></h3>
            <ol class="vy-signin-step-list" aria-labelledby="vy-signin-steps">
                <?php foreach ($steps as $step) : ?>
                    <?php if (!\is_array($step)) { continue; } ?>
                    <li class="vy-signin-step">
                        <span class="vy-signin-step-name"><?php echo $escape((string) ($step['name'] ?? '')); ?></span>
                        <span class="vy-signin-step-detail"><?php echo $escape((string) ($step['detail'] ?? '')); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
</section>
