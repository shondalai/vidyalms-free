<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\View\Import;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use VidyaLMS\Component\VidyaLMS\Administrator\Helper\VidyaLmsHelper;
use VidyaLMS\Component\VidyaLMS\Administrator\Model\ImportModel;

defined('_JEXEC') or die;

/**
 * The migration screen: what can be imported, the two ways to start one, and
 * the history of every run.
 *
 * The screen is honest about what an import is. A real run is queued and
 * happens in the background, so the list polls rather than pretending the page
 * load is the whole story; a dry run answers inline. Warning notes are pulled
 * out of the log and shown on the row, because they are how a migrating site
 * learns what did not come across.
 *
 * @since 1.0.0
 */
final class HtmlView extends BaseHtmlView
{
    /** @var list<array{key: string, label: string, extensions: list<string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: list<array{key: string, label: string, kind: string, help: string, default: string, choices: list<array{value: string, label: string}>}>}> */
    protected array $importers = [];

    /** @var list<ImportJob> */
    protected array $jobs = [];

    /** @var list<string> */
    protected array $queueWarnings = [];

    /** @var list<int> */
    protected array $pollIds = [];

    protected bool $authorised = false;

    protected int $maxUploadBytes = 0;

    protected string $maxUploadLabel = '';

    protected int $highlight = 0;

    protected int $page = 1;

    protected int $pages = 1;

    protected Registry $canDo;

    /** The core's clock, which is what decides whether a run has stopped responding. */
    protected \DateTimeImmutable $now;

    /**
     * @param string|null $tpl
     */
    public function display($tpl = null): void
    {
        /** @var ImportModel $model */
        $model = $this->getModel();

        $this->authorised = $model->isAuthorised();
        $this->canDo      = VidyaLmsHelper::getActions();
        $this->now        = VidyaLmsHelper::now();

        if ($this->authorised) {
            $this->importers      = $model->getImporters();
            $this->jobs           = $model->getJobs();
            $this->queueWarnings  = $model->getQueueWarnings();
            $this->pollIds        = $model->getUnfinishedJobIds();
            $this->maxUploadBytes = $model->getMaxUploadBytes();
            $this->maxUploadLabel = ImportModel::formatBytes($this->maxUploadBytes);
            $this->highlight      = $model->getHighlightedJobId();
            $this->page           = $model->getPage();
            $this->pages          = (int) max(1, ceil($model->getTotal() / ImportModel::PER_PAGE));
        }

        $this->addToolbar();
        $this->attachPolling();

        parent::display($tpl);
    }

    /**
     * The warning and error notes of one job.
     *
     * The core parses the log, and the level comes back as data rather than
     * as text this screen matched a prefix against. That is the whole point:
     * a note quotes course and lesson titles the source wrote, and a title
     * holding a line break and "[error]" would otherwise put a line into this
     * list that VidyaLMS never emitted. ImportNote flattens every note to one
     * line when it is written, and parseLog() is the reader that pairs with
     * that writer.
     *
     * @return list<ImportNote>
     */
    public static function troubleLines(?string $log): array
    {
        return array_values(array_filter(
            ImportNote::parseLog($log),
            static fn (ImportNote $note): bool => $note->level !== ImportNote::INFO
        ));
    }

    /**
     * The Bootstrap class for one note's level, so an error reads as an error
     * without the level ever being taken from the note's text.
     */
    public static function noteClass(string $level): string
    {
        return $level === ImportNote::ERROR ? 'text-danger' : 'text-warning-emphasis';
    }

    /**
     * The totals in the shared vocabulary's order first, then whatever else
     * the importer counted. A zero is dropped: an admin reading a migration
     * wants what came across, not a row of noughts.
     *
     * @param array<string, mixed>|null $totals
     *
     * @return array<string, int>
     */
    public static function orderedTotals(?array $totals): array
    {
        if ($totals === null || $totals === []) {
            return [];
        }

        $order   = ['courses', 'modules', 'lessons', 'quizzes', 'questions', 'enrolments', 'learners', 'skipped'];
        $ordered = [];

        foreach ($order as $key) {
            if (isset($totals[$key]) && is_numeric($totals[$key]) && (int) $totals[$key] !== 0) {
                $ordered[$key] = (int) $totals[$key];
            }
        }

        /** @var mixed $value */
        foreach ($totals as $key => $value) {
            if (!\is_string($key) || isset($ordered[$key]) || \in_array($key, $order, true)) {
                continue;
            }

            if (is_numeric($value) && (int) $value !== 0) {
                $ordered[$key] = (int) $value;
            }
        }

        return $ordered;
    }

    /**
     * The poll endpoint and the ids to watch, handed to the browser as script
     * options rather than written into the markup.
     */
    private function attachPolling(): void
    {
        $document = $this->getDocument();

        // A raw or JSON response has no asset manager to attach to; only an
        // HTML render polls.
        if (!$document instanceof HtmlDocument) {
            return;
        }

        $document->getWebAssetManager()->useScript('com_vidyalms.import');
        $document->addScriptOptions('com_vidyalms.import', [
            'endpoint'       => Uri::base(true) . '/index.php?option=com_vidyalms&task=import.poll&format=json',
            'token'          => Session::getFormToken(),
            'ids'            => $this->pollIds,
            'interval'       => 4000,
            'troubleHeading' => Text::_('COM_VIDYALMS_IMPORT_TROUBLE_COUNT'),
        ]);
    }

    private function addToolbar(): void
    {
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_VIDYALMS_MANAGER_IMPORT'), 'upload vidyalms');

        $user = $this->getCurrentUser();

        if ($user->authorise('core.admin', VidyaLmsHelper::COMPONENT) || $user->authorise('core.options', VidyaLmsHelper::COMPONENT)) {
            $toolbar->preferences(VidyaLmsHelper::COMPONENT);
        }
    }
}
