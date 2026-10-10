<?php

declare(strict_types=1);

/**
 * VidyaLMS: public certificate verification.
 *
 * Every code gets the same shape of answer, and an unknown one is an ordinary
 * negative result rather than an error, so probing this page tells an attacker
 * nothing about which codes exist. It is noindex, because the answer names a
 * person against a course completion, and the contract call behind it runs
 * through the core rate limiter.
 *
 * The page discloses only what the design permits: name, course, issuer,
 * dates, status and credits. Never an email, a user id, an enrolment id or the
 * internal certificate id.
 *
 * @var array<string, mixed> $context Built by Templates::certificateContext().
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\Rewrites;

defined( 'ABSPATH' ) || exit;

$vidyalms_context = isset($context) && \is_array($context) ? $context : [];

$vidyalms_available   = (bool) ($vidyalms_context['available'] ?? false);
$vidyalms_answered    = (bool) ($vidyalms_context['answered'] ?? false);
$vidyalms_rate_limited = (bool) ($vidyalms_context['rate_limited'] ?? false);
$vidyalms_is_valid     = (bool) ($vidyalms_context['valid'] ?? false);
$vidyalms_code        = (string) ($vidyalms_context['code'] ?? '');
$vidyalms_status_label = (string) ($vidyalms_context['status_label'] ?? '');
$vidyalms_status      = (string) ($vidyalms_context['status'] ?? '');
$vidyalms_reason      = (string) ($vidyalms_context['reason_label'] ?? '');
$vidyalms_course_title = (string) ($vidyalms_context['course_title'] ?? '');
$vidyalms_learner_name = (string) ($vidyalms_context['learner_name'] ?? '');
$vidyalms_issuer_name  = (string) ($vidyalms_context['issuer_name'] ?? '');
$vidyalms_issuer_url   = (string) ($vidyalms_context['issuer_url'] ?? '');
$vidyalms_completed   = (string) ($vidyalms_context['completed'] ?? '');
$vidyalms_completed_iso = (string) ($vidyalms_context['completed_iso'] ?? '');
$vidyalms_issued      = (string) ($vidyalms_context['issued'] ?? '');
$vidyalms_issued_iso   = (string) ($vidyalms_context['issued_iso'] ?? '');
$vidyalms_valid_from   = (string) ($vidyalms_context['valid_from'] ?? '');
$vidyalms_valid_from_iso = (string) ($vidyalms_context['valid_from_iso'] ?? '');
$vidyalms_expires     = (string) ($vidyalms_context['expires'] ?? '');
$vidyalms_expires_iso  = (string) ($vidyalms_context['expires_iso'] ?? '');
$vidyalms_revoked_on   = (string) ($vidyalms_context['revoked_on'] ?? '');
$vidyalms_revoked_iso  = (string) ($vidyalms_context['revoked_on_iso'] ?? '');
$vidyalms_credits     = \is_array($vidyalms_context['credits'] ?? null) ? $vidyalms_context['credits'] : [];
$vidyalms_credential  = (string) ($vidyalms_context['credential_url'] ?? '');
$vidyalms_superseded  = (string) ($vidyalms_context['superseded_by_url'] ?? '');
$vidyalms_show_form    = (bool) ($vidyalms_context['show_form'] ?? true);
$vidyalms_form_action  = (string) ($vidyalms_context['form_action'] ?? '');
$vidyalms_code_id      = wp_unique_id('vidyalms-code-');
?>
<div class="vidyalms vidyalms-verify">
    <p class="vidyalms-verify-intro">
        <?php esc_html_e('Enter the code printed on a certificate to check that it was issued by this site.', 'vidyalms'); ?>
    </p>

    <?php if ($vidyalms_show_form) : ?>
        <form class="vidyalms-filters" method="get" action="<?php echo esc_url($vidyalms_form_action); ?>">
            <div class="vidyalms-field">
                <label class="vidyalms-label" for="<?php echo esc_attr($vidyalms_code_id); ?>">
                    <?php esc_html_e('Certificate code', 'vidyalms'); ?>
                </label>
                <input
                    class="vidyalms-input"
                    type="text"
                    id="<?php echo esc_attr($vidyalms_code_id); ?>"
                    name="<?php echo esc_attr(Rewrites::QUERY_CODE); ?>"
                    value="<?php echo esc_attr($vidyalms_code); ?>"
                    maxlength="64"
                    autocapitalize="characters"
                    autocomplete="off"
                    spellcheck="false">
            </div>
            <div class="vidyalms-field vidyalms-field-actions">
                <button class="vidyalms-button" type="submit"><?php esc_html_e('Verify', 'vidyalms'); ?></button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($vidyalms_rate_limited) : ?>
        <p class="vidyalms-notice vidyalms-verify-throttled" role="status">
            <?php esc_html_e('Too many checks from here just now. Wait a moment and try again.', 'vidyalms'); ?>
        </p>
    <?php elseif (!$vidyalms_available) : ?>
        <p class="vidyalms-notice vidyalms-notice-error">
            <?php esc_html_e('Verification is not available right now.', 'vidyalms'); ?>
        </p>
    <?php elseif ($vidyalms_answered) : ?>
        <section
            class="vidyalms-verify-result <?php echo $vidyalms_is_valid ? 'vidyalms-verify-valid' : 'vidyalms-verify-invalid'; ?>"
            aria-labelledby="vidyalms-verify-heading">
            <h2 id="vidyalms-verify-heading" class="vidyalms-verify-heading">
                <?php
                echo $vidyalms_is_valid
                    ? esc_html__('This certificate is valid.', 'vidyalms')
                    : esc_html__('This certificate is not valid.', 'vidyalms');
                ?>
            </h2>

            <?php if ($vidyalms_status_label !== '') : ?>
                <p class="vidyalms-verify-status" data-status="<?php echo esc_attr($vidyalms_status); ?>">
                    <?php echo esc_html($vidyalms_status_label); ?>
                </p>
            <?php endif; ?>

            <?php if (!$vidyalms_is_valid && $vidyalms_reason !== '') : ?>
                <p class="vidyalms-verify-reason"><?php echo esc_html($vidyalms_reason); ?></p>
            <?php endif; ?>

            <dl class="vidyalms-definition">
                <dt><?php esc_html_e('Code', 'vidyalms'); ?></dt>
                <dd><code class="vidyalms-code"><?php echo esc_html($vidyalms_code); ?></code></dd>

                <?php if ($vidyalms_course_title !== '') : ?>
                    <dt><?php esc_html_e('Course', 'vidyalms'); ?></dt>
                    <dd><?php echo esc_html($vidyalms_course_title); ?></dd>
                <?php endif; ?>

                <?php if ($vidyalms_learner_name !== '') : ?>
                    <dt><?php esc_html_e('Awarded to', 'vidyalms'); ?></dt>
                    <dd><?php echo esc_html($vidyalms_learner_name); ?></dd>
                <?php endif; ?>

                <?php if ($vidyalms_issuer_name !== '') : ?>
                    <dt><?php esc_html_e('Issued by', 'vidyalms'); ?></dt>
                    <dd>
                        <?php if ($vidyalms_issuer_url !== '') : ?>
                            <a href="<?php echo esc_url($vidyalms_issuer_url); ?>" rel="nofollow noopener"><?php echo esc_html($vidyalms_issuer_name); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($vidyalms_issuer_name); ?>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <?php if ($vidyalms_completed !== '') : ?>
                    <dt><?php esc_html_e('Completed', 'vidyalms'); ?></dt>
                    <dd>
                        <time datetime="<?php echo esc_attr($vidyalms_completed_iso); ?>"><?php echo esc_html($vidyalms_completed); ?></time>
                    </dd>
                <?php endif; ?>

                <?php if ($vidyalms_issued !== '') : ?>
                    <dt><?php esc_html_e('Issued', 'vidyalms'); ?></dt>
                    <dd>
                        <time datetime="<?php echo esc_attr($vidyalms_issued_iso); ?>"><?php echo esc_html($vidyalms_issued); ?></time>
                    </dd>
                <?php endif; ?>

                <?php if ($vidyalms_valid_from !== '') : ?>
                    <dt><?php esc_html_e('In force from', 'vidyalms'); ?></dt>
                    <dd>
                        <time datetime="<?php echo esc_attr($vidyalms_valid_from_iso); ?>"><?php echo esc_html($vidyalms_valid_from); ?></time>
                    </dd>
                <?php endif; ?>

                <?php if ($vidyalms_expires !== '') : ?>
                    <dt><?php esc_html_e('Expires', 'vidyalms'); ?></dt>
                    <dd>
                        <time datetime="<?php echo esc_attr($vidyalms_expires_iso); ?>"><?php echo esc_html($vidyalms_expires); ?></time>
                    </dd>
                <?php endif; ?>

                <?php if ($vidyalms_revoked_on !== '') : ?>
                    <dt><?php esc_html_e('Revoked on', 'vidyalms'); ?></dt>
                    <dd>
                        <time datetime="<?php echo esc_attr($vidyalms_revoked_iso); ?>"><?php echo esc_html($vidyalms_revoked_on); ?></time>
                    </dd>
                <?php endif; ?>

                <?php foreach ($vidyalms_credits as $vidyalms_credit) : ?>
                    <dt><?php esc_html_e('Credit', 'vidyalms'); ?></dt>
                    <dd><?php echo esc_html((string) $vidyalms_credit); ?></dd>
                <?php endforeach; ?>
            </dl>

            <?php if ($vidyalms_superseded !== '') : ?>
                <p class="vidyalms-verify-superseded">
                    <a href="<?php echo esc_url($vidyalms_superseded); ?>" rel="nofollow">
                        <?php esc_html_e('Open the certificate that replaced this one', 'vidyalms'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if ($vidyalms_credential !== '') : ?>
                <p class="vidyalms-verify-credential">
                    <a href="<?php echo esc_url($vidyalms_credential); ?>" rel="nofollow">
                        <?php esc_html_e('Open the machine readable credential', 'vidyalms'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <p class="vidyalms-verify-note">
                <?php esc_html_e('This check confirms that the site issued this certificate and what its current status is. It does not judge what the holder can do.', 'vidyalms'); ?>
            </p>
        </section>
    <?php elseif ($vidyalms_code !== '') : ?>
        <p class="vidyalms-notice vidyalms-notice-error">
            <?php esc_html_e('This certificate could not be verified.', 'vidyalms'); ?>
        </p>
    <?php endif; ?>
</div>
