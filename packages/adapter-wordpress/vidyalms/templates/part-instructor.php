<?php

declare(strict_types=1);

/**
 * VidyaLMS: the instructor workbench mount, as a template part.
 *
 * One mount div carrying the data-vy-config contract; the React workbench owns
 * everything inside it. A theme may replace this file with
 * vidyalms/part-instructor.php of its own; it receives the same context array.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string, mixed> $context */
$vidyalmsContext = $context;
?>
<div class="vidyalms vidyalms-instructor">
    <?php if ($vidyalmsContext['signed_in'] !== true) : ?>
        <p>
            <?php esc_html_e('Sign in to reach your teaching workbench.', 'vidyalms'); ?>
            <a class="vidyalms-link" href="<?php echo esc_url((string) $vidyalmsContext['login_url']); ?>">
                <?php esc_html_e('Sign in', 'vidyalms'); ?>
            </a>
        </p>
    <?php else : ?>
        <div id="<?php echo esc_attr((string) $vidyalmsContext['mount_id']); ?>" data-vy-config="<?php echo esc_attr($vidyalmsContext['config_attribute']); ?>">
            <noscript>
                <p>
                    <?php esc_html_e('The teaching workbench needs JavaScript.', 'vidyalms'); ?>
                    <a href="<?php echo esc_url((string) $vidyalmsContext['my_learning_url']); ?>">
                        <?php esc_html_e('My learning', 'vidyalms'); ?>
                    </a>
                </p>
            </noscript>
        </div>
    <?php endif; ?>
</div>
