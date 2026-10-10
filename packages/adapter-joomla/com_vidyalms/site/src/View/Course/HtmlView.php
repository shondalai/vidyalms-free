<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\Course;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Shondalai\VidyaLMS\Core\Application\Progress\MyCourseProgress;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssetContext;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets;
use VidyaLMS\Component\VidyaLMS\Site\Model\CourseModel;
use VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig;

/**
 * The public course page: outline, price or enrolment, and the learner's own
 * progress once they are enrolled.
 *
 * @since 1.0.0
 */
final class HtmlView extends BaseHtmlView
{
    public Course $course;

    /** @var list<\Shondalai\VidyaLMS\Core\Application\Curriculum\CurriculumSection> */
    public array $sections = [];

    /** @var array<string, mixed>|null */
    public ?array $enrolment = null;

    public ?MyCourseProgress $progress = null;

    public float $percent = 0.0;

    public ?int $resumeLessonId = null;

    /** @var array<int, bool> */
    public array $completedLessonIds = [];

    /**
     * The player URL of each free-preview lesson, keyed by lesson id, for a
     * visitor without an enrolment; empty for an enrolled learner.
     *
     * @var array<int, string>
     */
    public array $previewLessonUrls = [];

    public ?CoursePrice $price = null;

    public ?string $purchaseUrl = null;

    public bool $canEnrol = false;

    public bool $isGuest = true;

    /** @var list<array{id: int, name: string, role: string}> */
    public array $instructors = [];

    /** @var list<array{slug: string, title: string}> */
    public array $prerequisites = [];

    public Registry $params;

    public string $formAction = '';

    public string $loginUrl = '';

    public int $itemId = 0;

    /** The quiz lesson the quiz-runner mount boots on; 0 on the course page itself. */
    public int $quizLessonId = 0;

    /** The data-vy-config payload of the quiz-runner mount, not yet HTML-escaped. */
    public string $configJson = '';

    public function display($tpl = null): void
    {
        /** @var CourseModel $model */
        $model  = $this->getModel();
        $course = $model->getCourse();

        if ($course === null) {
            throw new \Exception(Text::_('COM_VIDYALMS_ERROR_COURSE_NOT_FOUND'), 404);
        }

        $app = Factory::getApplication();

        // The quiz-runner handoff: the player links to the course URL plus
        // vy-quiz=<lessonId> (the one host URL the mount contract carries),
        // and that parameter turns this page into the quiz-runner mount.
        $quizLessonId = (int) $app->getInput()->get('vy-quiz', 0, 'int');

        if ($quizLessonId > 0) {
            $this->displayQuizRunner($course, $quizLessonId, $tpl);

            return;
        }

        $this->course         = $course;
        $this->params         = $app->getParams();
        $this->sections       = $model->getSections();
        $this->enrolment      = $model->getEnrolment();
        $this->progress       = $model->getProgress();
        $this->percent        = $model->getProgressPercent();
        $this->resumeLessonId = $model->getResumeLessonId();

        $this->completedLessonIds = $model->getCompletedLessonIds();
        $this->previewLessonUrls  = $this->enrolment === null ? $this->buildPreviewLessonUrls($course) : [];

        $this->price          = $model->getPrice();
        $this->purchaseUrl    = $model->getPurchaseUrl();
        $this->canEnrol       = $model->canOfferSelfEnrolment();
        $this->instructors    = $model->getInstructors();
        $this->prerequisites  = $model->getPrerequisites();
        $this->isGuest        = (bool) ($app->getIdentity()->guest ?? true);
        $this->itemId         = (int) $app->getInput()->get('Itemid', 0, 'int');
        $this->formAction     = Route::_('index.php?option=com_vidyalms');
        $this->loginUrl       = $this->buildLoginUrl();

        $this->prepareDocument();

        parent::display($tpl);
    }

    /**
     * A lesson labelled free preview is only a preview if the label leads
     * somewhere: the player deep link for each one, which the player view
     * opens to a guest whenever the course previews anything. An enrolled
     * learner gets the resume button and the player's own outline instead,
     * so the map stays empty for them.
     *
     * @return array<int, string>
     */
    private function buildPreviewLessonUrls(Course $course): array
    {
        $urls = [];

        foreach ($this->sections as $section) {
            foreach ($section->lessons as $lesson) {
                if ($lesson->freePreview) {
                    $urls[$lesson->id] = Route::_(
                        'index.php?option=com_vidyalms&view=player&slug=' . urlencode($course->slug->value)
                        . '&vy-lesson=' . $lesson->id,
                    );
                }
            }
        }

        return $urls;
    }

    private function buildLoginUrl(): string
    {
        $current = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']);

        return Route::_('index.php?option=com_users&view=login&return=' . base64_encode($current), false);
    }

    /**
     * Renders the quiz-runner mount for one quiz lesson. Taking a quiz is
     * always a signed-in act, so guests are sent to login and brought back;
     * everything else (whether the lesson is a quiz, whether attempts remain)
     * is the core's answer through the REST surface.
     */
    private function displayQuizRunner(Course $course, int $quizLessonId, ?string $tpl): void
    {
        $app = Factory::getApplication();

        if ((bool) ($app->getIdentity()->guest ?? true)) {
            $app->enqueueMessage(Text::_('COM_VIDYALMS_PLAYER_LOGIN_REQUIRED'), 'notice');
            $app->redirect($this->buildLoginUrl());

            return;
        }

        $this->course       = $course;
        $this->quizLessonId = $quizLessonId;
        $this->configJson   = MountConfig::encode($course, $quizLessonId);

        // The config carries the learner's own CSRF token: the login guard
        // above keeps the core page cache away (it only stores guest pages),
        // but any other page cache must skip this response too.
        MountConfig::forbidPageCache();

        // The free bundle, then anything another extension contributed to the
        // quiz-runner mount. A Pro question type's renderer arrives here and
        // nowhere else; see Administrator\Service\MountAssets.
        MountAssets::attach(
            $this->getDocument()->getWebAssetManager(),
            MountAssetContext::site(MountAssets::MOUNT_QUIZ_RUNNER),
        );

        $this->setDocumentTitle($course->title);
        $this->setLayout('quiz');

        parent::display($tpl);
    }

    private function prepareDocument(): void
    {
        $this->setDocumentTitle($this->course->title);

        $summary = trim((string) ($this->course->summary ?? ''));

        if ($summary !== '') {
            $this->getDocument()->setDescription($summary);
        }
    }
}
