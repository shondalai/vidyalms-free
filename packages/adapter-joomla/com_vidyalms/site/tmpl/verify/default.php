<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\Verify\HtmlView $this */

$heading = (string) $this->params->get('page_heading', $this->params->get('page_title', ''));

if ($heading === '') {
    $heading = Text::_('COM_VIDYALMS_VERIFY_TITLE');
}

$data      = $this->verification;
$answered  = $this->result !== null && $this->result->ok();
$valid     = $answered && ($data['valid'] ?? false) === true;
$status    = \is_string($data['status'] ?? null) ? (string) $data['status'] : '';
$reason    = \is_string($data['reason'] ?? null) ? (string) $data['reason'] : '';
$issuer    = \is_array($data['issuer'] ?? null) ? $data['issuer'] : [];
$credits   = \is_array($data['credits'] ?? null) ? $data['credits'] : [];

/**
 * Every field is read defensively and rendered only when the build answered
 * it. The verification shape gains fields as the credential work lands, and
 * this page is the one surface a relying party reads, so it must degrade to
 * fewer rows rather than to a warning.
 */
$string = static function (array $data, string $key): string {
    $value = $data[$key] ?? null;

    return \is_string($value) && $value !== '' ? $value : '';
};

$date = static function (string $value): string {
    return $value === '' ? '' : HTMLHelper::_('date', $value, Text::_('DATE_FORMAT_LC3'));
};

/**
 * The label for one credential status, falling back to the raw value.
 *
 * Text::_() returns the constant itself when no string is defined, so a
 * status this catalogue has not caught up with printed
 * COM_VIDYALMS_VERIFY_STATUS_FOO on a public evidentiary page. WordPress's
 * copy of this page returns the value untranslated instead, and the two hosts
 * must not degrade differently on the one surface a relying party reads with
 * none of our client code. The vocabulary itself is published by the core as
 * CredentialStatus::allValues(); this list is the host's translation of it.
 */
$statusLabel = static function (string $status): string {
    $key = 'COM_VIDYALMS_VERIFY_STATUS_' . strtoupper($status);
    $label = Text::_($key);

    return $label === $key ? $status : $label;
};

/**
 * An href a browser may follow, or the empty string.
 *
 * $this->escape() is htmlspecialchars, which restricts no scheme, so a
 * javascript: issuer address reached the href of a public page. WordPress's
 * own copy of this page runs esc_url(), which strips it; the two hosts must
 * not disagree about the same payload, and the core now refuses to store one.
 */
$href = static function (string $value): string {
    $scheme = parse_url($value, PHP_URL_SCHEME);

    return \is_string($scheme) && \in_array(strtolower($scheme), ['http', 'https'], true) ? $value : '';
};
?>
<div class="vidyalms vidyalms-verify">
    <?php if ((int) $this->params->get('show_page_heading', 1) === 1) : ?>
        <h1 class="vidyalms-page-title"><?php echo $this->escape($heading); ?></h1>
    <?php endif; ?>

    <p class="vidyalms-verify-intro"><?php echo Text::_('COM_VIDYALMS_VERIFY_INTRO'); ?></p>

    <form class="vidyalms-filters" action="<?php echo $this->formAction; ?>" method="get">
        <div class="vidyalms-field">
            <label class="vidyalms-label" for="vidyalms-verify-code"><?php echo Text::_('COM_VIDYALMS_VERIFY_CODE_LABEL'); ?></label>
            <input
                class="vidyalms-input"
                type="text"
                id="vidyalms-verify-code"
                name="code"
                inputmode="latin"
                autocapitalize="characters"
                maxlength="64"
                value="<?php echo $this->escape($this->code); ?>">
        </div>
        <div class="vidyalms-field vidyalms-field-actions">
            <button class="vidyalms-button" type="submit"><?php echo Text::_('COM_VIDYALMS_VERIFY_SUBMIT'); ?></button>
        </div>

        <input type="hidden" name="option" value="com_vidyalms">
        <input type="hidden" name="view" value="verify">
        <?php if ($this->itemId > 0) : ?>
            <input type="hidden" name="Itemid" value="<?php echo $this->itemId; ?>">
        <?php endif; ?>
    </form>

    <?php if ($this->rateLimited) : ?>
        <p class="vidyalms-notice vidyalms-verify-throttled" role="status">
            <?php echo Text::_('COM_VIDYALMS_VERIFY_THROTTLED'); ?>
        </p>
    <?php elseif ($this->result !== null && !$this->available) : ?>
        <p class="vidyalms-notice vidyalms-verify-unavailable" role="status">
            <?php echo Text::_('COM_VIDYALMS_VERIFY_UNAVAILABLE'); ?>
        </p>
    <?php elseif ($answered) : ?>
        <section class="vidyalms-verify-result <?php echo $valid ? 'vidyalms-verify-valid' : 'vidyalms-verify-invalid'; ?>" aria-labelledby="vidyalms-verify-heading">
            <h2 id="vidyalms-verify-heading">
                <?php echo $valid
                    ? Text::_('COM_VIDYALMS_VERIFY_VALID')
                    : Text::_('COM_VIDYALMS_VERIFY_INVALID'); ?>
            </h2>

            <?php if ($status !== '') : ?>
                <p class="vidyalms-verify-status" data-status="<?php echo $this->escape($status); ?>">
                    <?php echo $this->escape($statusLabel($status)); ?>
                </p>
            <?php endif; ?>

            <?php if (!$valid && $reason !== '') : ?>
                <p class="vidyalms-verify-reason">
                    <?php echo Text::_('COM_VIDYALMS_VERIFY_REASON_' . strtoupper($reason)); ?>
                </p>
            <?php endif; ?>

            <dl class="vidyalms-definition">
                <dt><?php echo Text::_('COM_VIDYALMS_CERTIFICATE_CODE'); ?></dt>
                <dd><code class="vidyalms-code"><?php echo $this->escape($this->code); ?></code></dd>

                <?php if ($string($data, 'course_title') !== '') : ?>
                    <dt><?php echo Text::_('COM_VIDYALMS_VERIFY_COURSE'); ?></dt>
                    <dd><?php echo $this->escape($string($data, 'course_title')); ?></dd>
                <?php endif; ?>

                <?php if ($string($data, 'learner_name') !== '') : ?>
                    <dt><?php echo Text::_('COM_VIDYALMS_VERIFY_LEARNER'); ?></dt>
                    <dd><?php echo $this->escape($string($data, 'learner_name')); ?></dd>
                <?php endif; ?>

                <?php if (\is_string($issuer['name'] ?? null) && $issuer['name'] !== '') : ?>
                    <dt><?php echo Text::_('COM_VIDYALMS_VERIFY_ISSUER'); ?></dt>
                    <dd>
                        <?php if ($href(\is_string($issuer['url'] ?? null) ? (string) $issuer['url'] : '') !== '') : ?>
                            <a href="<?php echo $this->escape($href((string) $issuer['url'])); ?>" rel="nofollow noopener"><?php echo $this->escape((string) $issuer['name']); ?></a>
                        <?php else : ?>
                            <?php echo $this->escape((string) $issuer['name']); ?>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <?php if ($string($data, 'completed_at') !== '') : ?>
                    <dt><?php echo Text::_('COM_VIDYALMS_VERIFY_COMPLETED'); ?></dt>
                    <dd><?php echo $date($string($data, 'completed_at')); ?></dd>
                <?php endif; ?>

                <?php if ($string($data, 'issued_at') !== '') : ?>
                    <dt><?php echo Text::_('COM_VIDYALMS_CERTIFICATE_ISSUED'); ?></dt>
                    <dd><?php echo $date($string($data, 'issued_at')); ?></dd>
                <?php endif; ?>

                <?php if ($string($data, 'valid_from') !== '') : ?>
                    <dt><?php echo Text::_('COM_VIDYALMS_VERIFY_VALID_FROM'); ?></dt>
                    <dd><?php echo $date($string($data, 'valid_from')); ?></dd>
                <?php endif; ?>

                <?php if ($string($data, 'expires_at') !== '') : ?>
                    <dt><?php echo Text::_('COM_VIDYALMS_CERTIFICATE_EXPIRES'); ?></dt>
                    <dd><?php echo $date($string($data, 'expires_at')); ?></dd>
                <?php endif; ?>

                <?php if ($string($data, 'revoked_at') !== '') : ?>
                    <dt><?php echo Text::_('COM_VIDYALMS_VERIFY_REVOKED_ON'); ?></dt>
                    <dd><?php echo $date($string($data, 'revoked_at')); ?></dd>
                <?php endif; ?>

                <?php foreach ($credits as $credit) : ?>
                    <?php if (!\is_array($credit)) : ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <dt><?php echo Text::_('COM_VIDYALMS_VERIFY_CREDIT'); ?></dt>
                    <dd><?php echo $this->escape(trim(
                        $string($credit, 'amount') . ' ' . $string($credit, 'unit')
                    )); ?></dd>
                <?php endforeach; ?>
            </dl>

            <?php if ($string($data, 'superseded_by_url') !== '') : ?>
                <p class="vidyalms-verify-superseded">
                    <a href="<?php echo $this->escape($string($data, 'superseded_by_url')); ?>" rel="nofollow">
                        <?php echo Text::_('COM_VIDYALMS_VERIFY_SUPERSEDED_LINK'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if ($string($data, 'credential_url') !== '') : ?>
                <p class="vidyalms-verify-credential">
                    <a href="<?php echo $this->escape($string($data, 'credential_url')); ?>" rel="nofollow">
                        <?php echo Text::_('COM_VIDYALMS_VERIFY_CREDENTIAL_LINK'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <p class="vidyalms-verify-note"><?php echo Text::_('COM_VIDYALMS_VERIFY_NOT_VALIDATION'); ?></p>
        </section>
    <?php endif; ?>
</div>
