<?php

declare(strict_types=1);

/**
 * VidyaLMS: the learner dashboard.
 *
 * A guest is offered the sign-in gate, never an error: not being signed in is
 * an ordinary state of this page, not a failure.
 *
 * @var array<string, mixed> $context Built by Templates::myLearningContext().
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\SignInGate;
use Shondalai\VidyaLMS\WordPress\Content\Rewrites;

defined( 'ABSPATH' ) || exit;

$vidyalms_context = isset($context) && \is_array($context) ? $context : [];

$vidyalms_available        = (bool) ($vidyalms_context['available'] ?? false);
$vidyalms_is_guest          = (bool) ($vidyalms_context['is_guest'] ?? true);
$vidyalms_enrolments       = (array) ($vidyalms_context['enrolments'] ?? []);
$vidyalms_certificates     = (array) ($vidyalms_context['certificates'] ?? []);
$vidyalms_show_certificates = (bool) ($vidyalms_context['show_certificates'] ?? true);
$vidyalms_catalogue_url     = (string) ($vidyalms_context['catalogue_url'] ?? '');
$vidyalms_enrolment_page    = (int) ($vidyalms_context['enrolment_page'] ?? 1);
$vidyalms_enrolment_total   = (int) ($vidyalms_context['enrolment_total'] ?? 0);
$vidyalms_enrolment_pagination = (string) ($vidyalms_context['enrolment_pagination'] ?? '');
?>
<div class="vidyalms vidyalms-my-learning">
    <?php $vidyalms_shop_account_url = class_exists(\Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::class)
        ? \Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::accountUrl() : ''; ?>
    <?php if (!$vidyalms_is_guest && $vidyalms_shop_account_url !== '') : ?>
        <p><a href="<?php echo esc_url($vidyalms_shop_account_url); ?>"><?php esc_html_e('Shop orders and subscriptions', 'vidyalms'); ?></a></p>
    <?php endif; ?>
    <?php if ($vidyalms_is_guest) : ?>
        <?php SignInGate::render(\is_array($vidyalms_context['signin'] ?? null) ? $vidyalms_context['signin'] : SignInGate::context('mylearning', Rewrites::myLearningUrl())); ?>
    <?php elseif (!$vidyalms_available) : ?>
        <p class="vidyalms-notice vidyalms-notice-error">
            <?php esc_html_e('Your learning is not available right now.', 'vidyalms'); ?>
        </p>
    <?php else : ?>
        <form method="get" data-vy-page-form class="vy-learning-filter" action="<?php echo esc_url((string) $vidyalms_context['learning_url']); ?>">
            <?php
            // Preserve the host route on installations using plain permalinks.
            $vidyalms_route_query = [];
            parse_str((string) wp_parse_url((string) $vidyalms_context['learning_url'], PHP_URL_QUERY), $vidyalms_route_query);
            foreach ($vidyalms_route_query as $vidyalms_key => $vidyalms_value) :
                if (!is_scalar($vidyalms_value) || in_array($vidyalms_key, ['organisation_id', 'vidyalms_learning_page'], true)) { continue; }
                ?><input type="hidden" name="<?php echo esc_attr($vidyalms_key); ?>" value="<?php echo esc_attr((string) $vidyalms_value); ?>"><?php
            endforeach;
            ?>
            <label for="vy-learning-source"><?php esc_html_e('Learning from', 'vidyalms'); ?></label>
            <select id="vy-learning-source" name="organisation_id">
                <option value=""><?php esc_html_e('All learning', 'vidyalms'); ?></option>
                <option value="0" <?php selected($vidyalms_context['organisation_filter'] === 0); ?>><?php esc_html_e('Personal enrolment', 'vidyalms'); ?></option>
                <?php foreach ($vidyalms_context['organisation_options'] as $vidyalms_organisation) : ?>
                    <option value="<?php echo esc_attr((string) $vidyalms_organisation['id']); ?>" <?php selected($vidyalms_context['organisation_filter'] === $vidyalms_organisation['id']); ?>><?php echo esc_html($vidyalms_organisation['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="vidyalms-button"><?php esc_html_e('Apply filter', 'vidyalms'); ?></button>
        </form>
        <?php foreach ($vidyalms_enrolments as $vidyalms_resume) : ?>
            <?php if (empty($vidyalms_resume['learn_url']) || (float) ($vidyalms_resume['percent'] ?? 0) >= 100) { continue; } ?>
            <section class="vy-resume-course">
                <div><p class="vy-page-kicker"><?php esc_html_e('Pick up where you left off', 'vidyalms'); ?></p><h2><?php echo esc_html((string) ($vidyalms_resume['title'] ?? '')); ?></h2><p><?php echo esc_html(number_format_i18n((float) ($vidyalms_resume['percent'] ?? 0), 1)); ?>% <?php esc_html_e('complete', 'vidyalms'); ?></p></div>
                <a class="vidyalms-button" href="<?php echo esc_url((string) $vidyalms_resume['learn_url']); ?>"><?php esc_html_e('Continue learning', 'vidyalms'); ?></a>
            </section>
            <?php break; ?>
        <?php endforeach; ?>
        <nav class="vidyalms-learner-navigation" aria-label="<?php esc_attr_e('Your learning account', 'vidyalms'); ?>">
            <a class="vidyalms-button" href="<?php echo esc_url((string) ($vidyalms_context['achievements_url'] ?? '')); ?>"><?php esc_html_e('Achievements', 'vidyalms'); ?></a>
            <a class="vidyalms-button" href="<?php echo esc_url((string) ($vidyalms_context['organisations_url'] ?? '')); ?>"><?php esc_html_e('My organizations', 'vidyalms'); ?></a>
            <a class="vidyalms-button" href="<?php echo esc_url((string) ($vidyalms_context['preferences_url'] ?? '')); ?>"><?php esc_html_e('Notification preferences', 'vidyalms'); ?></a>
        </nav>
        <section class="vidyalms-enrolments" aria-labelledby="vidyalms-enrolments-heading">
            <h2 id="vidyalms-enrolments-heading"><?php esc_html_e('My courses', 'vidyalms'); ?></h2>

            <?php if ($vidyalms_enrolments === []) : ?>
                <p class="vidyalms-empty"><?php echo esc_html($vidyalms_context['organisation_filter'] !== null ? __('No courses match this learning source. Choose All learning to see your other courses.', 'vidyalms') : __('You are not enrolled on any course yet.', 'vidyalms')); ?></p>
                <p>
                    <a class="vidyalms-button" href="<?php echo esc_url($vidyalms_catalogue_url); ?>">
                        <?php esc_html_e('Browse the catalogue', 'vidyalms'); ?>
                    </a>
                </p>
            <?php else : ?>
                <p class="vidyalms-result-count" role="status"><?php printf(
                    /* translators: 1: first row, 2: last row, 3: total courses. */
                    esc_html__('Showing %1$s to %2$s of %3$s courses', 'vidyalms'),
                    esc_html(number_format_i18n(($vidyalms_enrolment_page - 1) * 20 + 1)),
                    esc_html(number_format_i18n(($vidyalms_enrolment_page - 1) * 20 + \count($vidyalms_enrolments))),
                    esc_html(number_format_i18n($vidyalms_enrolment_total)),
                ); ?></p>
                <ul class="vidyalms-enrolment-list">
                    <?php foreach ($vidyalms_enrolments as $vidyalms_enrolment) : ?>
                        <?php
                        $vidyalms_title    = (string) ($vidyalms_enrolment['title'] ?? '');
                        $vidyalms_url      = (string) ($vidyalms_enrolment['url'] ?? '');
                        $vidyalms_learn_url = (string) ($vidyalms_enrolment['learn_url'] ?? '');
                        $vidyalms_percent  = (float) ($vidyalms_enrolment['percent'] ?? 0.0);
                        $vidyalms_bar_id    = wp_unique_id('vidyalms-bar-');
                        ?>
                        <li class="vidyalms-enrolment">
                            <h3 class="vidyalms-enrolment-title">
                                <?php if ($vidyalms_url !== '') : ?>
                                    <a href="<?php echo esc_url($vidyalms_url); ?>"><?php echo esc_html($vidyalms_title); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($vidyalms_title); ?>
                                <?php endif; ?>
                            </h3>

                            <p class="vy-learning-sources"><span><?php esc_html_e('Enrolment sources', 'vidyalms'); ?></span>
                                <?php foreach ($vidyalms_enrolment['organisations'] ?? [] as $vidyalms_organisation) : ?>
                                    <span class="vidyalms-badge"><?php echo esc_html($vidyalms_organisation['name']); ?></span>
                                <?php endforeach; ?>
                                <?php if (!empty($vidyalms_enrolment['personal_enrolment'])) : ?><span class="vidyalms-badge"><?php esc_html_e('Personal enrolment', 'vidyalms'); ?></span><?php endif; ?>
                            </p>

                            <p class="vidyalms-enrolment-status">
                                <span class="vidyalms-badge-label"><?php esc_html_e('Status', 'vidyalms'); ?></span>
                                <?php echo esc_html((string) ($vidyalms_enrolment['status_label'] ?? '')); ?>
                            </p>

                            <div class="vidyalms-progress">
                                <label class="vidyalms-label" for="<?php echo esc_attr($vidyalms_bar_id); ?>">
                                    <?php esc_html_e('Your progress', 'vidyalms'); ?>
                                </label>
                                <progress
                                    class="vidyalms-progress-bar"
                                    id="<?php echo esc_attr($vidyalms_bar_id); ?>"
                                    max="100"
                                    value="<?php echo esc_attr((string) $vidyalms_percent); ?>"></progress>
                                <p class="vidyalms-progress-value">
                                    <?php
                                    printf(
                                        /* translators: %s: percentage of the course completed. */
                                        esc_html__('%s%% complete', 'vidyalms'),
                                        esc_html(number_format_i18n($vidyalms_percent, 1)),
                                    );
                                    ?>
                                </p>
                            </div>

                            <?php if ($vidyalms_learn_url !== '') : ?>
                                <p>
                                    <a class="vidyalms-button" href="<?php echo esc_url($vidyalms_learn_url); ?>">
                                        <?php esc_html_e('Continue learning', 'vidyalms'); ?>
                                    </a>
                                    <?php if ($vidyalms_url !== '') : ?>
                                        <a class="vidyalms-link" href="<?php echo esc_url($vidyalms_url); ?>">
                                            <?php esc_html_e('Course page', 'vidyalms'); ?>
                                        </a>
                                    <?php endif; ?>
                                </p>
                            <?php elseif ($vidyalms_url !== '') : ?>
                                <p>
                                    <a class="vidyalms-link" href="<?php echo esc_url($vidyalms_url); ?>">
                                        <?php esc_html_e('Continue this course', 'vidyalms'); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($vidyalms_enrolment_pagination !== '') : ?>
                    <nav class="vidyalms-pagination" aria-label="<?php esc_attr_e('Your course pages', 'vidyalms'); ?>">
                        <?php echo wp_kses_post($vidyalms_enrolment_pagination); ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($vidyalms_show_certificates) : ?>
            <section class="vidyalms-certificates" aria-labelledby="vidyalms-certificates-heading">
                <h2 id="vidyalms-certificates-heading"><?php esc_html_e('Recent certificates', 'vidyalms'); ?></h2>

                <?php if ($vidyalms_certificates === []) : ?>
                    <p class="vidyalms-empty"><?php esc_html_e('You have no certificates yet.', 'vidyalms'); ?></p>
                <?php else : ?>
                    <ul class="vidyalms-certificate-list">
                        <?php foreach ($vidyalms_certificates as $vidyalms_certificate) : ?>
                            <?php
                            $vidyalms_title  = (string) ($vidyalms_certificate['title'] ?? '');
                            $vidyalms_issued = (string) ($vidyalms_certificate['issued'] ?? '');
                            ?>
                            <li class="vidyalms-certificate">
                                <h3 class="vidyalms-certificate-title">
                                    <?php echo esc_html($vidyalms_title !== '' ? $vidyalms_title : __('Certificate', 'vidyalms')); ?>
                                </h3>

                                <p class="vidyalms-certificate-meta">
                                    <span class="vidyalms-badge-label"><?php esc_html_e('Code', 'vidyalms'); ?></span>
                                    <code class="vidyalms-code"><?php echo esc_html((string) ($vidyalms_certificate['code'] ?? '')); ?></code>
                                </p>

                                <?php if ($vidyalms_issued !== '') : ?>
                                    <p class="vidyalms-certificate-meta">
                                        <span class="vidyalms-badge-label"><?php esc_html_e('Issued', 'vidyalms'); ?></span>
                                        <time datetime="<?php echo esc_attr((string) ($vidyalms_certificate['issued_iso'] ?? '')); ?>">
                                            <?php echo esc_html($vidyalms_issued); ?>
                                        </time>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($vidyalms_certificate['revoked'])) : ?>
                                    <p class="vidyalms-notice vidyalms-notice-error">
                                        <?php esc_html_e('This certificate has been revoked.', 'vidyalms'); ?>
                                    </p>
                                <?php endif; ?>

                                <p>
                                    <a class="vidyalms-link" href="<?php echo esc_url((string) ($vidyalms_certificate['verify_url'] ?? '')); ?>">
                                        <?php esc_html_e('Verify this certificate', 'vidyalms'); ?>
                                    </a>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
