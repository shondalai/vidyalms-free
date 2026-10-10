<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use VidyaLMS\Component\VidyaLMS\Administrator\Helper\VidyaLmsHelper;
use VidyaLMS\Component\VidyaLMS\Administrator\Model\ImportModel;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ImportSources;
use VidyaLMS\Component\VidyaLMS\Administrator\View\Import\HtmlView;

defined('_JEXEC') or die;

/**
 * The Import screen's write and poll tasks.
 *
 * Five tasks, and each one checks the session token and the core's MANAGE
 * capability before anything else. The capability check here is not the gate
 * (every use case checks it again inside itself); it is what turns a refusal
 * into a message on the screen instead of an exception page.
 *
 * Nothing is undone. A run that failed after eleven of twenty lessons has
 * created eleven lessons, and running the same source again is the recovery:
 * the import map turns the second run into an update. There is deliberately no
 * "clean up failed import" task here to delete them.
 *
 * A run whose process died leaves a row that says "running" and never changes
 * again. The screen shows that as stopped responding, and abandon() and
 * retry() are the two ways out of it. Both are the operator's decision: the
 * core reports the presumption and never acts on it.
 *
 * @since 1.0.0
 */
final class ImportController extends BaseController
{
    private const SCREEN_URL = 'index.php?option=com_vidyalms&view=import';

    /** Secure multipart preparation for the SPA; applying remains in the idempotent core API. */
    public function workspace(): void
    {
        $this->checkToken();
        $this->app->allowCache(false);
        $status = 200;
        $payload = [];
        if (!VidyaLmsHelper::can(Capability::MANAGE)) {
            $status = 403;
            $payload = ['error' => Text::_('COM_VIDYALMS_IMPORT_ERROR_NO_MANAGE')];
        } else {
            try {
                /** @var ImportModel $model */
                $model = $this->getModel('Import');
                $operation = (string) $this->input->post->get('operation', '', 'word');
                if ($operation === 'catalogue') {
                    $payload = ['importers' => $model->getImporters(), 'max_bytes' => $model->getMaxUploadBytes()];
                } elseif ($operation === 'abandon') {
                    $job = $model->getJob($this->requestedJobId());
                    if (!$job->isFinished()) {
                        $job = VidyaLmsHelper::kernel()->abandonImportJob->execute(VidyaLmsHelper::principal(), $job->id);
                    }
                    $payload = ['id' => $job->id];
                } elseif ($operation === 'preview') {
                    $key = (string) $this->input->post->get('importer', '', 'string');
                    if (!$model->hasImporter($key)) {
                        throw new \RuntimeException(Text::_('COM_VIDYALMS_IMPORT_ERROR_UNKNOWN_IMPORTER'));
                    }
                    $source = $this->resolveSource($model, $key);
                    $job = $model->start($key, $source, $this->importerOptions($key), true);
                    $payload = ['id' => $job->id];
                } else {
                    $status = 400;
                    $payload = ['error' => Text::_('COM_VIDYALMS_IMPORT_INVALID_REQUEST')];
                }
            } catch (DomainException | \RuntimeException $failure) {
                $status = 422;
                $payload = ['error' => $failure->getMessage()];
            }
        }
        http_response_code($status);
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->sendHeaders();
        echo json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $this->app->close();
    }

    /**
     * Queue a real import. Answers immediately with a pending job: a Moodle
     * backup takes minutes, and the request that asked for it would time out
     * long before the import finished. The task plugin runs it.
     */
    public function run(): void
    {
        $this->start(false);
    }

    /**
     * Run a dry run inline. It writes nothing, neither through a use case nor
     * into the import map, and ends as "previewed", so running the same source
     * for real afterwards is still a first run.
     */
    public function preview(): void
    {
        $this->start(true);
    }

    /**
     * Ends a run that stopped responding, because the operator said so.
     *
     * The core refuses this while the job is still writing, so a screen that
     * has been open for an hour cannot end a run that recovered in the
     * meantime. Nothing it created is removed.
     */
    public function abandon(): void
    {
        $this->checkToken();

        if (!VidyaLmsHelper::can(Capability::MANAGE)) {
            $this->fail(Text::_('COM_VIDYALMS_IMPORT_ERROR_NO_MANAGE'));

            return;
        }

        try {
            $job = VidyaLmsHelper::kernel()->abandonImportJob->execute(
                VidyaLmsHelper::principal(),
                $this->requestedJobId()
            );
        } catch (DomainException $failure) {
            $this->fail($failure->getMessage());

            return;
        }

        $this->setMessage(Text::sprintf('COM_VIDYALMS_IMPORT_ABANDONED', $job->id), 'success');
        $this->setRedirect(Route::_(self::SCREEN_URL . '&highlight=' . $job->id, false));
    }

    /**
     * Queues the same source again.
     *
     * A run that stopped responding is ended first, so the retry does not
     * leave a row saying "running" for ever beside its replacement. Nothing is
     * undone either way: the import map turns the second run into an update of
     * what the first one created.
     */
    public function retry(): void
    {
        $this->checkToken();

        if (!VidyaLmsHelper::can(Capability::MANAGE)) {
            $this->fail(Text::_('COM_VIDYALMS_IMPORT_ERROR_NO_MANAGE'));

            return;
        }

        /** @var ImportModel $model */
        $model     = $this->getModel('Import');
        $principal = VidyaLmsHelper::principal();
        $kernel    = VidyaLmsHelper::kernel();

        try {
            $job = $model->getJob($this->requestedJobId());

            // The prune routine removes an uploaded source once the retention
            // window has passed and clears the path with it, so a job old
            // enough has nothing left to run. Saying so is the whole point of
            // clearing the path rather than leaving one that points at bytes
            // that are gone.
            if ($job->sourcePath === null || $job->sourcePath === '') {
                $this->fail(Text::_('COM_VIDYALMS_IMPORT_ERROR_SOURCE_GONE'));

                return;
            }

            if (!$job->isFinished()) {
                $job = $kernel->abandonImportJob->execute($principal, $job->id);
            }

            $queued = $kernel->queueImportJob->execute($principal, $job->importer, $job->sourcePath, $job->options);
        } catch (DomainException $failure) {
            $this->fail($failure->getMessage());

            return;
        }

        $this->setMessage(Text::sprintf('COM_VIDYALMS_IMPORT_QUEUED', $queued->id), 'success');
        $this->setRedirect(Route::_(self::SCREEN_URL . '&highlight=' . $queued->id, false));
    }

    /**
     * One job as JSON, for the screen that polls while an import runs. The log
     * a job carries can name courses and people, so this needs the same token
     * and the same capability as starting one.
     */
    public function poll(): void
    {
        $this->checkToken('request');

        $this->app->allowCache(false);
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);

        $this->app->sendHeaders();
        echo json_encode($this->pollPayload(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $this->app->close();
    }

    /**
     * @return array<string, mixed>
     */
    private function pollPayload(): array
    {
        if (!VidyaLmsHelper::can(Capability::MANAGE)) {
            return ['error' => Text::_('COM_VIDYALMS_IMPORT_ERROR_NO_MANAGE')];
        }

        /** @var ImportModel $model */
        $model = $this->getModel('Import');
        $ids   = array_slice($this->requestedJobIds(), 0, 20);
        $jobs  = [];

        $now = VidyaLmsHelper::now();

        foreach ($ids as $id) {
            try {
                $jobs[] = self::jobPayload($model->getJob($id), $now);
            } catch (EntityNotFoundException) {
                // A job pruned between two polls is not an error worth a
                // banner; it simply stops being reported.
                continue;
            } catch (DomainException $failure) {
                return ['error' => $failure->getMessage()];
            }
        }

        return ['jobs' => $jobs];
    }

    private function requestedJobId(): int
    {
        return max(0, (int) $this->input->post->get('job_id', 0, 'int'));
    }

    /**
     * @return list<int>
     */
    private function requestedJobIds(): array
    {
        $raw = (string) $this->input->get('ids', '', 'string');
        $ids = [];

        foreach (explode(',', $raw) as $candidate) {
            $id = (int) trim($candidate);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The job as the screen renders it. "source" rather than "source_path", to
     * match the wire field the /v1/import routes use.
     *
     * @return array<string, mixed>
     */
    private static function jobPayload(ImportJob $job, \DateTimeImmutable $now): array
    {
        $stalled = $job->hasStalled($now);

        return [
            'id'          => $job->id,
            'importer'    => $job->importer,
            'status'      => $job->status,
            'status_text' => Text::_(VidyaLmsHelper::importStatusLabel($job->status, $stalled)),
            'badge'       => VidyaLmsHelper::importStatusBadge($job->status, $stalled),
            'source'      => $job->sourcePath,
            'dry_run'     => $job->isDryRun(),
            'finished'    => $job->isFinished(),
            // A presumption about a row that stopped moving, never the stored
            // status: the screen stops polling and offers the operator a way
            // out, and nothing rewrites the job behind their back.
            'stalled'     => $stalled,
            'heartbeat'   => $job->heartbeatAt()?->format('Y-m-d H:i:s'),
            'totals'      => $job->totals ?? [],
            'log'         => $job->log ?? '',
            // The notes carry their level as data, so the polling script
            // shows a warning because the core recorded one and not because
            // an imported title starts with "[warning]".
            'notes'       => array_map(
                static fn (ImportNote $note): array => [
                    'level'   => $note->level,
                    'message' => $note->message,
                    'label'   => Text::_(VidyaLmsHelper::importNoteLabel($note->level)),
                    'class'   => HtmlView::noteClass($note->level),
                ],
                HtmlView::troubleLines($job->log)
            ),
            'finished_at' => $job->finishedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The one path both buttons take. The source is either an upload stored in
     * the private area or the "cms-db:" scheme, per the core's source
     * convention; nothing else is offered, because nothing else exists.
     */
    private function start(bool $dryRun): void
    {
        $this->checkToken();

        if (!VidyaLmsHelper::can(Capability::MANAGE)) {
            $this->fail(Text::_('COM_VIDYALMS_IMPORT_ERROR_NO_MANAGE'));

            return;
        }

        /** @var ImportModel $model */
        $model       = $this->getModel('Import');
        $importerKey = (string) $this->input->post->get('importer', '', 'string');

        if (!$model->hasImporter($importerKey)) {
            $this->fail(Text::_('COM_VIDYALMS_IMPORT_ERROR_UNKNOWN_IMPORTER'));

            return;
        }

        try {
            $source = $this->resolveSource($model, $importerKey);
            $job    = $model->start($importerKey, $source, $this->importerOptions($importerKey), $dryRun);
        } catch (DomainException $failure) {
            $this->fail($failure->getMessage());

            return;
        } catch (\RuntimeException $failure) {
            $this->fail($failure->getMessage());

            return;
        }

        $this->setMessage($this->startedMessage($job), $job->status === ImportJob::STATUS_FAILED ? 'error' : 'success');
        $this->setRedirect(Route::_(self::SCREEN_URL . '&highlight=' . $job->id, false));
    }

    /**
     * A database importer is started with no file at all; every other source
     * is an upload this adapter validates and stores before the job exists.
     */
    private function resolveSource(ImportModel $model, string $importerKey): string
    {
        $descriptor = $model->getImporter($importerKey);
        $mode       = (string) $this->input->post->get('source_mode', 'file', 'word');

        // An importer with only one source shape has no choice to make, and a
        // browser with scripting off cannot hide the control that does not
        // apply. Taking the only shape it offers beats an error message that
        // asks for a file it would refuse.
        if ($descriptor !== null && !$descriptor['accepts_file'] && $descriptor['accepts_database']) {
            $mode = 'database';
        }

        if ($mode === 'database') {
            if ($descriptor === null || !$descriptor['accepts_database']) {
                throw new \RuntimeException(Text::_('COM_VIDYALMS_IMPORT_ERROR_NO_DATABASE_SOURCE'));
            }

            return ImportSources::databaseSource(
                (string) $this->input->post->get('selector', '', 'string'),
                // The scheme the chosen importer answered to, not the literal
                // "cms-db:": a LearnDash run composes "learndash-db:".
                $descriptor['database_scheme']
            );
        }

        /** @var array<string, mixed>|null $file */
        $file = $this->input->files->get('import_file', null, 'raw');

        if (!\is_array($file)) {
            throw new \RuntimeException(Text::_('COM_VIDYALMS_IMPORT_ERROR_UPLOAD_MISSING'));
        }

        return $model->storeUpload($file, $importerKey);
    }

    /**
     * Importer options arrive as a free key/value map, because their meaning
     * belongs to the importer that declares them.
     *
     * What the form posts is strings, and an importer reading
     * ($options['enrolments'] ?? false) === true would read the string "1" as
     * false and quietly do the opposite of what the operator ticked. So the
     * options an importer declared are read back through
     * ImportOption::read(), which is in the core precisely so that this form
     * and the WordPress one cannot mean two different things. Anything the
     * importer did not declare still passes through as a scalar, because an
     * importer may read an option it chose not to draw.
     *
     * Only "dry_run" is stripped, there and here: that one is decided by
     * which button was pressed.
     *
     * @return array<string, mixed>
     */
    private function importerOptions(string $importerKey): array
    {
        /** @var mixed $raw */
        $raw = $this->input->post->get('options', [], 'array');

        if (!\is_array($raw)) {
            $raw = [];
        }

        $submitted = [];

        /** @var mixed $value */
        foreach ($raw as $name => $value) {
            $name = trim((string) $name);

            if ($name === '' || $name === ImportOption::RESERVED_KEY || \is_array($value)) {
                continue;
            }

            $submitted[$name] = \is_scalar($value) ? $value : null;
        }

        return ImportOption::read($this->declaredOptions($importerKey), $submitted);
    }

    /**
     * What the chosen importer says it accepts, or nothing at all when it
     * declares nothing, is not registered, or answers with a throw. A screen
     * that could not read a declaration still starts the import.
     *
     * @return list<ImportOption>
     */
    private function declaredOptions(string $importerKey): array
    {
        try {
            $importer = VidyaLmsHelper::kernel()->registries->importers->get($importerKey);
        } catch (\Throwable) {
            return [];
        }

        if (!$importer instanceof ConfigurableImporterInterface) {
            return [];
        }

        try {
            return $importer->importOptions();
        } catch (\Throwable) {
            return [];
        }
    }

    private function startedMessage(ImportJob $job): string
    {
        return match ($job->status) {
            ImportJob::STATUS_PREVIEWED => Text::sprintf('COM_VIDYALMS_IMPORT_PREVIEW_DONE', $job->id),
            ImportJob::STATUS_COMPLETED => Text::sprintf('COM_VIDYALMS_IMPORT_COMPLETED', $job->id),
            ImportJob::STATUS_FAILED    => Text::sprintf('COM_VIDYALMS_IMPORT_FAILED', $job->id),
            default                     => Text::sprintf('COM_VIDYALMS_IMPORT_QUEUED', $job->id),
        };
    }

    private function fail(string $message): void
    {
        $this->setMessage($message, 'error');
        $this->setRedirect(Route::_(self::SCREEN_URL, false));
    }
}
