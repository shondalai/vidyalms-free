<?php

declare(strict_types=1);

/**
 * VidyaLMS: the catalogue listing.
 *
 * Rendered by the catalogue page, the [vidyalms_catalogue] shortcode and the
 * catalogue block alike, so it starts at h2 and leaves the h1 to whatever
 * frames it.
 *
 * @var array<string, mixed> $context Built by Templates::catalogueContext().
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\Rewrites;
use Shondalai\VidyaLMS\WordPress\Content\Templates;

defined( 'ABSPATH' ) || exit;

$vidyalms_context = isset($context) && \is_array($context) ? $context : [];

$vidyalms_available  = (bool) ($vidyalms_context['available'] ?? false);
$vidyalms_courses    = (array) ($vidyalms_context['courses'] ?? []);
$vidyalms_search     = (string) ($vidyalms_context['search'] ?? '');
$vidyalms_category   = (int) ($vidyalms_context['category'] ?? 0);
$vidyalms_total      = (int) ($vidyalms_context['total'] ?? 0);
$vidyalms_heading    = (string) ($vidyalms_context['heading'] ?? '');
$vidyalms_show_search = (bool) ($vidyalms_context['show_search'] ?? true);
$vidyalms_form_action = (string) ($vidyalms_context['form_action'] ?? '');
$vidyalms_pagination = (string) ($vidyalms_context['pagination'] ?? '');
$vidyalms_search_id   = wp_unique_id('vidyalms-search-');
?>
<div class="vidyalms vidyalms-catalogue">
    <?php if ($vidyalms_heading !== '') : ?>
        <h2 class="vidyalms-section-title"><?php echo esc_html($vidyalms_heading); ?></h2>
    <?php endif; ?>

    <?php if ($vidyalms_show_search) : ?>
        <form data-vy-page-form class="vidyalms-filters" role="search" method="get" action="<?php echo esc_url($vidyalms_form_action); ?>">
            <div class="vidyalms-field">
                <label class="vidyalms-label" for="<?php echo esc_attr($vidyalms_search_id); ?>">
                    <?php esc_html_e('Search courses', 'vidyalms'); ?>
                </label>
                <input
                    class="vidyalms-input"
                    type="search"
                    id="<?php echo esc_attr($vidyalms_search_id); ?>"
                    name="<?php echo esc_attr(Rewrites::QUERY_SEARCH); ?>"
                    value="<?php echo esc_attr($vidyalms_search); ?>"
                    placeholder="<?php esc_attr_e('Title or keyword', 'vidyalms'); ?>">
            </div>

            <?php if ($vidyalms_category > 0) : ?>
                <input
                    type="hidden"
                    name="<?php echo esc_attr(Rewrites::QUERY_CATEGORY); ?>"
                    value="<?php echo esc_attr((string) $vidyalms_category); ?>">
            <?php endif; ?>

            <div class="vidyalms-field vidyalms-field-actions">
                <button class="vidyalms-button" type="submit"><?php esc_html_e('Search', 'vidyalms'); ?></button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (!$vidyalms_available) : ?>
        <p class="vidyalms-notice vidyalms-notice-error">
            <?php esc_html_e('The course catalogue is not available right now.', 'vidyalms'); ?>
        </p>
    <?php else : ?>
        <?php if ($vidyalms_heading === '') : ?>
            <h2 class="vidyalms-visually-hidden"><?php esc_html_e('Courses', 'vidyalms'); ?></h2>
        <?php endif; ?>

        <p class="vidyalms-result-count" role="status">
            <?php
            printf(
                /* translators: %s: number of courses found. */
                esc_html(_n('%s course found', '%s courses found', $vidyalms_total, 'vidyalms')),
                esc_html(number_format_i18n($vidyalms_total)),
            );
            ?>
        </p>

        <?php if ($vidyalms_courses === []) : ?>
            <p class="vidyalms-empty">
                <?php
                echo $vidyalms_search === ''
                    ? esc_html__('No courses have been published yet.', 'vidyalms')
                    : esc_html__('No courses match your search.', 'vidyalms');
                ?>
            </p>
        <?php else : ?>
            <ul class="vidyalms-grid">
                <?php foreach ($vidyalms_courses as $vidyalms_course) : ?>
                    <?php
                    $vidyalms_url     = (string) ($vidyalms_course['url'] ?? '');
                    $vidyalms_summary = (string) ($vidyalms_course['summary'] ?? '');
                    $vidyalms_level   = (string) ($vidyalms_course['level'] ?? '');
                    $vidyalms_price   = (string) ($vidyalms_course['price_label'] ?? '');
                    $vidyalms_image   = Templates::courseImage((string) ($vidyalms_course['image'] ?? ''));
                    ?>
                    <li class="vidyalms-grid-item">
                        <article class="vidyalms-card">
                            <?php if ($vidyalms_image !== '') : ?>
                                <div class="vidyalms-card-media">
                                    <a
                                        class="vidyalms-card-media-link"
                                        href="<?php echo esc_url($vidyalms_url); ?>"
                                        tabindex="-1"
                                        aria-hidden="true">
                                        <?php
                                        echo wp_kses_post($vidyalms_image);
                                        ?>
                                    </a>
                                </div>
                            <?php else : ?>
                                <div class="vy-course-art" aria-hidden="true"><small><?php esc_html_e('Course', 'vidyalms'); ?></small><strong><?php echo esc_html(mb_substr((string) ($vidyalms_course['title'] ?? ''), 0, 1)); ?></strong></div>
                            <?php endif; ?>

                            <h3 class="vidyalms-card-title">
                                <a class="vidyalms-card-link" href="<?php echo esc_url($vidyalms_url); ?>">
                                    <?php echo esc_html((string) ($vidyalms_course['title'] ?? '')); ?>
                                </a>
                            </h3>

                            <?php if ($vidyalms_summary !== '') : ?>
                                <p class="vidyalms-card-summary"><?php echo esc_html($vidyalms_summary); ?></p>
                            <?php endif; ?>

                            <p class="vidyalms-card-meta">
                                <?php if ($vidyalms_level !== '') : ?>
                                    <span class="vidyalms-badge">
                                        <span class="vidyalms-badge-label"><?php esc_html_e('Level', 'vidyalms'); ?></span>
                                        <?php echo esc_html((string) ($vidyalms_course['level_label'] ?? $vidyalms_level)); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($vidyalms_course['is_free'])) : ?>
                                    <span class="vidyalms-badge vidyalms-badge-free">
                                        <?php esc_html_e('Free', 'vidyalms'); ?>
                                    </span>
                                <?php elseif ($vidyalms_price !== '') : ?>
                                    <span class="vidyalms-badge vidyalms-badge-price">
                                        <span class="vidyalms-badge-label"><?php esc_html_e('Price', 'vidyalms'); ?></span>
                                        <?php echo esc_html($vidyalms_price); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($vidyalms_course['featured'])) : ?>
                                    <span class="vidyalms-badge vidyalms-badge-featured">
                                        <?php esc_html_e('Featured', 'vidyalms'); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                            <a class="vy-course-link" href="<?php echo esc_url($vidyalms_url); ?>"><?php esc_html_e('Explore course', 'vidyalms'); ?></a>
                        </article>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($vidyalms_pagination !== '') : ?>
                <nav class="vidyalms-pagination" aria-label="<?php esc_attr_e('Catalogue pages', 'vidyalms'); ?>">
                    <?php
                    echo wp_kses_post($vidyalms_pagination);
                    ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
