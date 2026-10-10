<?php
declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\Preferences\HtmlView $this */
$centre = $this->centre;
$saved = $this->saved;
$saveError = $this->saveError;
$submitted = [];
foreach ($saveError !== '' ? $this->submittedRows : [] as $row) {
    $submitted[$row['category_key'] . '|' . $row['channel_key']] = $row['frequency'];
}
$action = html_entity_decode($this->formAction, ENT_QUOTES, 'UTF-8');
$escape = fn (string $value): string => $this->escape($value);
$nonceField = '<input type="hidden" name="' . $escape($this->csrfToken) . '" value="1">';
$idPrefix = 'vidyalms-preferences';
$copy = [
    'intro' => Text::_('COM_VIDYALMS_PREF_UX_INTRO'),
    'kicker' => Text::_('COM_VIDYALMS_PREF_UX_KICKER'),
    'guideTitle' => Text::_('COM_VIDYALMS_PREF_UX_GUIDETITLE'),
    'guideText' => Text::_('COM_VIDYALMS_PREF_UX_GUIDETEXT'),
    'frequencyTitle' => Text::_('COM_VIDYALMS_PREF_UX_FREQUENCYTITLE'),
    'immediate' => Text::_('COM_VIDYALMS_PREF_UX_IMMEDIATE'),
    'immediateHint' => Text::_('COM_VIDYALMS_PREF_UX_IMMEDIATEHINT'),
    'daily' => Text::_('COM_VIDYALMS_PREF_UX_DAILY'),
    'weekly' => Text::_('COM_VIDYALMS_PREF_UX_WEEKLY'),
    'digestHint' => Text::_('COM_VIDYALMS_PREF_UX_DIGESTHINT'),
    'never' => Text::_('COM_VIDYALMS_PREF_UX_NEVER'),
    'neverHint' => Text::_('COM_VIDYALMS_PREF_UX_NEVERHINT'),
    'essentialTitle' => Text::_('COM_VIDYALMS_PREF_UX_ESSENTIALTITLE'),
    'essentialHint' => Text::_('COM_VIDYALMS_PREF_UX_ESSENTIALHINT'),
    'channels' => Text::_('COM_VIDYALMS_PREF_UX_CHANNELS'),
    'frequency' => Text::_('COM_VIDYALMS_PREF_UX_FREQUENCY'),
    'essential' => Text::_('COM_VIDYALMS_PREF_UX_ESSENTIAL'),
    'managed' => Text::_('COM_VIDYALMS_PREF_UX_MANAGED'),
    'managedHint' => Text::_('COM_VIDYALMS_PREF_UX_MANAGEDHINT'),
    'editable' => Text::_('COM_VIDYALMS_PREF_UX_EDITABLE'),
    'controlsHint' => Text::_('COM_VIDYALMS_PREF_UX_CONTROLSHINT'),
    'saved' => Text::_('COM_VIDYALMS_PREF_UX_SAVED'),
    'save' => Text::_('COM_VIDYALMS_PREF_UX_SAVE'),
    'saving' => Text::_('COM_VIDYALMS_PREF_UX_SAVING'),
    'reset' => Text::_('COM_VIDYALMS_PREF_UX_RESET'),
    'clean' => Text::_('COM_VIDYALMS_PREF_UX_CLEAN'),
    'dirty' => Text::_('COM_VIDYALMS_PREF_UX_DIRTY'),
    'unavailable' => Text::_('COM_VIDYALMS_PREF_UX_UNAVAILABLE'),
    'login' => Text::_('COM_VIDYALMS_PREF_UX_LOGIN'),
    'empty' => Text::_('COM_VIDYALMS_PREF_UX_EMPTY'),
    'stored' => Text::_('COM_VIDYALMS_PREF_UX_STORED'),
    'categoryDefault' => Text::_('COM_VIDYALMS_PREF_UX_CATEGORYDEFAULT'),
    'course_progress' => Text::_('COM_VIDYALMS_PREF_UX_COURSE_PROGRESS'),
    'assessment' => Text::_('COM_VIDYALMS_PREF_UX_ASSESSMENT'),
    'credential' => Text::_('COM_VIDYALMS_PREF_UX_CREDENTIAL'),
    'account' => Text::_('COM_VIDYALMS_PREF_UX_ACCOUNT'),
    'announcements' => Text::_('COM_VIDYALMS_PREF_UX_ANNOUNCEMENTS'),
    'marketing' => Text::_('COM_VIDYALMS_PREF_UX_MARKETING'),
    'email' => Text::_('COM_VIDYALMS_PREF_UX_EMAIL'),
    'emailHint' => Text::_('COM_VIDYALMS_PREF_UX_EMAILHINT'),
    'in_app' => Text::_('COM_VIDYALMS_PREF_UX_IN_APP'),
    'in_appHint' => Text::_('COM_VIDYALMS_PREF_UX_IN_APPHINT'),
    'sms' => Text::_('COM_VIDYALMS_PREF_UX_SMS'),
    'smsHint' => Text::_('COM_VIDYALMS_PREF_UX_SMSHINT'),
    'push' => Text::_('COM_VIDYALMS_PREF_UX_PUSH'),
    'pushHint' => Text::_('COM_VIDYALMS_PREF_UX_PUSHHINT'),
    'webhook' => Text::_('COM_VIDYALMS_PREF_UX_WEBHOOK'),
    'webhookHint' => Text::_('COM_VIDYALMS_PREF_UX_WEBHOOKHINT'),
];
$heading = (string) $this->params->get('page_heading', $this->params->get('page_title', '')) ?: Text::_('COM_VIDYALMS_PREFERENCES_TITLE');
\VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::begin('preferences');
?>
<div class="vidyalms vidyalms-page-preferences">
    <header class="vidyalms-page-header">
        <p class="vy-page-kicker"><?php echo $escape($copy['kicker']); ?></p>
        <?php if ((int) $this->params->get('show_page_heading', 1) === 1) : ?>
            <h1 class="vidyalms-page-title"><?php echo $escape($heading); ?></h1>
        <?php endif; ?>
    </header>
<?php

$channelTitles = array_intersect_key($copy, array_flip(['email', 'in_app', 'sms', 'push', 'webhook']));
$categoryDescriptions = array_intersect_key($copy, array_flip(['course_progress', 'assessment', 'credential', 'account', 'announcements', 'marketing']));
$channelLabels = [];
foreach ($centre === null ? [] : $centre->rows('channels') as $channel) {
    if (isset($channel['key']) && is_string($channel['key'])) {
        $channelLabels[$channel['key']] = $channelTitles[$channel['key']] ?? (string) ($channel['label'] ?? $channel['key']);
    }
}
$categoryLabels = [];
foreach ($centre === null ? [] : $centre->rows('categories') as $category) {
    if (isset($category['key']) && is_string($category['key'])) {
        $categoryLabels[$category['key']] = (string) ($category['label'] ?? $category['key']);
    }
}
$groups = [];
foreach ($centre === null ? [] : $centre->rows('rows') as $index => $row) {
    $category = $row['category_key'] ?? '';
    $channel = $row['channel_key'] ?? '';
    if (!is_string($category) || !is_string($channel) || $category === '' || $channel === '') {
        continue;
    }
    $groups[$category] ??= ['label' => $categoryLabels[$category] ?? $category, 'rows' => [], 'essential' => true, 'editable' => false];
    $row['index'] = $index;
    $groups[$category]['rows'][] = $row;
    $groups[$category]['essential'] = $groups[$category]['essential'] && ($row['bypasses_preference'] ?? false) === true;
    $groups[$category]['editable'] = $groups[$category]['editable'] || (($row['editable'] ?? true) === true && ($row['bypasses_preference'] ?? false) !== true);
}
// Put choices first; essential delivery remains visible in its own group.
uasort($groups, static function (array $a, array $b): int {
    if ($a['editable'] !== $b['editable']) {
        return $a['editable'] ? -1 : 1;
    }
    return (int) $a['essential'] <=> (int) $b['essential'];
});
$frequencyLabels = array_intersect_key($copy, array_flip(['immediate', 'daily', 'weekly', 'never']));
$icons = [
    'course_progress' => 'M12 7c-3-3-7-3-10-2v15c3-1 7-1 10 2m0-15c3-3 7-3 10-2v15c-3-1-7-1-10 2V7',
    'assessment' => 'M9 11l2 2 4-4M9 3H5v18h14V3h-4M9 2h6v4H9z',
    'account' => 'M20 21v-2a7 7 0 0 0-14 0v2M17 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
    'credential' => 'M16 8a4 4 0 1 1-8 0 4 4 0 0 1 8 0M8 12l-2 9 6-3 6 3-2-9',
];
?>
<div class="vidyalms vidyalms-preferences">
    <?php if ($saved) : ?>
        <p class="vidyalms-notice vidyalms-notice-success" role="status" data-vy-preferences-saved><?php echo $escape($copy['saved']); ?></p>
    <?php endif; ?>
    <?php if ($saveError !== '') : ?>
        <p class="vidyalms-notice vidyalms-notice-error" role="alert"><?php echo $escape($saveError); ?></p>
    <?php endif; ?>
    <?php if ($centre === null || $centre->routeMissing()) : ?>
        <p class="vidyalms-notice" role="status"><?php echo $escape($copy['unavailable']); ?></p>
    <?php elseif ($centre->unauthorized()) : ?>
        <p class="vidyalms-notice" role="status"><?php echo $escape($copy['login']); ?></p>
    <?php elseif (!$centre->ok()) : ?>
        <p class="vidyalms-notice vidyalms-notice-error" role="alert"><?php echo $escape($copy['unavailable']); ?></p>
    <?php elseif ($groups === []) : ?>
        <p class="vidyalms-notice" role="status"><?php echo $escape($copy['empty']); ?></p>
    <?php else : ?>
        <p class="vy-preferences-intro"><?php echo $escape($copy['intro']); ?></p>
        <form class="vy-preferences-form" data-vy-preferences-form data-vy-draft-form action="<?php echo $escape($action); ?>" method="post"
              data-vy-clean-label="<?php echo $escape($copy['clean']); ?>" data-vy-dirty-label="<?php echo $escape($copy['dirty']); ?>"
              data-vy-saving-label="<?php echo $escape($copy['saving']); ?>">
            <div class="vy-preferences-layout">
                <aside class="vy-preferences-guide" aria-label="<?php echo $escape($copy['frequencyTitle']); ?>">
                    <span class="vy-preferences-guide-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4M12 2V1"/></svg>
                    </span>
                    <h2><?php echo $escape($copy['guideTitle']); ?></h2>
                    <p><?php echo $escape($copy['guideText']); ?></p>
                    <dl>
                        <dt><?php echo $escape($copy['immediate']); ?></dt>
                        <dd><?php echo $escape($copy['immediateHint']); ?></dd>
                        <dt><?php echo $escape($copy['daily'] . ' / ' . $copy['weekly']); ?></dt>
                        <dd><?php echo $escape($copy['digestHint']); ?></dd>
                        <dt><?php echo $escape($copy['never']); ?></dt>
                        <dd><?php echo $escape($copy['neverHint']); ?></dd>
                    </dl>
                    <div class="vy-preferences-essential-note">
                        <strong><?php echo $escape($copy['essentialTitle']); ?></strong>
                        <p><?php echo $escape($copy['essentialHint']); ?></p>
                    </div>
                </aside>
                <div class="vy-preferences-groups">
                    <?php $first = true; foreach ($groups as $category => $group) :
                        $categoryTitle = $group['label'];
                        $groupId = $idPrefix . '-group-' . $category;
                        $icon = $icons[$category] ?? 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4';
                    ?>
                    <details class="vy-preference-group" <?php echo $first ? 'open' : ''; ?>>
                        <summary>
                            <span class="vy-preference-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $escape($icon); ?>"/></svg></span>
                            <span class="vy-preference-summary">
                                <strong id="<?php echo $escape($groupId); ?>"><?php echo $escape($categoryTitle); ?></strong>
                                <span><?php echo $escape($categoryDescriptions[$category] ?? $copy['categoryDefault']); ?></span>
                            </span>
                            <span class="vy-preference-badge"><?php echo $escape($copy[$group['essential'] ? 'essential' : ($group['editable'] ? 'editable' : 'managed')]); ?></span>
                            <svg class="vy-preference-chevron" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <div class="vy-preference-options" role="group" aria-labelledby="<?php echo $escape($groupId); ?>">
                            <?php if ($group['essential']) : ?>
                                <p class="vy-preference-note"><?php echo $escape($copy['essentialHint']); ?></p>
                            <?php endif; ?>
                            <div class="vy-preference-columns" aria-hidden="true"><span><?php echo $escape($copy['channels']); ?></span><span><?php echo $escape($copy['frequency']); ?></span></div>
                            <?php foreach ($group['rows'] as $row) :
                                $channel = $row['channel_key'];
                                $channelTitle = $channelLabels[$channel] ?? $channel;
                                $bypasses = ($row['bypasses_preference'] ?? false) === true;
                                $editable = ($row['editable'] ?? true) === true && !$bypasses;
                                $frequency = is_string($row['frequency'] ?? null) ? $row['frequency'] : 'immediate';
                                $available = array_values(array_intersect((array) ($row['available_frequencies'] ?? array_keys($frequencyLabels)), array_keys($frequencyLabels)));
                                $chosen = $submitted[$category . '|' . $channel] ?? $frequency;
                                $chosen = in_array($chosen, $available, true) ? $chosen : $frequency;
                                $controlId = $idPrefix . '-pref-' . $row['index'];
                                $controlName = 'preference[' . $category . '|' . $channel . ']';
                                $hintId = $controlId . '-hint';
                                $hasStored = is_string($row['stored_frequency'] ?? null);
                            ?>
                            <div class="vy-preference-row">
                                <div class="vy-preference-channel">
                                    <label <?php echo $editable ? 'for="' . $escape($controlId) . '"' : ''; ?>><?php echo $escape($channelTitle); ?></label>
                                    <?php if (isset($channelTitles[$channel], $copy[$channel . 'Hint'])) : ?><span><?php echo $escape($copy[$channel . 'Hint']); ?></span><?php endif; ?>
                                </div>
                                <div class="vy-preference-control">
                                    <?php if ($editable && $available !== []) : ?>
                                        <select class="vidyalms-input" id="<?php echo $escape($controlId); ?>" name="<?php echo $escape($controlName); ?>" data-vy-original-value="<?php echo $escape($frequency); ?>"
                                            aria-label="<?php echo $escape($categoryTitle . ' · ' . $channelTitle); ?>"<?php echo $hasStored ? ' aria-describedby="' . $escape($hintId) . '"' : ''; ?>>
                                            <?php foreach ($available as $option) : ?>
                                                <option value="<?php echo $escape($option); ?>" <?php echo $option === $chosen ? 'selected' : ''; ?>><?php echo $escape($frequencyLabels[$option]); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($hasStored) : ?><span class="vy-preference-note" id="<?php echo $escape($hintId); ?>"><?php echo $escape($copy['stored']); ?></span><?php endif; ?>
                                    <?php else : ?>
                                        <span class="vy-preference-locked"><?php echo $escape($copy[$bypasses ? 'essential' : 'managed']); ?></span>
                                        <?php if (!$bypasses) : ?><span class="vy-preference-note"><?php echo $escape($copy['managedHint']); ?></span><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php $first = false; endforeach; ?>
                    <p class="vy-preferences-footnote"><?php echo $escape($copy['controlsHint']); ?></p>
                </div>
            </div>
            <div class="vy-preferences-actions">
                <p role="status" aria-live="polite" data-vy-preferences-status><?php echo $escape($copy['clean']); ?></p>
                <div>
                    <button type="reset" class="vidyalms-button" data-vy-preferences-reset hidden><?php echo $escape($copy['reset']); ?></button>
                    <button type="submit" class="vidyalms-button vidyalms-button-primary" data-vy-preferences-save><?php echo $escape($copy['save']); ?></button>
                </div>
            </div>
            <?php echo $nonceField; // The adapter creates the escaped CSRF field. ?>
        </form>
    <?php endif; ?>
</div>

</div>
<?php \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::end(); ?>
