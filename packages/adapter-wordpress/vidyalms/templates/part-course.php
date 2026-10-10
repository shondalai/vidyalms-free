<?php

declare(strict_types=1);

/**
 * VidyaLMS: one course, everything below the page title.
 *
 * The curriculum shows module and lesson titles, types and lengths. It never
 * shows a content payload: the core deliberately keeps lesson content out of a
 * curriculum listing so a page like this cannot leak it to a visitor who has no
 * access. A free-preview lesson's title links into the player for a visitor
 * without an enrolment, through the URLs Templates::courseContext() built.
 *
 * @var array<string, mixed> $context Built by Templates::courseContext().
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\Rewrites;
use Shondalai\VidyaLMS\WordPress\Content\Templates;

defined( 'ABSPATH' ) || exit;

$vidyalms_context = isset($context) && \is_array($context) ? $context : [];

$vidyalms_available     = (bool) ($vidyalms_context['available'] ?? false);
$vidyalms_found         = (bool) ($vidyalms_context['found'] ?? false);
$vidyalms_course        = $vidyalms_context['course'] ?? null;
$vidyalms_sections      = (array) ($vidyalms_context['sections'] ?? []);
$vidyalms_instructors   = (array) ($vidyalms_context['instructors'] ?? []);
$vidyalms_prerequisites = (array) ($vidyalms_context['prerequisites'] ?? []);
$vidyalms_is_guest       = (bool) ($vidyalms_context['is_guest'] ?? true);
$vidyalms_enrolment     = $vidyalms_context['enrolment'] ?? null;
$vidyalms_percent       = (float) ($vidyalms_context['percent'] ?? 0.0);
$vidyalms_completed     = (array) ($vidyalms_context['completed_lessons'] ?? []);
$vidyalms_resume_lesson  = $vidyalms_context['resume_lesson_id'] ?? null;
$vidyalms_preview_urls   = (array) ($vidyalms_context['preview_urls'] ?? []);
$vidyalms_price_label    = (string) ($vidyalms_context['price_label'] ?? '');
$vidyalms_purchase_url   = (string) ($vidyalms_context['purchase_url'] ?? '');
$vidyalms_can_enrol      = (bool) ($vidyalms_context['can_enrol'] ?? false);
$vidyalms_login_url      = (string) ($vidyalms_context['login_url'] ?? '');
$vidyalms_course_url     = (string) ($vidyalms_context['course_url'] ?? '');
$vidyalms_catalogue_url  = (string) ($vidyalms_context['catalogue_url'] ?? '');
$vidyalms_my_learning_url = (string) ($vidyalms_context['my_learning_url'] ?? '');
$vidyalms_notice        = (string) ($vidyalms_context['notice'] ?? '');
$vidyalms_notice_type    = (string) ($vidyalms_context['notice_type'] ?? '');
$vidyalms_progress_id    = wp_unique_id('vidyalms-progress-');
?>
<div class="vidyalms vidyalms-course">
    <?php if ($vidyalms_notice !== '') : ?>
        <p
            class="vidyalms-notice vidyalms-notice-<?php echo esc_attr($vidyalms_notice_type !== '' ? $vidyalms_notice_type : 'info'); ?>"
            role="status">
            <?php echo esc_html($vidyalms_notice); ?>
        </p>
    <?php endif; ?>

    <?php if (!$vidyalms_available) : ?>
        <p class="vidyalms-notice vidyalms-notice-error">
            <?php esc_html_e('Course information is not available right now.', 'vidyalms'); ?>
        </p>
    <?php elseif (!$vidyalms_found || $vidyalms_course === null) : ?>
        <p class="vidyalms-empty"><?php esc_html_e('This course is no longer available.', 'vidyalms'); ?></p>
        <p>
            <a class="vidyalms-button" href="<?php echo esc_url($vidyalms_catalogue_url); ?>">
                <?php esc_html_e('Browse the catalogue', 'vidyalms'); ?>
            </a>
        </p>
    <?php else : ?>
        <?php
        $vidyalms_level       = (string) ($vidyalms_course->level ?? '');
        $vidyalms_summary     = (string) ($vidyalms_course->summary ?? '');
        $vidyalms_description = (string) ($vidyalms_course->description ?? '');
        $vidyalms_image       = Templates::courseImage((string) ($vidyalms_course->image ?? ''), 'vidyalms-course-image');
        $vidyalms_is_free      = $vidyalms_course->priceMode === 'free';
        ?>
        <div class="vidyalms-course-layout">
            <article class="vidyalms-course-main">
                <p class="vidyalms-card-meta">
                    <?php if ($vidyalms_level !== '') : ?>
                        <span class="vidyalms-badge">
                            <span class="vidyalms-badge-label"><?php esc_html_e('Level', 'vidyalms'); ?></span>
                            <?php echo esc_html(Templates::levelLabel($vidyalms_level)); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($vidyalms_is_free) : ?>
                        <span class="vidyalms-badge vidyalms-badge-free"><?php esc_html_e('Free', 'vidyalms'); ?></span>
                    <?php elseif ($vidyalms_price_label !== '') : ?>
                        <span class="vidyalms-badge vidyalms-badge-price">
                            <span class="vidyalms-badge-label"><?php esc_html_e('Price', 'vidyalms'); ?></span>
                            <?php echo esc_html($vidyalms_price_label); ?>
                        </span>
                    <?php endif; ?>
                </p>

                <?php if ($vidyalms_summary !== '') : ?>
                    <p class="vidyalms-course-summary"><?php echo esc_html($vidyalms_summary); ?></p>
                <?php endif; ?>

                <?php if ($vidyalms_image !== '') : ?>
                    <div class="vidyalms-course-media">
                        <?php
                        echo wp_kses_post($vidyalms_image);
                        ?>
                    </div>
                <?php endif; ?>

                <?php if ($vidyalms_description !== '') : ?>
                    <section class="vidyalms-course-description" aria-labelledby="vidyalms-about">
                        <h2 id="vidyalms-about"><?php esc_html_e('About this course', 'vidyalms'); ?></h2>
                        <?php echo wp_kses_post($vidyalms_description); ?>
                    </section>
                <?php endif; ?>

                <?php if ($vidyalms_instructors !== []) : ?>
                    <section class="vidyalms-course-instructors" aria-labelledby="vidyalms-instructors">
                        <h2 id="vidyalms-instructors"><?php esc_html_e('Instructors', 'vidyalms'); ?></h2>
                        <ul class="vidyalms-list">
                            <?php foreach ($vidyalms_instructors as $vidyalms_instructor) : ?>
                                <li><?php echo esc_html((string) ($vidyalms_instructor['name'] ?? '')); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <?php if ($vidyalms_prerequisites !== []) : ?>
                    <section class="vidyalms-course-prerequisites" aria-labelledby="vidyalms-prerequisites">
                        <h2 id="vidyalms-prerequisites"><?php esc_html_e('Prerequisites', 'vidyalms'); ?></h2>
                        <ul class="vidyalms-list">
                            <?php foreach ($vidyalms_prerequisites as $vidyalms_prerequisite) : ?>
                                <li>
                                    <a href="<?php echo esc_url((string) ($vidyalms_prerequisite['url'] ?? '')); ?>">
                                        <?php echo esc_html((string) ($vidyalms_prerequisite['title'] ?? '')); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <section class="vidyalms-curriculum" id="vidyalms-curriculum" aria-labelledby="vidyalms-curriculum-heading">
                    <h2 id="vidyalms-curriculum-heading"><?php esc_html_e('Curriculum', 'vidyalms'); ?></h2>

                    <?php if ($vidyalms_sections === []) : ?>
                        <p class="vidyalms-empty"><?php esc_html_e('The curriculum is not published yet.', 'vidyalms'); ?></p>
                    <?php else : ?>
                        <?php foreach ($vidyalms_sections as $vidyalms_index => $vidyalms_section) : ?>
                            <?php $vidyalms_module_id = 'vidyalms-module-' . (int) $vidyalms_section->module->id; ?>
                            <section class="vidyalms-module" aria-labelledby="<?php echo esc_attr($vidyalms_module_id); ?>">
                                <h3 class="vidyalms-module-title" id="<?php echo esc_attr($vidyalms_module_id); ?>">
                                    <span class="vidyalms-module-number">
                                        <?php
                                        printf(
                                            /* translators: %s: module number within the course. */
                                            esc_html__('Module %s', 'vidyalms'),
                                            esc_html(number_format_i18n((int) $vidyalms_index + 1)),
                                        );
                                        ?>
                                    </span>
                                    <span class="vidyalms-module-name"><?php echo esc_html((string) $vidyalms_section->module->title); ?></span>
                                </h3>

                                <?php if ((string) ($vidyalms_section->module->summary ?? '') !== '') : ?>
                                    <p class="vidyalms-module-summary">
                                        <?php echo esc_html((string) $vidyalms_section->module->summary); ?>
                                    </p>
                                <?php endif; ?>

                                <ol class="vidyalms-lessons">
                                    <?php foreach ($vidyalms_section->lessons as $vidyalms_lesson) : ?>
                                        <?php
                                        $vidyalms_length     = Templates::duration($vidyalms_lesson->durationSeconds);
                                        $vidyalms_is_done     = isset($vidyalms_completed[$vidyalms_lesson->id]);
                                        $vidyalms_lesson_id   = 'vidyalms-lesson-' . (int) $vidyalms_lesson->id;
                                        $vidyalms_preview_url = (string) ($vidyalms_preview_urls[(int) $vidyalms_lesson->id] ?? '');
                                        ?>
                                        <li
                                            class="vidyalms-lesson<?php echo $vidyalms_is_done ? ' vidyalms-lesson-complete' : ''; ?>"
                                            id="<?php echo esc_attr($vidyalms_lesson_id); ?>">
                                            <?php if ($vidyalms_preview_url !== '') : ?>
                                                <a
                                                    class="vidyalms-lesson-title vidyalms-lesson-preview"
                                                    href="<?php echo esc_url($vidyalms_preview_url); ?>"><?php echo esc_html((string) $vidyalms_lesson->title); ?></a>
                                            <?php else : ?>
                                                <span class="vidyalms-lesson-title"><?php echo esc_html((string) $vidyalms_lesson->title); ?></span>
                                            <?php endif; ?>
                                            <span class="vidyalms-lesson-meta">
                                                <span class="vidyalms-lesson-type">
                                                    <?php echo esc_html(Templates::lessonTypeLabel((string) $vidyalms_lesson->type)); ?>
                                                </span>

                                                <?php if ($vidyalms_length !== '') : ?>
                                                    <span class="vidyalms-lesson-duration"><?php echo esc_html($vidyalms_length); ?></span>
                                                <?php endif; ?>

                                                <?php if ($vidyalms_lesson->freePreview) : ?>
                                                    <span class="vidyalms-badge vidyalms-badge-preview">
                                                        <?php esc_html_e('Free preview', 'vidyalms'); ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($vidyalms_is_done) : ?>
                                                    <span class="vidyalms-lesson-state">
                                                        <?php esc_html_e('Completed', 'vidyalms'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </section>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </article>

            <aside class="vidyalms-course-aside" aria-labelledby="vidyalms-enrolment">
                <h2 id="vidyalms-enrolment" class="vidyalms-aside-title"><?php esc_html_e('Enrolment', 'vidyalms'); ?></h2>

                <?php if ($vidyalms_enrolment !== null) : ?>
                    <p class="vidyalms-enrolled"><?php esc_html_e('You are enrolled on this course.', 'vidyalms'); ?></p>

                    <div class="vidyalms-progress">
                        <label class="vidyalms-label" for="<?php echo esc_attr($vidyalms_progress_id); ?>">
                            <?php esc_html_e('Your progress', 'vidyalms'); ?>
                        </label>
                        <progress
                            class="vidyalms-progress-bar"
                            id="<?php echo esc_attr($vidyalms_progress_id); ?>"
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

                    <?php
                    // The interactive player owns the learning surface; the
                    // resume lesson rides along as the deep link when set.
                    $vidyalms_learn_url = Rewrites::learnUrl(
                        $vidyalms_course !== null ? $vidyalms_course->slug->value : '',
                        $vidyalms_resume_lesson !== null ? (int) $vidyalms_resume_lesson : null,
                    );
                    ?>
                    <p>
                        <a class="vidyalms-button" href="<?php echo esc_url($vidyalms_learn_url); ?>">
                            <?php if ($vidyalms_resume_lesson !== null) : ?>
                                <?php esc_html_e('Resume where you left off', 'vidyalms'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Start learning', 'vidyalms'); ?>
                            <?php endif; ?>
                        </a>
                    </p>

                    <p>
                        <a class="vidyalms-link" href="<?php echo esc_url($vidyalms_my_learning_url); ?>">
                            <?php esc_html_e('Go to my learning', 'vidyalms'); ?>
                        </a>
                    </p>
                <?php elseif ($vidyalms_is_guest) : ?>
                    <p class="vidyalms-notice vidyalms-notice-info">
                        <?php esc_html_e('Sign in to enrol on this course.', 'vidyalms'); ?>
                    </p>
                    <p>
                        <a class="vidyalms-button" href="<?php echo esc_url($vidyalms_login_url); ?>">
                            <?php esc_html_e('Sign in', 'vidyalms'); ?>
                        </a>
                    </p>
                <?php elseif ($vidyalms_can_enrol) : ?>
                    <form class="vidyalms-enrol-form" method="post" action="<?php echo esc_url($vidyalms_course_url); ?>">
                        <input type="hidden" name="vidyalms_action" value="self_enrol">
                        <input type="hidden" name="vidyalms_course_id" value="<?php echo esc_attr((string) (int) $vidyalms_course->id); ?>">
                        <input type="hidden" name="vidyalms_course_slug" value="<?php echo esc_attr($vidyalms_course->slug->value); ?>">
                        <?php
                        echo wp_kses(Templates::enrolNonceField((int) $vidyalms_course->id), [
                            'input' => ['type' => true, 'id' => true, 'name' => true, 'value' => true],
                        ]);
                        ?>
                        <button class="vidyalms-button" type="submit"><?php esc_html_e('Enrol on this course', 'vidyalms'); ?></button>
                    </form>
                <?php elseif ($vidyalms_purchase_url !== '') : ?>
                    <p>
                        <a class="vidyalms-button" href="<?php echo esc_url($vidyalms_purchase_url); ?>">
                            <?php esc_html_e('Buy this course', 'vidyalms'); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p class="vidyalms-notice vidyalms-notice-info">
                        <?php esc_html_e('Enrolment is closed for this course.', 'vidyalms'); ?>
                    </p>
                <?php endif; ?>
            </aside>
        </div>
    <?php endif; ?>
</div>
