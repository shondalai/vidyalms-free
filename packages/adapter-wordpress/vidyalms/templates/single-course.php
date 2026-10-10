<?php

declare(strict_types=1);

/**
 * VidyaLMS: one course page.
 *
 * The post supplies the permalink; every fact on the page comes from the core,
 * keyed by the post slug, which sync keeps equal to the course slug.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\LearnerApps;
use Shondalai\VidyaLMS\WordPress\Content\Templates;
use Shondalai\VidyaLMS\WordPress\Content\LearnerLayout;
use Shondalai\VidyaLMS\WordPress\Content\DocumentTitle;

defined( 'ABSPATH' ) || exit;

$vidyalmsPost    = get_queried_object();
$vidyalmsSlug    = $vidyalmsPost instanceof WP_Post ? (string) $vidyalmsPost->post_name : '';
$vidyalmsContext = Templates::courseContext($vidyalmsSlug);
$vidyalmsCourse  = $vidyalmsContext['course'];
if ($vidyalmsCourse === null) {
    status_header(404);
    nocache_headers();
}
$vidyalmsTitle   = $vidyalmsCourse !== null ? (string) $vidyalmsCourse->title : __('Course unavailable', 'vidyalms');
// A denied core read must not fall back to the published permalink shell's title.
DocumentTitle::register($vidyalmsTitle);

// The quiz-runner handoff: the player links to this permalink plus
// vy-quiz=<lessonId>; that parameter mounts the quiz runner instead of the
// course page. LearnerApps::guard() has already sent guests to login.
$vidyalmsQuizLessonId = LearnerApps::quizLessonId();

if ($vidyalmsQuizLessonId > 0 && $vidyalmsCourse !== null) {
    $vidyalmsQuiz = LearnerApps::quizContext($vidyalmsCourse);

    Templates::pageHeader();
    LearnerLayout::begin('mylearning');
    ?>
    <main id="vidyalms-content" class="vidyalms vidyalms-page vidyalms-page-quiz">
        <div id="vidyalms-quiz-runner" data-vy-config="<?php echo esc_attr($vidyalmsQuiz['config_attribute']); ?>">
            <noscript>
                <p>
                    <?php esc_html_e('The quiz runner needs JavaScript.', 'vidyalms'); ?>
                    <a href="<?php echo esc_url($vidyalmsQuiz['course_url']); ?>">
                        <?php esc_html_e('Back to the course page', 'vidyalms'); ?>
                    </a>
                </p>
            </noscript>
        </div>
    </main>
    <?php
    LearnerLayout::end();
    Templates::pageFooter();

    return;
}

Templates::pageHeader();
LearnerLayout::begin('catalogue');
?>
<main id="vidyalms-content" class="vidyalms vidyalms-page vidyalms-page-course">
    <header class="vidyalms-page-header">
        <h1 class="vidyalms-page-title"><?php echo esc_html($vidyalmsTitle); ?></h1>
    </header>

    <?php Templates::output('part-course', $vidyalmsContext); ?>
</main>
<?php
LearnerLayout::end();
Templates::pageFooter();
