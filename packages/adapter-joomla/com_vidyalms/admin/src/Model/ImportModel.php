<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Shondalai\VidyaLMS\Core\Application\Platform\ListImportJobs;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use VidyaLMS\Component\VidyaLMS\Administrator\Helper\VidyaLmsHelper;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ImportSources;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\UploadLimits;

defined('_JEXEC') or die;

/**
 * The Import screen's model: what can be imported, what has been imported, and
 * the one path an upload takes to become a source.
 *
 * Not one row is written here. Starting an import is QueueImportJob (a real
 * run, which the task plugin picks up) or RunImportJob (a dry run, which
 * answers the operator now); reading history is ListImportJobs and
 * GetImportJob. Every one of them requires MANAGE inside the use case, so the
 * screen's own check is a courtesy to the operator, not the gate.
 *
 * The upload is the only thing this adapter owns, because bytes never travel
 * through the API: the file is validated, stored through StoragePort, and its
 * private path is what the job records as its source.
 *
 * @since 1.0.0
 */
final class ImportModel extends BaseDatabaseModel
{
    /**
     * The upload cap when the component options do not set one, in megabytes.
     * PHP's own upload_max_filesize and post_max_size still apply and are the
     * lower bound the screen reports.
     */
    public const DEFAULT_MAX_UPLOAD_MB = 64;

    public const PER_PAGE = 20;

    /**
     * The scheduled task routine that drains the adapter queue, which is what
     * actually runs a queued import. Declared by plg_task_vidyalms.
     */
    private const DRAIN_ROUTINE = 'vidyalms.drain_jobs';

    private ?bool $authorised = null;

    /** @var list<array{key: string, label: string, extensions: list<string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: list<array{key: string, label: string, kind: string, help: string, default: string, choices: list<array{value: string, label: string}>}>}>|null */
    private ?array $importers = null;

    /** @var list<ImportJob>|null */
    private ?array $jobs = null;

    private int $total = 0;

    /**
     * Importing is administration, so the core's MANAGE capability decides,
     * not the Joomla ACL alone.
     */
    public function isAuthorised(): bool
    {
        if ($this->authorised === null) {
            $this->authorised = VidyaLmsHelper::kernel()->guard->can(VidyaLmsHelper::principal(), Capability::MANAGE);
        }

        return $this->authorised;
    }

    /**
     * The registered importers, described for the screen. The screen offers
     * exactly this list and the intake refuses anything else, so an importer
     * whose extension failed to install is simply absent rather than a job
     * that sits pending forever.
     *
     * @return list<array{key: string, label: string, extensions: list<string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: list<array{key: string, label: string, kind: string, help: string, default: string, choices: list<array{value: string, label: string}>}>}>
     */
    public function getImporters(): array
    {
        if ($this->importers === null) {
            $this->importers = ImportSources::descriptors(VidyaLmsHelper::kernel()->registries->importers);
        }

        return $this->importers;
    }

    public function hasImporter(string $key): bool
    {
        return VidyaLmsHelper::kernel()->registries->importers->has($key);
    }

    /**
     * @return array{key: string, label: string, extensions: list<string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: list<array{key: string, label: string, kind: string, help: string, default: string, choices: list<array{value: string, label: string}>}>}|null
     */
    public function getImporter(string $key): ?array
    {
        foreach ($this->getImporters() as $importer) {
            if ($importer['key'] === $key) {
                return $importer;
            }
        }

        return null;
    }

    /**
     * One page of the import history, newest first.
     *
     * @return list<ImportJob>
     */
    public function getJobs(): array
    {
        if ($this->jobs !== null) {
            return $this->jobs;
        }

        if (!$this->isAuthorised()) {
            return $this->jobs = [];
        }

        $page = VidyaLmsHelper::kernel()->listImportJobs->execute(
            VidyaLmsHelper::principal(),
            $this->getPage(),
            self::PER_PAGE
        );

        $this->total = $page->total;

        return $this->jobs = $page->items;
    }

    public function getTotal(): int
    {
        $this->getJobs();

        return $this->total;
    }

    public function getPage(): int
    {
        $page = (int) $this->getState('import.page', 0);

        return $page > 0 ? $page : 1;
    }

    /**
     * The job the screen just started, so the list can open it rather than
     * making the operator hunt for the row they created a second ago.
     */
    public function getHighlightedJobId(): int
    {
        return max(0, (int) $this->getState('import.highlight', 0));
    }

    public function getJob(int $jobId): ImportJob
    {
        return VidyaLmsHelper::kernel()->getImportJob->execute(VidyaLmsHelper::principal(), $jobId);
    }

    /**
     * The ids on this page that are still moving, so the screen knows whether
     * it has anything to poll for.
     *
     * A run whose heartbeat stopped is not moving and never will: polling it
     * is what left the old screen refreshing a dead job for ever. It is left
     * out here and shown as stopped responding instead.
     *
     * @return list<int>
     */
    public function getUnfinishedJobIds(): array
    {
        $ids = [];
        $now = VidyaLmsHelper::now();

        foreach ($this->getJobs() as $job) {
            if (!$job->isFinished() && !$job->hasStalled($now)) {
                $ids[] = $job->id;
            }
        }

        return $ids;
    }

    /**
     * What stands between a queued import and the work actually happening,
     * as language keys the screen shows before the operator presses Run.
     *
     * A real import is queued, not run inline, so it needs plg_task_vidyalms
     * enabled and its drain routine scheduled. Without both, the job row is
     * created, stays pending forever and nothing says why.
     *
     * @return list<string>
     */
    public function getQueueWarnings(): array
    {
        $warnings = [];

        if (!PluginHelper::isEnabled('task', 'vidyalms')) {
            $warnings[] = 'COM_VIDYALMS_IMPORT_WARN_TASK_PLUGIN_DISABLED';

            return $warnings;
        }

        if (!$this->drainTaskScheduled()) {
            $warnings[] = 'COM_VIDYALMS_IMPORT_WARN_NO_DRAIN_TASK';
        }

        return $warnings;
    }

    /**
     * True when at least one enabled scheduled task runs the queue drain.
     * A missing com_scheduler table is answered "yes" rather than raising a
     * warning about a warning.
     */
    private function drainTaskScheduled(): bool
    {
        try {
            $db    = $this->getDatabase();
            $type  = self::DRAIN_ROUTINE;
            $query = $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('state') . ' = 1')
                ->bind(':type', $type);

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * The smaller of the configured cap and what PHP will actually accept, in
     * bytes. Reporting the configured number while PHP silently truncates at
     * 8M is how an operator loses an afternoon.
     */
    public function getMaxUploadBytes(): int
    {
        $configured = (int) \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::configuration()
            ->get('import_max_upload_mb', self::DEFAULT_MAX_UPLOAD_MB);

        return UploadLimits::effectiveCeilingBytes(max(1, $configured) * 1024 * 1024);
    }

    /**
     * Validates an upload and stores it in the private area, returning the
     * source path the job will record.
     *
     * Everything is checked before a byte is written: the transport error, the
     * size against the cap, and the extension against the allowlist the chosen
     * importer narrowed. The stored name is rebuilt from the validated
     * extension, so an uploaded "course.php.json" cannot become a PHP file.
     *
     * @param array<string, mixed> $file the $_FILES entry, as Joomla's input gives it
     *
     * @throws ValidationException when the upload is not usable
     */
    public function storeUpload(array $file, string $importerKey): string
    {
        $descriptor = $this->getImporter($importerKey);

        if ($descriptor === null) {
            throw self::invalid('importer', Text::_('COM_VIDYALMS_IMPORT_ERROR_UNKNOWN_IMPORTER'));
        }

        $error = (int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE);

        if ($error !== \UPLOAD_ERR_OK) {
            throw self::invalid('source', self::uploadErrorMessage($error));
        }

        $temporary = (string) ($file['tmp_name'] ?? '');

        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw self::invalid('source', Text::_('COM_VIDYALMS_IMPORT_ERROR_UPLOAD_MISSING'));
        }

        $size = (int) ($file['size'] ?? 0);
        $cap  = $this->getMaxUploadBytes();

        if ($size <= 0) {
            throw self::invalid('source', Text::_('COM_VIDYALMS_IMPORT_ERROR_UPLOAD_EMPTY'));
        }

        if ($size > $cap) {
            throw self::invalid('source', Text::sprintf(
                'COM_VIDYALMS_IMPORT_ERROR_UPLOAD_TOO_LARGE',
                self::formatBytes($size),
                self::formatBytes($cap)
            ));
        }

        $name      = (string) ($file['name'] ?? '');
        $extension = ImportSources::extensionOf($name);

        if ($extension === '' || !\in_array($extension, $descriptor['extensions'], true)) {
            throw self::invalid('source', Text::sprintf(
                'COM_VIDYALMS_IMPORT_ERROR_UPLOAD_EXTENSION',
                $extension !== '' ? $extension : Text::_('COM_VIDYALMS_IMPORT_NO_EXTENSION'),
                implode(', ', $descriptor['extensions'])
            ));
        }

        $contents = \Shondalai\VidyaLMS\Core\Upload\UploadedBytes::read($temporary, $cap);
        \Shondalai\VidyaLMS\Core\Upload\UploadIntake::inspect(
            \Shondalai\VidyaLMS\Core\Upload\UploadPolicy::importSource($descriptor['extensions'], $cap),
            $contents, $name, $cap,
        );

        $path = sprintf(
            '%s/%d/%s-%s',
            ImportSources::UPLOAD_FOLDER,
            max(0, VidyaLmsHelper::principal()->userId),
            bin2hex(random_bytes(16)),
            ImportSources::safeFileName($name, $extension)
        );

        $storage = VidyaLmsHelper::storage();
        if (!$storage instanceof \Shondalai\VidyaLMS\Core\Upload\UploadStorage) {
            throw new \RuntimeException('Upload storage limits are unavailable.');
        }
        $storage->putUpload($path, $contents, false, VidyaLmsHelper::principal()->userId);

        return $path;
    }

    /**
     * Starts an import.
     *
     * A dry run is inline and answers here, because a preview exists to be
     * read now and writes nothing. A real import is queued: a Moodle backup
     * takes minutes, and the request that asked for it would time out long
     * before the import finished.
     *
     * @param array<string, mixed> $options
     */
    public function start(string $importerKey, string $source, array $options, bool $dryRun): ImportJob
    {
        $kernel    = VidyaLmsHelper::kernel();
        $principal = VidyaLmsHelper::principal();
        $options['dry_run'] = $dryRun;

        if ($dryRun) {
            return $kernel->runImportJob->execute($principal, $importerKey, $source, $options);
        }

        return $kernel->queueImportJob->execute($principal, $importerKey, $source, $options);
    }

    protected function populateState(): void
    {
        $input = Factory::getApplication()->getInput();

        $this->setState('import.page', max(1, $input->getInt('page', 1)));
        $this->setState('import.importer', $input->getCmd('importer', ''));
        $this->setState('import.highlight', max(0, $input->getInt('highlight', 0)));

        parent::populateState();
    }

    /**
     * ValidationException::forField() keeps a generic message and puts the
     * detail in errors; the screen shows a message, so both carry it.
     */
    private static function invalid(string $field, string $message): ValidationException
    {
        return new ValidationException([$field => $message], $message);
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            \UPLOAD_ERR_NO_FILE               => Text::_('COM_VIDYALMS_IMPORT_ERROR_UPLOAD_MISSING'),
            \UPLOAD_ERR_INI_SIZE,
            \UPLOAD_ERR_FORM_SIZE             => Text::_('COM_VIDYALMS_IMPORT_ERROR_UPLOAD_OVER_PHP_LIMIT'),
            \UPLOAD_ERR_PARTIAL               => Text::_('COM_VIDYALMS_IMPORT_ERROR_UPLOAD_PARTIAL'),
            \UPLOAD_ERR_NO_TMP_DIR,
            \UPLOAD_ERR_CANT_WRITE,
            \UPLOAD_ERR_EXTENSION             => Text::_('COM_VIDYALMS_IMPORT_ERROR_UPLOAD_SERVER'),
            default                           => Text::_('COM_VIDYALMS_IMPORT_ERROR_UPLOAD_FAILED'),
        };
    }

    /**
     * The screen renders byte counts; the arithmetic and PHP's own ceiling
     * belong to Service\UploadLimits, which the upload endpoint reads too, so
     * the two surfaces cannot report different limits for the same server.
     */
    public static function formatBytes(int $bytes): string
    {
        return UploadLimits::formatBytes($bytes);
    }

    /**
     * ListImportJobs caps the page size itself; naming the constant here keeps
     * the screen's paging and the use case's ceiling from drifting apart.
     */
    public static function maxPerPage(): int
    {
        return ListImportJobs::MAX_PER_PAGE;
    }
}
