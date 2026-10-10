<?php

declare(strict_types=1);

/**
 * VidyaLMS: the unsubscribe confirmation form and its outcome.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 *
 * @var array<string, mixed> $context
 */

use Shondalai\VidyaLMS\WordPress\Content\CoreRouteResult;

defined( 'ABSPATH' ) || exit;

$vidyalms_context = isset($context) && \is_array($context) ? $context : [];

$vidyalms_token  = isset($vidyalms_context['token']) && \is_string($vidyalms_context['token']) ? $vidyalms_context['token'] : '';
$vidyalms_acted  = isset($vidyalms_context['acted']) && $vidyalms_context['acted'] === true;
$vidyalms_result = $vidyalms_context['result'] ?? null;
$vidyalms_action = isset($vidyalms_context['form_action']) && \is_string($vidyalms_context['form_action']) ? $vidyalms_context['form_action'] : '';

$vidyalms_result   = $vidyalms_result instanceof CoreRouteResult ? $vidyalms_result : null;
$vidyalms_missing  = $vidyalms_result === null || $vidyalms_result->routeMissing();
$vidyalms_limited  = $vidyalms_result !== null && $vidyalms_result->rateLimited();
$vidyalms_category = $vidyalms_result === null ? '' : $vidyalms_result->string('category_label', $vidyalms_result->string('category_key'));
$vidyalms_channels = $vidyalms_result === null ? [] : $vidyalms_result->strings('channels');
$vidyalms_keeps    = $vidyalms_result === null ? [] : $vidyalms_result->strings($vidyalms_acted ? 'still_receiving' : 'keeps_receiving');
$vidyalms_already  = $vidyalms_result !== null && $vidyalms_result->bool('already_unsubscribed');
$vidyalms_done     = $vidyalms_acted && $vidyalms_result !== null && $vidyalms_result->bool('unsubscribed');

$vidyalms_channel_labels = [
    'email' => __('Email', 'vidyalms'),
    'in_app' => __('In the site', 'vidyalms'),
    'sms' => __('Text message', 'vidyalms'),
    'push' => __('Push notification', 'vidyalms'),
    'webhook' => __('Webhook', 'vidyalms'),
];

$vidyalms_transactional_note = __(
    'Messages about your account, your enrolments and your certificates are sent whatever you choose here. They are not marketing, and the site may be required to send them.',
    'vidyalms'
);
?>
<div class="vidyalms-unsubscribe">
    <?php if ($vidyalms_token === '') : ?>
        <p class="vidyalms-notice">
            <?php esc_html_e('This page needs the link from the email you received.', 'vidyalms'); ?>
        </p>
    <?php elseif ($vidyalms_limited) : ?>
        <p class="vidyalms-notice">
            <?php esc_html_e('Too many requests from this address. Wait a minute and try again.', 'vidyalms'); ?>
        </p>
    <?php elseif ($vidyalms_missing) : ?>
        <p class="vidyalms-notice">
            <?php esc_html_e(
                'This unsubscribe link is not valid, or the site is no longer sending this kind of message.',
                'vidyalms'
            ); ?>
        </p>
    <?php elseif ($vidyalms_acted) : ?>
        <section class="vidyalms-unsubscribe-outcome" aria-labelledby="vidyalms-unsubscribe-heading">
            <h2 id="vidyalms-unsubscribe-heading">
                <?php echo $vidyalms_done
                    ? esc_html__('Unsubscribed', 'vidyalms')
                    : esc_html__('Nothing was changed', 'vidyalms'); ?>
            </h2>

            <?php if ($vidyalms_done) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: %s: the kind of message, for example "Course progress". */
                        esc_html__('You will no longer receive %s messages.', 'vidyalms'),
                        esc_html($vidyalms_category)
                    );
                    ?>
                </p>
            <?php else : ?>
                <p><?php echo esc_html($vidyalms_result->detail()); ?></p>
            <?php endif; ?>

            <?php if ($vidyalms_keeps !== []) : ?>
                <p class="vidyalms-unsubscribe-keeps"><?php esc_html_e('You still receive:', 'vidyalms'); ?></p>
                <ul class="vidyalms-list">
                    <?php foreach ($vidyalms_keeps as $vidyalms_keep) : ?>
                        <li><?php echo esc_html($vidyalms_keep); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="vidyalms-note"><?php echo esc_html($vidyalms_transactional_note); ?></p>
        </section>
    <?php else : ?>
        <section class="vidyalms-unsubscribe-form" aria-labelledby="vidyalms-unsubscribe-heading">
            <h2 id="vidyalms-unsubscribe-heading"><?php echo esc_html($vidyalms_category); ?></h2>

            <?php if ($vidyalms_already) : ?>
                <p class="vidyalms-notice">
                    <?php esc_html_e(
                        'You are already unsubscribed from these messages. Confirming again changes nothing.',
                        'vidyalms'
                    ); ?>
                </p>
            <?php else : ?>
                <p>
                    <?php
                    printf(
                        /* translators: %s: the kind of message, for example "Course progress". */
                        esc_html__('Stop sending you %s messages?', 'vidyalms'),
                        esc_html($vidyalms_category)
                    );
                    ?>
                </p>
            <?php endif; ?>

            <form action="<?php echo esc_url($vidyalms_action); ?>" method="post">
                <?php if (\count($vidyalms_channels) > 1) : ?>
                    <fieldset class="vidyalms-fieldset">
                        <legend><?php esc_html_e('Stop these ways of reaching you', 'vidyalms'); ?></legend>
                        <?php foreach ($vidyalms_channels as $vidyalms_index => $vidyalms_channel) : ?>
                            <div class="vidyalms-field vidyalms-field-check">
                                <input
                                    type="checkbox"
                                    id="vidyalms-unsub-channel-<?php echo (int) $vidyalms_index; ?>"
                                    name="channels[]"
                                    value="<?php echo esc_attr($vidyalms_channel); ?>"
                                    checked>
                                <label for="vidyalms-unsub-channel-<?php echo (int) $vidyalms_index; ?>">
                                    <?php echo esc_html($vidyalms_channel_labels[$vidyalms_channel] ?? $vidyalms_channel); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <div class="vidyalms-field vidyalms-field-actions">
                    <button class="vidyalms-button" type="submit">
                        <?php esc_html_e('Unsubscribe', 'vidyalms'); ?>
                    </button>
                </div>
            </form>

            <?php if ($vidyalms_keeps !== []) : ?>
                <p class="vidyalms-unsubscribe-keeps"><?php esc_html_e('This does not stop:', 'vidyalms'); ?></p>
                <ul class="vidyalms-list">
                    <?php foreach ($vidyalms_keeps as $vidyalms_keep) : ?>
                        <li><?php echo esc_html($vidyalms_keep); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="vidyalms-note"><?php echo esc_html($vidyalms_transactional_note); ?></p>
        </section>
    <?php endif; ?>
</div>
