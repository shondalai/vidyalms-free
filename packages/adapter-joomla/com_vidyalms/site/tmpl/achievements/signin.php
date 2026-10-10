<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\Achievements\HtmlView $this */
\VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::begin('achievements');
?>
<div class="vidyalms vidyalms-signin-page">
    <?php echo \VidyaLMS\Component\VidyaLMS\Site\Service\SignInGate::render($this->signIn); ?>
</div>
<?php \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::end(); ?>
