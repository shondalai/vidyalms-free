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

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\Unsubscribe\HtmlView $this */

$heading = (string) $this->params->get('page_heading', $this->params->get('page_title', ''));

if ($heading === '') {
    $heading = Text::_('COM_VIDYALMS_UNSUBSCRIBE_TITLE');
}

$result   = $this->acted ? $this->outcome : $this->preview;
$missing  = $result === null || $result->routeMissing();
$limited  = $result !== null && $result->rateLimited();
$category = $result === null ? '' : $result->string('category_label', $result->string('category_key'));
$channels = $result === null ? [] : $result->strings('channels');
$keeps    = $result === null ? [] : $result->strings($this->acted ? 'still_receiving' : 'keeps_receiving');
$already  = $result !== null && $result->bool('already_unsubscribed');
$done     = $this->acted && $result !== null && $result->bool('unsubscribed');
?>
<div class="vidyalms vidyalms-unsubscribe">
    <?php if ((int) $this->params->get('show_page_heading', 1) === 1) : ?>
        <h1 class="vidyalms-page-title"><?php echo $this->escape($heading); ?></h1>
    <?php endif; ?>

    <?php if ($this->token === '') : ?>
        <p class="vidyalms-notice"><?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_NO_TOKEN'); ?></p>
    <?php elseif ($limited) : ?>
        <p class="vidyalms-notice"><?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_RATE_LIMITED'); ?></p>
    <?php elseif ($missing) : ?>
        <p class="vidyalms-notice"><?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_UNAVAILABLE'); ?></p>
    <?php elseif ($this->acted) : ?>
        <section class="vidyalms-unsubscribe-outcome" aria-labelledby="vidyalms-unsubscribe-heading">
            <h2 id="vidyalms-unsubscribe-heading">
                <?php echo $done
                    ? Text::_('COM_VIDYALMS_UNSUBSCRIBE_DONE')
                    : Text::_('COM_VIDYALMS_UNSUBSCRIBE_NOT_DONE'); ?>
            </h2>

            <?php if ($done) : ?>
                <p><?php echo Text::sprintf('COM_VIDYALMS_UNSUBSCRIBE_DONE_BODY', $this->escape($category)); ?></p>
            <?php else : ?>
                <p><?php echo $this->escape($result->detail()); ?></p>
            <?php endif; ?>

            <?php if ($keeps !== []) : ?>
                <p class="vidyalms-unsubscribe-keeps">
                    <?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_STILL_RECEIVING'); ?>
                </p>
                <ul class="vidyalms-list">
                    <?php foreach ($keeps as $keep) : ?>
                        <li><?php echo $this->escape($keep); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="vidyalms-note"><?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_TRANSACTIONAL_NOTE'); ?></p>
        </section>
    <?php else : ?>
        <section class="vidyalms-unsubscribe-form" aria-labelledby="vidyalms-unsubscribe-heading">
            <h2 id="vidyalms-unsubscribe-heading"><?php echo $this->escape($category); ?></h2>

            <?php if ($already) : ?>
                <p class="vidyalms-notice"><?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_ALREADY'); ?></p>
            <?php else : ?>
                <p><?php echo Text::sprintf('COM_VIDYALMS_UNSUBSCRIBE_CONFIRM', $this->escape($category)); ?></p>
            <?php endif; ?>

            <form action="<?php echo $this->formAction; ?>" method="post">
                <?php if (\count($channels) > 1) : ?>
                    <fieldset class="vidyalms-fieldset">
                        <legend><?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_CHANNELS'); ?></legend>
                        <?php foreach ($channels as $index => $channel) : ?>
                            <div class="vidyalms-field vidyalms-field-check">
                                <input
                                    type="checkbox"
                                    id="vidyalms-unsub-channel-<?php echo (int) $index; ?>"
                                    name="channels[]"
                                    value="<?php echo $this->escape($channel); ?>"
                                    checked>
                                <label for="vidyalms-unsub-channel-<?php echo (int) $index; ?>">
                                    <?php echo Text::_('COM_VIDYALMS_CHANNEL_' . strtoupper($channel)); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <div class="vidyalms-field vidyalms-field-actions">
                    <button class="vidyalms-button" type="submit">
                        <?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_SUBMIT'); ?>
                    </button>
                </div>
            </form>

            <?php if ($keeps !== []) : ?>
                <p class="vidyalms-unsubscribe-keeps">
                    <?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_KEEPS_RECEIVING'); ?>
                </p>
                <ul class="vidyalms-list">
                    <?php foreach ($keeps as $keep) : ?>
                        <li><?php echo $this->escape($keep); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="vidyalms-note"><?php echo Text::_('COM_VIDYALMS_UNSUBSCRIBE_TRANSACTIONAL_NOTE'); ?></p>
        </section>
    <?php endif; ?>
</div>
