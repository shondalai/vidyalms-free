<?php
declare(strict_types=1);

/** Learner preference groups. Native controls work without JavaScript. */
use Shondalai\VidyaLMS\WordPress\Content\CoreRouteResult;
use Shondalai\VidyaLMS\WordPress\Content\SignInGate;

defined('ABSPATH') || exit;

$vidyalms_context = isset($context) && \is_array($context) ? $context : [];

/** @var array<string, mixed> $context */
$vidyalms_centre = ($vidyalms_context['centre'] ?? null) instanceof CoreRouteResult ? $vidyalms_context['centre'] : null;
$vidyalms_saved = ($vidyalms_context['saved'] ?? false) === true;
$vidyalms_save_error = is_string($vidyalms_context['save_error'] ?? null) ? $vidyalms_context['save_error'] : '';
$vidyalms_submitted = [];
foreach ($vidyalms_save_error !== '' ? ($vidyalms_context['submitted_rows'] ?? []) : [] as $vidyalms_row) {
    $vidyalms_submitted[$vidyalms_row['category_key'] . '|' . $vidyalms_row['channel_key']] = $vidyalms_row['frequency'];
}
$vidyalms_action = is_string($vidyalms_context['form_action'] ?? null) ? $vidyalms_context['form_action'] : '';
$vidyalms_nonce_field = is_string($vidyalms_context['nonce_field'] ?? null) ? $vidyalms_context['nonce_field'] : '';
$vidyalms_id_prefix = wp_unique_id('vidyalms-preferences-');

$vidyalms_copy = [
    'intro' => __('Choose the updates that matter to you, at a pace that suits you.', 'vidyalms'),
    'kicker' => __('MAKE IT YOURS', 'vidyalms'),
    'guideTitle' => __('A little less noise.', 'vidyalms'),
    'guideText' => __('Set a delivery frequency for each type of update. Open a group to choose how you hear from us.', 'vidyalms'),
    'frequencyTitle' => __('Find your rhythm', 'vidyalms'),
    'immediate' => __('As they happen', 'vidyalms'),
    'immediateHint' => __('Individual updates, without waiting for a summary.', 'vidyalms'),
    'daily' => __('Daily summary', 'vidyalms'),
    'weekly' => __('Weekly summary', 'vidyalms'),
    'digestHint' => __('Fewer messages, collected into a digest when supported.', 'vidyalms'),
    'never' => __('Off', 'vidyalms'),
    'neverHint' => __('Pause optional updates for that channel.', 'vidyalms'),
    'essentialTitle' => __('Essential messages stay on', 'vidyalms'),
    'essentialHint' => __('Some messages are essential to your learning. They are not affected by these preferences.', 'vidyalms'),
    'channels' => __('Delivery channels', 'vidyalms'),
    'frequency' => __('Frequency', 'vidyalms'),
    'essential' => __('Always on', 'vidyalms'),
    'managed' => __('Managed by your site', 'vidyalms'),
    'managedHint' => __('This delivery setting is controlled by your site administrator.', 'vidyalms'),
    'editable' => __('Your choice', 'vidyalms'),
    'controlsHint' => __('Only frequencies supported by each channel are shown.', 'vidyalms'),
    'saved' => __('Your preferences were saved.', 'vidyalms'),
    'save' => __('Save preferences', 'vidyalms'),
    'saving' => __('Saving preferences…', 'vidyalms'),
    'reset' => __('Discard changes', 'vidyalms'),
    'clean' => __('Choose what works for you. Save when you’re ready.', 'vidyalms'),
    'dirty' => __('You have unsaved changes.', 'vidyalms'),
    'unavailable' => __('Notification preferences are not available right now. Please try again later.', 'vidyalms'),
    'login' => __('Sign in to manage your notification preferences.', 'vidyalms'),
    'empty' => __('There are no notification preferences to manage yet.', 'vidyalms'),
    'stored' => __('Your previous frequency is currently unavailable. Review this selection before saving.', 'vidyalms'),
    'categoryDefault' => __('Choose how you receive these updates.', 'vidyalms'),
    'course_progress' => __('Milestones, progress updates and learning reminders.', 'vidyalms'),
    'assessment' => __('Updates about your assessments, submissions and results.', 'vidyalms'),
    'credential' => __('Your certificates, credentials and achievements.', 'vidyalms'),
    'account' => __('Updates about your learning account and access.', 'vidyalms'),
    'announcements' => __('News and updates from your learning community.', 'vidyalms'),
    'marketing' => __('Optional recommendations, offers and news.', 'vidyalms'),
    'email' => __('Email', 'vidyalms'),
    'emailHint' => __('Updates delivered to your email inbox.', 'vidyalms'),
    'in_app' => __('In-app notifications', 'vidyalms'),
    'in_appHint' => __('Updates in your notification inbox.', 'vidyalms'),
    'sms' => __('Text messages', 'vidyalms'),
    'smsHint' => __('Updates delivered by SMS.', 'vidyalms'),
    'push' => __('Push notifications', 'vidyalms'),
    'pushHint' => __('Notifications on your devices.', 'vidyalms'),
    'webhook' => __('Connected apps', 'vidyalms'),
    'webhookHint' => __('Updates sent to connected services.', 'vidyalms'),
];

$vidyalms_channel_titles = array_intersect_key($vidyalms_copy, array_flip(['email', 'in_app', 'sms', 'push', 'webhook']));
$vidyalms_category_descriptions = array_intersect_key($vidyalms_copy, array_flip(['course_progress', 'assessment', 'credential', 'account', 'announcements', 'marketing']));
$vidyalms_channel_labels = [];
foreach ($vidyalms_centre === null ? [] : $vidyalms_centre->rows('channels') as $vidyalms_channel) {
    if (isset($vidyalms_channel['key']) && is_string($vidyalms_channel['key'])) {
        $vidyalms_channel_labels[$vidyalms_channel['key']] = $vidyalms_channel_titles[$vidyalms_channel['key']] ?? (string) ($vidyalms_channel['label'] ?? $vidyalms_channel['key']);
    }
}
$vidyalms_category_labels = [];
foreach ($vidyalms_centre === null ? [] : $vidyalms_centre->rows('categories') as $vidyalms_category) {
    if (isset($vidyalms_category['key']) && is_string($vidyalms_category['key'])) {
        $vidyalms_category_labels[$vidyalms_category['key']] = (string) ($vidyalms_category['label'] ?? $vidyalms_category['key']);
    }
}
$vidyalms_groups = [];
foreach ($vidyalms_centre === null ? [] : $vidyalms_centre->rows('rows') as $vidyalms_index => $vidyalms_row) {
    $vidyalms_category = $vidyalms_row['category_key'] ?? '';
    $vidyalms_channel = $vidyalms_row['channel_key'] ?? '';
    if (!is_string($vidyalms_category) || !is_string($vidyalms_channel) || $vidyalms_category === '' || $vidyalms_channel === '') {
        continue;
    }
    $vidyalms_groups[$vidyalms_category] ??= ['label' => $vidyalms_category_labels[$vidyalms_category] ?? $vidyalms_category, 'rows' => [], 'essential' => true, 'editable' => false];
    $vidyalms_row['index'] = $vidyalms_index;
    $vidyalms_groups[$vidyalms_category]['rows'][] = $vidyalms_row;
    $vidyalms_groups[$vidyalms_category]['essential'] = $vidyalms_groups[$vidyalms_category]['essential'] && ($vidyalms_row['bypasses_preference'] ?? false) === true;
    $vidyalms_groups[$vidyalms_category]['editable'] = $vidyalms_groups[$vidyalms_category]['editable'] || (($vidyalms_row['editable'] ?? true) === true && ($vidyalms_row['bypasses_preference'] ?? false) !== true);
}
// Put choices first; essential delivery remains visible in its own group.
uasort($vidyalms_groups, static function (array $vidyalms_a, array $vidyalms_b): int {
    if ($vidyalms_a['editable'] !== $vidyalms_b['editable']) {
        return $vidyalms_a['editable'] ? -1 : 1;
    }
    return (int) $vidyalms_a['essential'] <=> (int) $vidyalms_b['essential'];
});
$vidyalms_frequency_labels = array_intersect_key($vidyalms_copy, array_flip(['immediate', 'daily', 'weekly', 'never']));
$vidyalms_icons = [
    'course_progress' => 'M12 7c-3-3-7-3-10-2v15c3-1 7-1 10 2m0-15c3-3 7-3 10-2v15c-3-1-7-1-10 2V7',
    'assessment' => 'M9 11l2 2 4-4M9 3H5v18h14V3h-4M9 2h6v4H9z',
    'account' => 'M20 21v-2a7 7 0 0 0-14 0v2M17 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
    'credential' => 'M16 8a4 4 0 1 1-8 0 4 4 0 0 1 8 0M8 12l-2 9 6-3 6 3-2-9',
];
?>
<div class="vidyalms vidyalms-preferences">
    <?php if (isset($vidyalms_context['signin']) && \is_array($vidyalms_context['signin'])) : ?>
        <?php SignInGate::render($vidyalms_context['signin']); ?>
    <?php else : ?>
    <?php if ($vidyalms_saved) : ?>
        <p class="vidyalms-notice vidyalms-notice-success" role="status" data-vy-preferences-saved><?php echo esc_html($vidyalms_copy['saved']); ?></p>
    <?php endif; ?>
    <?php if ($vidyalms_save_error !== '') : ?>
        <p class="vidyalms-notice vidyalms-notice-error" role="alert"><?php echo esc_html($vidyalms_save_error); ?></p>
    <?php endif; ?>
    <?php if ($vidyalms_centre === null || $vidyalms_centre->routeMissing()) : ?>
        <p class="vidyalms-notice" role="status"><?php echo esc_html($vidyalms_copy['unavailable']); ?></p>
    <?php elseif ($vidyalms_centre->unauthorized()) : ?>
        <p class="vidyalms-notice" role="status"><?php echo esc_html($vidyalms_copy['login']); ?></p>
    <?php elseif (!$vidyalms_centre->ok()) : ?>
        <p class="vidyalms-notice vidyalms-notice-error" role="alert"><?php echo esc_html($vidyalms_copy['unavailable']); ?></p>
    <?php elseif ($vidyalms_groups === []) : ?>
        <p class="vidyalms-notice" role="status"><?php echo esc_html($vidyalms_copy['empty']); ?></p>
    <?php else : ?>
        <p class="vy-preferences-intro"><?php echo esc_html($vidyalms_copy['intro']); ?></p>
        <form class="vy-preferences-form" data-vy-preferences-form data-vy-draft-form action="<?php echo esc_url($vidyalms_action); ?>" method="post"
              data-vy-clean-label="<?php echo esc_attr($vidyalms_copy['clean']); ?>" data-vy-dirty-label="<?php echo esc_attr($vidyalms_copy['dirty']); ?>"
              data-vy-saving-label="<?php echo esc_attr($vidyalms_copy['saving']); ?>">
            <div class="vy-preferences-layout">
                <aside class="vy-preferences-guide" aria-label="<?php echo esc_attr($vidyalms_copy['frequencyTitle']); ?>">
                    <span class="vy-preferences-guide-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4M12 2V1"/></svg>
                    </span>
                    <h2><?php echo esc_html($vidyalms_copy['guideTitle']); ?></h2>
                    <p><?php echo esc_html($vidyalms_copy['guideText']); ?></p>
                    <dl>
                        <dt><?php echo esc_html($vidyalms_copy['immediate']); ?></dt>
                        <dd><?php echo esc_html($vidyalms_copy['immediateHint']); ?></dd>
                        <dt><?php echo esc_html($vidyalms_copy['daily'] . ' / ' . $vidyalms_copy['weekly']); ?></dt>
                        <dd><?php echo esc_html($vidyalms_copy['digestHint']); ?></dd>
                        <dt><?php echo esc_html($vidyalms_copy['never']); ?></dt>
                        <dd><?php echo esc_html($vidyalms_copy['neverHint']); ?></dd>
                    </dl>
                    <div class="vy-preferences-essential-note">
                        <strong><?php echo esc_html($vidyalms_copy['essentialTitle']); ?></strong>
                        <p><?php echo esc_html($vidyalms_copy['essentialHint']); ?></p>
                    </div>
                </aside>
                <div class="vy-preferences-groups">
                    <?php $vidyalms_first = true; foreach ($vidyalms_groups as $vidyalms_category => $vidyalms_group) :
                        $vidyalms_category_title = $vidyalms_group['label'];
                        $vidyalms_group_id = $vidyalms_id_prefix . '-group-' . $vidyalms_category;
                        $vidyalms_icon = $vidyalms_icons[$vidyalms_category] ?? 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4';
                    ?>
                    <details class="vy-preference-group" <?php echo $vidyalms_first ? 'open' : ''; ?>>
                        <summary>
                            <span class="vy-preference-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo esc_attr($vidyalms_icon); ?>"/></svg></span>
                            <span class="vy-preference-summary">
                                <strong id="<?php echo esc_attr($vidyalms_group_id); ?>"><?php echo esc_html($vidyalms_category_title); ?></strong>
                                <span><?php echo esc_html($vidyalms_category_descriptions[$vidyalms_category] ?? $vidyalms_copy['categoryDefault']); ?></span>
                            </span>
                            <span class="vy-preference-badge"><?php echo esc_html($vidyalms_copy[$vidyalms_group['essential'] ? 'essential' : ($vidyalms_group['editable'] ? 'editable' : 'managed')]); ?></span>
                            <svg class="vy-preference-chevron" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <div class="vy-preference-options" role="group" aria-labelledby="<?php echo esc_attr($vidyalms_group_id); ?>">
                            <?php if ($vidyalms_group['essential']) : ?>
                                <p class="vy-preference-note"><?php echo esc_html($vidyalms_copy['essentialHint']); ?></p>
                            <?php endif; ?>
                            <div class="vy-preference-columns" aria-hidden="true"><span><?php echo esc_html($vidyalms_copy['channels']); ?></span><span><?php echo esc_html($vidyalms_copy['frequency']); ?></span></div>
                            <?php foreach ($vidyalms_group['rows'] as $vidyalms_row) :
                                $vidyalms_channel = $vidyalms_row['channel_key'];
                                $vidyalms_channel_title = $vidyalms_channel_labels[$vidyalms_channel] ?? $vidyalms_channel;
                                $vidyalms_bypasses = ($vidyalms_row['bypasses_preference'] ?? false) === true;
                                $vidyalms_editable = ($vidyalms_row['editable'] ?? true) === true && !$vidyalms_bypasses;
                                $vidyalms_frequency = is_string($vidyalms_row['frequency'] ?? null) ? $vidyalms_row['frequency'] : 'immediate';
                                $vidyalms_available = array_values(array_intersect((array) ($vidyalms_row['available_frequencies'] ?? array_keys($vidyalms_frequency_labels)), array_keys($vidyalms_frequency_labels)));
                                $vidyalms_chosen = $vidyalms_submitted[$vidyalms_category . '|' . $vidyalms_channel] ?? $vidyalms_frequency;
                                $vidyalms_chosen = in_array($vidyalms_chosen, $vidyalms_available, true) ? $vidyalms_chosen : $vidyalms_frequency;
                                $vidyalms_control_id = $vidyalms_id_prefix . '-pref-' . $vidyalms_row['index'];
                                $vidyalms_control_name = 'preference[' . $vidyalms_category . '|' . $vidyalms_channel . ']';
                                $vidyalms_hint_id = $vidyalms_control_id . '-hint';
                                $vidyalms_has_stored = is_string($vidyalms_row['stored_frequency'] ?? null);
                            ?>
                            <div class="vy-preference-row">
                                <div class="vy-preference-channel">
                                    <label <?php echo $vidyalms_editable ? 'for="' . esc_attr($vidyalms_control_id) . '"' : ''; ?>><?php echo esc_html($vidyalms_channel_title); ?></label>
                                    <?php if (isset($vidyalms_channel_titles[$vidyalms_channel], $vidyalms_copy[$vidyalms_channel . 'Hint'])) : ?><span><?php echo esc_html($vidyalms_copy[$vidyalms_channel . 'Hint']); ?></span><?php endif; ?>
                                </div>
                                <div class="vy-preference-control">
                                    <?php if ($vidyalms_editable && $vidyalms_available !== []) : ?>
                                        <select class="vidyalms-input" id="<?php echo esc_attr($vidyalms_control_id); ?>" name="<?php echo esc_attr($vidyalms_control_name); ?>" data-vy-original-value="<?php echo esc_attr($vidyalms_frequency); ?>"
                                            aria-label="<?php echo esc_attr($vidyalms_category_title . ' · ' . $vidyalms_channel_title); ?>"<?php echo $vidyalms_has_stored ? ' aria-describedby="' . esc_attr($vidyalms_hint_id) . '"' : ''; ?>>
                                            <?php foreach ($vidyalms_available as $vidyalms_option) : ?>
                                                <option value="<?php echo esc_attr($vidyalms_option); ?>" <?php echo $vidyalms_option === $vidyalms_chosen ? 'selected' : ''; ?>><?php echo esc_html($vidyalms_frequency_labels[$vidyalms_option]); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($vidyalms_has_stored) : ?><span class="vy-preference-note" id="<?php echo esc_attr($vidyalms_hint_id); ?>"><?php echo esc_html($vidyalms_copy['stored']); ?></span><?php endif; ?>
                                    <?php else : ?>
                                        <span class="vy-preference-locked"><?php echo esc_html($vidyalms_copy[$vidyalms_bypasses ? 'essential' : 'managed']); ?></span>
                                        <?php if (!$vidyalms_bypasses) : ?><span class="vy-preference-note"><?php echo esc_html($vidyalms_copy['managedHint']); ?></span><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php $vidyalms_first = false; endforeach; ?>
                    <p class="vy-preferences-footnote"><?php echo esc_html($vidyalms_copy['controlsHint']); ?></p>
                </div>
            </div>
            <div class="vy-preferences-actions">
                <p role="status" aria-live="polite" data-vy-preferences-status><?php echo esc_html($vidyalms_copy['clean']); ?></p>
                <div>
                    <button type="reset" class="vidyalms-button" data-vy-preferences-reset hidden><?php echo esc_html($vidyalms_copy['reset']); ?></button>
                    <button type="submit" class="vidyalms-button vidyalms-button-primary" data-vy-preferences-save><?php echo esc_html($vidyalms_copy['save']); ?></button>
                </div>
            </div>
            <?php echo wp_kses($vidyalms_nonce_field, [
                'input' => ['type' => true, 'id' => true, 'name' => true, 'value' => true],
            ]); ?>
        </form>
    <?php endif; ?>
    <?php endif; ?>
</div>
