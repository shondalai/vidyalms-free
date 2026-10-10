<?php

/**
 * @package     VidyaLMS
 * @subpackage  Package
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Shondalai\VidyaLMS\Core\Application\Organisation\BackfillAssignmentReferences;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaDatabase;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaRandom;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\SystemClock;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\SchemaVersion;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\UserGroupProvisioner;

return new class () implements InstallerScriptInterface {
    private const MINIMUM_PHP = '8.3.0';

    private const MINIMUM_JOOMLA = '6.0.0';

    /**
     * Plugin (folder, element) pairs the package ships. Joomla installs every
     * plugin disabled, and several are required for normal operation: without the
     * webservices plugin the REST surface is dormant, without the system
     * plugin nothing boots the kernel outside com_vidyalms, without the task
     * plugin the job queue never drains.
     *
     * @var  list<array{0: string, 1: string}>
     */
    private const PLUGINS = [
        ['webservices', 'vidyalms'],
        ['system', 'vidyalms'],
        ['system', 'vidyalms_easycommerce'],
        ['task', 'vidyalms'],
        ['privacy', 'vidyalms'],
        ['finder', 'vidyalms'],
    ];

    /**
     * The delegated administrators group, and the one capability it carries.
     *
     * Organisations are free, so the group that makes the delegated console
     * reachable is provisioned by the free package rather than by Pro. The
     * title is a data value in #__usergroups and not rendered copy, so it is
     * a constant here for the same reason "Instructors" is one in the Pro
     * installer: a translated title would give one site a group nothing else
     * can find by name.
     *
     * ONE capability and no more. Every site-wide use case refuses an
     * organisation administrator precisely because holding
     * vidyalms.manage_groups grants entry to the delegated surface and
     * nothing else; a second action on the component asset would widen four
     * of them silently and no core edit would show it.
     */
    private const ORG_ADMIN_GROUP = 'Organisation administrators';

    /** @var  list<string> */
    private const ORG_ADMIN_ACTIONS = ['vidyalms.manage_groups'];

    private const COMPONENT_ASSET = 'com_vidyalms';

    /**
     * The namespace roots this package puts on disk, and where they land.
     *
     * Only the two postflight actually reaches into: the component, for its
     * Port and Service classes, and the shared library, for the use cases the
     * backfills run. The plugins are enabled by row and never instantiated
     * here, so their namespaces are left to the next request.
     *
     * @var  array<string, string>
     */
    private const INSTALLED_NAMESPACES = [
        'VidyaLMS\\Component\\VidyaLMS\\Administrator' => JPATH_ADMINISTRATOR . '/components/com_vidyalms/src',
        'Shondalai\\VidyaLMS\\Core'                    => JPATH_LIBRARIES . '/vidyalms_core/src',
    ];

    /**
     * The plugins of this package that already had an extension row when the
     * installer started, whatever their enabled state. Captured in preflight
     * because by postflight every shipped plugin exists and the two cases are
     * no longer distinguishable.
     *
     * @var  list<string>
     */
    private array $preexistingPlugins = [];

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        $this->cleanupPrivateStorage();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        if (in_array($db->replacePrefix('#__action_logs_extensions'), $db->getTableList(), true)) {
            $extension = 'com_vidyalms';
            $query = $db->createQuery()->delete($db->quoteName('#__action_logs_extensions'))
                ->where($db->quoteName('extension') . ' = :extension')->bind(':extension', $extension);
            $db->setQuery($query)->execute();
        }
        if (!in_array($db->replacePrefix('#__scheduler_tasks'), $db->getTableList(), true)) {
            return true;
        }
        $types = ['vidyalms.easycommerce_sync', 'vidyalms.drain_jobs', 'vidyalms.sweeps', 'vidyalms.expire_entitlements', 'vidyalms.prune'];
        $query = $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__scheduler_tasks'))
            ->whereIn($db->quoteName('type'), $types, ParameterType::STRING);
        $ids = $db->setQuery($query)->loadColumn();
        $scheduler = Factory::getApplication()->bootComponent('com_scheduler');
        if (!$scheduler instanceof \Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface) {
            throw new \RuntimeException('The scheduler MVC factory is unavailable.');
        }
        $factory = $scheduler->getMVCFactory();
        foreach ($ids as $id) {
            $table = $factory->createTable('Task', 'Administrator');
            if (!$table->delete((int) $id)) {
                throw new \RuntimeException('Could not remove a VidyaLMS scheduled task.');
            }
        }
        return true;
    }

    /** Called before package children and their settings tables are uninstalled. */
    private function cleanupPrivateStorage(): void
    {
        $app = Factory::getApplication();
        $app->getLanguage()->load('lib_vidyalms_core', JPATH_SITE);
        try {
            $app->bootComponent('com_vidyalms');
            $storage = Factory::getContainer()->get(\Shondalai\VidyaLMS\Core\Port\Storage\StoragePort::class);
            if (!$storage instanceof \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage) {
                throw new \RuntimeException('Joomla private storage is unavailable.');
            }
            $removeLearners = defined('VIDYALMS_REMOVE_LEARNER_FILES_ON_UNINSTALL')
                && constant('VIDYALMS_REMOVE_LEARNER_FILES_ON_UNINSTALL') === true;
            \VidyaLMS\Component\VidyaLMS\Administrator\Service\PrivateStorageCleanup::remove($storage, $removeLearners);
            $app->enqueueMessage(Text::_('LIB_VIDYALMS_CORE_PRIVATE_CLEANUP_COMPLETE'), 'info');
        } catch (\Throwable) {
            // Keep uninstall usable, but never claim that private erasure ran.
            $app->enqueueMessage(Text::_('LIB_VIDYALMS_CORE_PRIVATE_CLEANUP_INCOMPLETE'), 'warning');
        }
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        /** @var AdministratorApplication $app */
        $app = Factory::getApplication();

        if ($type !== 'uninstall') {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $installed = $db->setQuery($db->createQuery()->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))->where($db->quoteName('element') . ' = ' . $db->quote('pkg_vidyalms'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('package')))->loadResult();
            $metadata = json_decode((string) $installed, true);
            if (is_array($metadata) && version_compare((string) ($metadata['version'] ?? '0'), (string) $adapter->getManifest()->version, '>')) {
                $app->enqueueMessage(Text::_('PKG_VIDYALMS_NO_DOWNGRADE'), 'error');
                return false;
            }
            if (Factory::getContainer()->get(DatabaseInterface::class)->getServerType() !== 'mysql') {
                $app->enqueueMessage(Text::_('PKG_VIDYALMS_REQUIRES_MYSQL'), 'error');
                return false;
            }
            // JLIB_INSTALLER_MINIMUM_* are core strings: on a fresh install no VidyaLMS
            // language file is on disk yet, so nothing of ours can be quoted here.
            if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
                $app->enqueueMessage(Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', self::MINIMUM_PHP), 'error');

                return false;
            }

            if (!(new Version())->isCompatible(self::MINIMUM_JOOMLA)) {
                $app->enqueueMessage(Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', self::MINIMUM_JOOMLA), 'error');

                return false;
            }
        }

        if ($type !== 'uninstall') {
            $this->rememberExistingPlugins();
            if ($type === 'update') { $this->preserveRemovedPlugins($adapter->getManifest()); }
        }

        // Load the library strings while the library is still installed, so the
        // uninstall note below survives the removal of its own language file.
        $app->getLanguage()->load('lib_vidyalms_core', JPATH_SITE);

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        /** @var AdministratorApplication $app */
        $app = Factory::getApplication();

        if ($type !== 'uninstall') {
            $this->refreshNamespaceMap();
            $app->getLanguage()->load('lib_vidyalms_core', JPATH_SITE, null, true);
            $this->registerActionLogExtension();
            if ($type === 'install') {
                $this->enablePlugins();
                $this->provisionOrganisationAdministratorsGroup($app);
            }
            // Updates repair only existing task parameters, preserving deleted
            // tasks, enabled states, schedules and notification recipients.
            $this->provisionScheduledTasks($app, $type === 'install');
            $this->recordSiteBaseUrl();
            $this->backfillDefaultTemplateKey();
            $this->backfillAssignmentReferences();
            $this->recordSchemaVersion();
        }

        $key = match ($type) {
            'update'    => 'LIB_VIDYALMS_CORE_PACKAGE_UPDATED',
            'uninstall' => 'LIB_VIDYALMS_CORE_PACKAGE_UNINSTALLED',
            default     => 'LIB_VIDYALMS_CORE_PACKAGE_INSTALLED',
        };

        $app->enqueueMessage(Text::_($key), $type === 'uninstall' ? 'info' : 'success');

        return true;
    }

    /**
     * Teaches the autoloader of THIS request about the extensions this
     * package has just installed.
     *
     * Joomla builds its PSR-4 map once, at application boot, from the
     * extension rows that existed then. plg_extension_namespacemap rewrites
     * administrator/cache/autoload_psr4.php after every child install but
     * never feeds it back to the Composer loader already running, so on a
     * FIRST install every class of com_vidyalms and lib_vidyalms_core is
     * unreachable for the rest of the request. Postflight is exactly the code
     * that needs them, and the failure is a fatal "class not found" from the
     * package script after the tables are already created, which leaves a
     * site installed and unseeded.
     *
     * Nothing here may ask class_exists() whether the work is needed. Composer's
     * loader records every class it failed to find in a private missingClasses
     * cache and short circuits on it for the rest of the request, so a probe
     * that autoloads is what makes the later setPsr4 useless. That is why both
     * halves run unconditionally, and why the second half exists at all:
     * JLoader keeps no such cache, so a namespace registered with it still
     * resolves on a request where Composer has already given up on the class.
     *
     * It is a cheap no-op on an update, and it degrades to a logged warning
     * rather than an aborted install if the map cannot be read.
     */
    private function refreshNamespaceMap(): void
    {
        try {
            \JLoader::register('JNamespacePsr4Map', JPATH_LIBRARIES . '/namespacemap.php');

            (new \JNamespacePsr4Map())->load();
        } catch (\Throwable $failure) {
            Log::add(
                'VidyaLMS: cannot refresh the namespace map, ' . $failure->getMessage(),
                Log::WARNING,
                'jerror'
            );
        }

        foreach (self::INSTALLED_NAMESPACES as $namespace => $path) {
            if (!is_dir($path)) {
                continue;
            }

            try {
                \JLoader::registerNamespace($namespace, $path);
            } catch (\Throwable $failure) {
                Log::add(
                    'VidyaLMS: cannot register ' . $namespace . ', ' . $failure->getMessage(),
                    Log::WARNING,
                    'jerror'
                );
            }
        }
    }

    /**
     * Records which of the package's plugins are already installed, so
     * postflight can tell a first installation from an upgrade.
     */
    /** Keep intentional removals; newly introduced children may still install. */
    private function preserveRemovedPlugins(\SimpleXMLElement $manifest): void
    {
        $oldPath = JPATH_MANIFESTS . '/packages/pkg_vidyalms.xml';
        if (!is_file($oldPath)) { return; }
        $old = simplexml_load_file($oldPath, 'SimpleXMLElement', LIBXML_NONET);
        if ($old === false) { throw new \RuntimeException('Cannot inspect previous package children.'); }
        $previous = [];
        foreach ($old->files->file ?? [] as $child) {
            if ((string) $child['type'] === 'plugin' && str_starts_with((string) $child['id'], 'vidyalms')) {
                $previous[] = (string) $child['group'] . '.' . (string) $child['id'];
            }
        }
        for ($i = count($manifest->files->file) - 1; $i >= 0; --$i) {
            $child = $manifest->files->file[$i];
            $key = (string) $child['group'] . '.' . (string) $child['id'];
            if ((string) $child['type'] === 'plugin' && in_array($key, $previous, true)
                && !in_array($key, $this->preexistingPlugins, true)) {
                unset($manifest->files->file[$i]);
            }
        }
    }

    private function rememberExistingPlugins(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
        } catch (\Throwable $failure) {
            // Treating every plugin as pre-existing is the safe reading: the
            // installer then changes nobody's setting.
            $this->preexistingPlugins = array_map(
                static fn (array $plugin): string => $plugin[0] . '.' . $plugin[1],
                self::PLUGINS,
            );

            Log::add(
                'VidyaLMS: cannot read the plugin state before installing, leaving every plugin as it is, '
                . $failure->getMessage(),
                Log::WARNING,
                'jerror'
            );

            return;
        }

        foreach (self::PLUGINS as [$folder, $element]) {
            try {
                $query = $db->createQuery()
                    ->select($db->quoteName('extension_id'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = :folder')
                    ->where($db->quoteName('element') . ' = :element')
                    ->bind(':folder', $folder, ParameterType::STRING)
                    ->bind(':element', $element, ParameterType::STRING)
                    ->setLimit(1);

                if ((int) $db->setQuery($query)->loadResult() > 0) {
                    $this->preexistingPlugins[] = $folder . '.' . $element;
                }
            } catch (\Throwable $failure) {
                $this->preexistingPlugins[] = $folder . '.' . $element;

                Log::add(
                    'VidyaLMS: cannot read the state of plg_' . $folder . '_' . $element
                    . ', leaving it as it is, ' . $failure->getMessage(),
                    Log::WARNING,
                    'jerror'
                );
            }
        }
    }

    /**
     * Turns on the plugins this package installed for the FIRST time, because
     * Joomla installs every plugin disabled and four of the five are
     * load-bearing. A plugin that already existed is never touched: an
     * administrator who deliberately switched one off keeps that decision
     * across upgrades, and a plugin added in a later release still comes up
     * enabled because it has no row yet. Never fatal.
     */
    private function enablePlugins(): void
    {
        $new = array_values(array_filter(
            self::PLUGINS,
            fn (array $plugin): bool => !\in_array($plugin[0] . '.' . $plugin[1], $this->preexistingPlugins, true),
        ));

        if ($new === []) {
            return;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
        } catch (\Throwable $failure) {
            Log::add('VidyaLMS: cannot reach the database to enable plugins, ' . $failure->getMessage(), Log::WARNING, 'jerror');

            return;
        }

        foreach ($new as [$folder, $element]) {
            try {
                $query = $db->createQuery()
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('enabled') . ' = 1')
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = :folder')
                    ->where($db->quoteName('element') . ' = :element')
                    ->bind(':folder', $folder, ParameterType::STRING)
                    ->bind(':element', $element, ParameterType::STRING);

                $db->setQuery($query)->execute();
            } catch (\Throwable $failure) {
                Log::add(
                    'VidyaLMS: cannot enable plg_' . $folder . '_' . $element . ', ' . $failure->getMessage(),
                    Log::WARNING,
                    'jerror'
                );
            }
        }
    }

    /**
     * Record this site's own public root in the component's options, once.
     *
     * Uri::root() is composed from the request, and issuance is scheduled
     * work: the Scheduled Task routine that drains the queue and any
     * cli/joomla.php run have no request, so Uri::base() falls back to the
     * CLI entry point's path and a credential minted there carried an id like
     * http://localhost/cli/joomla.php/index.php/... Once that id is inside a
     * signed document it can never be corrected.
     *
     * Write-once and only from a real request. A site that sets live_site in
     * configuration.php never reaches this value at all, and an administrator
     * whose site has moved edits the option. It is deliberately not refreshed
     * on every request: a development box serving one site under two host
     * names would otherwise flip the recorded root back and forth, and the
     * value's whole purpose is to be the one answer a scheduled run gets.
     */
    private function recordSiteBaseUrl(): void
    {
        if (str_contains(PHP_SAPI, 'cli')) {
            return;
        }

        $root = rtrim(\Joomla\CMS\Uri\Uri::root(), '/');

        if ($root === '') {
            return;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->createQuery()
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_vidyalms'))
                ->setLimit(1);

            $stored = $db->setQuery($query)->loadResult();
            $params = \is_string($stored) && $stored !== '' ? json_decode($stored, true) : [];
            $params = \is_array($params) ? $params : [];

            if (trim((string) ($params['site_base_url'] ?? '')) !== '') {
                return;
            }

            $params['site_base_url'] = $root;
            $encoded = json_encode($params);

            if (!\is_string($encoded)) {
                return;
            }

            $update = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = :params')
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_vidyalms'))
                ->bind(':params', $encoded, ParameterType::STRING);

            $db->setQuery($update)->execute();
        } catch (\Throwable $failure) {
            Log::add(
                'VidyaLMS: cannot record this site\'s base URL, ' . $failure->getMessage(),
                Log::WARNING,
                'jerror'
            );
        }
    }

    /**
     * Give the seeded default certificate template its stable key on a site
     * that was installed before the column existed.
     *
     * EnsureDefaultTemplate seeds the row with template_key "vidyalms.default"
     * and looks it up by that key afterwards. A site whose default template
     * predates the column carries NULL there, so a lookup by the shipped key
     * misses where a fresh install finds it. A schema drift check cannot see
     * this: the structures match and only the data differs.
     *
     * A clean install at the floor has no template to repair, so this matches
     * nothing and costs one statement. It stays because it is a repair rather
     * than a migration: it is keyed on the state of the row, not on a version,
     * and a version-keyed file could never reach a site whose schema is
     * already current.
     *
     * Idempotent by its WHERE clause, and deliberately narrow: it names the
     * one row that is both the site default and unkeyed, so a template an
     * administrator created and made default is never renamed under them.
     */
    private function backfillDefaultTemplateKey(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $table = $db->replacePrefix('#__vidyalms_certificate_templates');

            if (!\in_array($table, $db->getTableList(), true)) {
                return;
            }

            $key = 'vidyalms.default';

            // template_key is unique, so this claims exactly one row: the
            // oldest unkeyed default, and only when nothing holds the key
            // already. Two writes of one key would be a duplicate-key error
            // rather than a backfill.
            $taken = $db->setQuery(
                $db->createQuery()
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__vidyalms_certificate_templates'))
                    ->where($db->quoteName('template_key') . ' = :key')
                    ->bind(':key', $key, ParameterType::STRING)
            )->loadResult();

            if ((int) $taken > 0) {
                return;
            }

            $candidate = (int) $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__vidyalms_certificate_templates'))
                    ->where($db->quoteName('is_default') . ' = 1')
                    ->where(
                        '(' . $db->quoteName('template_key') . ' IS NULL OR '
                        . $db->quoteName('template_key') . ' = ' . $db->quote('') . ')'
                    )
                    ->order($db->quoteName('id') . ' ASC')
                    ->setLimit(1)
            )->loadResult();

            if ($candidate <= 0) {
                return;
            }

            $update = $db->createQuery()
                ->update($db->quoteName('#__vidyalms_certificate_templates'))
                ->set($db->quoteName('template_key') . ' = :key')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':key', $key, ParameterType::STRING)
                ->bind(':id', $candidate, ParameterType::INTEGER);

            $db->setQuery($update)->execute();
        } catch (\Throwable $failure) {
            Log::add(
                'VidyaLMS: cannot backfill the default certificate template key, ' . $failure->getMessage(),
                Log::WARNING,
                'jerror'
            );
        }
    }

    /**
     * Every seat assignment written before assignment_ref existed gets the
     * opaque reference the unique index is carried on.
     *
     * Here rather than in the SQL file, and for a sharper reason than the
     * digest backfill above. A digest is a function of the row, so SQL could
     * in principle recompute it. A reference is a **draw**, so SQL would have
     * to invent an alphabet, a length and a source of randomness, and the
     * column would then mean one thing on a site that installed clean and
     * another on a site that upgraded. There is exactly one mint, and the
     * core's own use case runs it over this adapter's own database port; this
     * method writes nothing of its own.
     *
     * Idempotent by construction, and twice over: the read asks only for rows
     * whose reference is still null, and the write repeats that predicate in
     * its own statement. A second installer run finds nothing, and two
     * administrators upgrading at the same moment cannot overwrite each
     * other's mint.
     *
     * A failure is logged and swallowed for the reason every other postflight
     * step is: the extension is installed and working, the rows still carry
     * their own ids, and the next run finishes the job.
     */
    private function backfillAssignmentReferences(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            if (!\in_array($db->replacePrefix('#__vidyalms_seat_assignments'), $db->getTableList(), true)) {
                return;
            }

            $store = new OrganisationLearningStore(new JoomlaDatabase($db), new SystemClock(), new JoomlaRandom());
            $result = (new BackfillAssignmentReferences($store))->execute();

            if ($result['minted'] > 0) {
                Log::add(
                    sprintf('VidyaLMS: %d seat assignment reference(s) were minted.', $result['minted']),
                    Log::INFO,
                    'jerror'
                );
            }
        } catch (\Throwable $failure) {
            Log::add(
                'VidyaLMS: cannot backfill the seat assignment references, ' . $failure->getMessage(),
                Log::WARNING,
                'jerror'
            );
        }
    }

    /**
     * Write the applied schema version into the neutral schema_version table.
     *
     * Both hosts answer the question the same way from here on, through the
     * same core use case: the table the schema declares is the answer, not a
     * Joomla #__schemas row that says nothing about the neutral schema and not
     * a WordPress option only one host has. It runs on install and on every
     * update, and RecordSchemaVersion writes the row once, so a repeated
     * installer run adds nothing.
     *
     * A failure here is logged and swallowed for the same reason every other
     * postflight step is: the extension is installed and working, and a missing
     * version row is repaired by the next run rather than worth aborting an
     * install over.
     */
    private function recordSchemaVersion(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            if (!\in_array($db->replacePrefix('#__vidyalms_schema_version'), $db->getTableList(), true)) {
                return;
            }

            $updates = JPATH_ADMINISTRATOR . '/components/com_vidyalms/' . SchemaVersion::UPDATES;
            $recorded = SchemaVersion::record($db, $updates);

            if ($recorded === '') {
                Log::add(
                    'VidyaLMS: no schema update script was found, so no schema version was recorded.',
                    Log::WARNING,
                    'jerror'
                );
            }
        } catch (\Throwable $failure) {
            Log::add(
                'VidyaLMS: cannot record the applied schema version, ' . $failure->getMessage(),
                Log::WARNING,
                'jerror'
            );
        }
    }

    /**
     * Offer VidyaLMS in the User Actions Log extension filter. The entries
     * plg_system_vidyalms writes are recorded and displayed either way; this
     * is what puts the option in the drop-down.
     */
    private function registerActionLogExtension(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            if (!\in_array($db->replacePrefix('#__action_logs_extensions'), $db->getTableList(), true)) {
                return;
            }

            $extension = 'com_vidyalms';

            $query = $db->createQuery()
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__action_logs_extensions'))
                ->where($db->quoteName('extension') . ' = :extension')
                ->bind(':extension', $extension, ParameterType::STRING);

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                return;
            }

            $row = (object) ['extension' => $extension];
            $db->insertObject('#__action_logs_extensions', $row);
        } catch (\Throwable $failure) {
            Log::add('VidyaLMS: cannot register the action log extension, ' . $failure->getMessage(), Log::WARNING, 'jerror');
        }
    }

    /**
     * Creates the Scheduled Task rows the package needs to work at all.
     *
     * Four routines, and between them they start everything: drain_jobs runs
     * the adapter job queue, so every scheduled task type in the core registry
     * reaches its handler whatever queued it; sweeps asks the same registry
     * which handlers declare RunsPeriodically and queues one row per bin, so a
     * sweep a paid or third-party package registers starts the day it
     * registers; expire_entitlements runs the access sweep, which has no
     * periodic declaration of its own; prune is retention housekeeping. A
     * fresh install therefore needs no manual task setup, which is what this
     * method exists to guarantee.
     *
     * Idempotent by task type. A type that already has a row is left exactly
     * as it is, whatever its state, cadence or parameters, because an
     * administrator who disabled a routine or moved it to a different interval
     * made a decision an upgrade must not undo. That also makes this safe to
     * call on every install and every update: a routine added in a later
     * release still arrives, and nothing already there is duplicated.
     *
     * A disabled routine is reported and never switched back on, for the same
     * reason a changed cadence is left alone: somebody turned it off, and an
     * installer turning a site's scheduler back on behind their back is worse
     * than a sweep that does not run. It is reported rather than passed over
     * because "sweeps" is the one row every registered periodic task type
     * rides, this release included: the reports.schedule handler that renders
     * and delivers a scheduled report has no row of its own and never will, so
     * a site whose sweeps routine is switched off is a site where scheduled
     * reports silently never arrive with nothing anywhere saying so.
     *
     * Never fatal. A site without com_scheduler, or one whose scheduler table
     * refuses the insert, gets a warning in the log and a working extension;
     * the alternative is failing an install over housekeeping.
     */
    /** Safe task-notification shape; operators enable mail and choose recipients explicitly. */
    public static function taskParameters(array $params): array
    {
        $notifications = is_array($params['notifications'] ?? null) ? $params['notifications'] : [];
        foreach (['success' => 'success', 'failure' => 'failure', 'fatal_failure' => 'fatal', 'orphan' => 'orphan'] as $flag => $group) {
            $notifications[$flag . '_mail'] ??= 0;
            $key = 'notification_' . $group . '_groups';
            if (!is_array($notifications[$key] ?? null)) {
                $notifications[$key] = [];
            }
        }
        $params['notifications'] = $notifications;
        return $params;
    }

    private function provisionScheduledTasks(CMSApplicationInterface $app, bool $createMissing = true): void
    {
        // type => [title, interval unit and length, cron expression, params, note]
        $routines = [
            'vidyalms.easycommerce_sync' => [
                'title'    => 'VidyaLMS: Reconcile EasyCommerce access',
                'unit'     => 'minutes',
                'every'    => 5,
                'cron'     => 'PT5M',
                'params'   => ['batch_size' => 100],
                'note'     => 'Retries shop access synchronization and discovers missed order and subscription changes. No payment operations.',
            ],
            'vidyalms.drain_jobs' => [
                'title'    => 'VidyaLMS: Drain job queue',
                'unit'     => 'minutes',
                'every'    => 5,
                'cron'     => 'PT5M',
                'params'   => [
                    'batch_size'            => 25,
                    'max_attempts'          => 3,
                    'retry_backoff_minutes' => 5,
                    'stuck_minutes'         => 30,
                ],
                'note'     => 'Runs due rows in the VidyaLMS job queue: certificate renders, imports,'
                    . ' notification fan-out and sends, and any task type a package registers.',
            ],
            'vidyalms.sweeps' => [
                'title'    => 'VidyaLMS: Start periodic sweeps',
                'unit'     => 'hours',
                'every'    => 1,
                'cron'     => 'PT1H',
                'params'   => [],
                'note'     => 'One turn of the clock for every scheduled task handler that declares it has'
                    . ' to be started by the clock. It queues rows; the drain routine runs them.',
            ],
            'vidyalms.expire_entitlements' => [
                'title'    => 'VidyaLMS: Expire entitlements',
                'unit'     => 'days',
                'every'    => 1,
                'cron'     => 'P1D',
                'params'   => [],
                'note'     => 'Lapsed entitlements expire and their enrolments suspend, future dated'
                    . ' entitlements activate. Idempotent, so a second run in a day changes nothing.',
            ],
            'vidyalms.prune' => [
                'title'    => 'VidyaLMS: Prune spent rows',
                'unit'     => 'days',
                'every'    => 7,
                'cron'     => 'P7D',
                'params'   => [
                    'idempotency_grace_days'       => 1,
                    'job_retention_days'           => 30,
                    'import_source_retention_days' => 30,
                ],
                'note'     => 'Retention housekeeping over spent idempotency keys, finished job rows and'
                    . ' uploaded import archives. Touches no learning data.',
            ],
        ];

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            if (!\in_array($db->replacePrefix('#__scheduler_tasks'), $db->getTableList(), true)) {
                Log::add(
                    'VidyaLMS: com_scheduler is not installed, so no scheduled task rows were created.'
                    . ' The queue will not drain until a routine runs.',
                    Log::WARNING,
                    'jerror'
                );

                return;
            }

            $columns  = $db->getTableColumns('#__scheduler_tasks');
            $now      = Factory::getDate()->toSql();
            $userId   = (int) ($app->getIdentity()->id ?? 0);
            $created  = 0;
            $disabled = [];

            foreach ($routines as $type => $routine) {
                $query = $db->createQuery()
                    ->select($db->quoteName(['id', 'state', 'params']))
                    ->from($db->quoteName('#__scheduler_tasks'))
                    ->where($db->quoteName('type') . ' = :type')
                    ->bind(':type', $type, ParameterType::STRING);

                $existing = $db->setQuery($query)->loadAssocList();
                $states = array_column($existing ?: [], 'state');

                foreach ($existing ?: [] as $task) {
                    $params = json_decode((string) $task['params'], true);
                    if (!is_array($params)) {
                        continue; // Do not overwrite malformed/custom configuration.
                    }
                    $repaired = self::taskParameters($params);
                    if ($repaired !== $params) {
                        $id = (int) $task['id'];
                        $before = (string) $task['params'];
                        $after = json_encode($repaired, JSON_THROW_ON_ERROR);
                        $update = $db->createQuery()->update($db->quoteName('#__scheduler_tasks'))
                            ->set($db->quoteName('params') . ' = :after')
                            ->where($db->quoteName('id') . ' = :id')
                            ->where($db->quoteName('params') . ' = :before')
                            ->bind(':after', $after)->bind(':before', $before)->bind(':id', $id, ParameterType::INTEGER);
                        $db->setQuery($update)->execute();
                    }
                }

                if ($states !== []) {
                    // At least one row of this type exists, so nothing is
                    // created. It is enough that one of them is enabled: a
                    // site may keep a second, disabled copy of a routine on
                    // purpose.
                    if (!\in_array(1, array_map('intval', $states), true)) {
                        $disabled[] = $routine['title'];
                    }

                    continue;
                }

                if (!$createMissing) {
                    continue;
                }

                $candidate = [
                    // Joomla writes the asset row itself the first time the
                    // task is saved from the Scheduled Tasks screen. Until
                    // then the task inherits com_scheduler's rules, which is
                    // what every task seeded by an installer does.
                    'asset_id'         => 0,
                    'title'            => $routine['title'],
                    'type'             => $type,
                    'execution_rules'  => json_encode([
                        'rule-type'                     => 'interval-' . $routine['unit'],
                        'interval-' . $routine['unit']  => (string) $routine['every'],
                        'exec-day'                      => Factory::getDate()->format('d'),
                        'exec-time'                     => Factory::getDate()->format('H:i:s'),
                    ]),
                    'cron_rules'       => json_encode(['type' => 'interval', 'exp' => $routine['cron']]),
                    'state'            => 1,
                    'last_exit_code'   => 0,
                    'last_execution'   => null,
                    'next_execution'   => $now,
                    'times_executed'   => 0,
                    'times_failed'     => 0,
                    'locked'           => null,
                    'priority'         => 0,
                    'ordering'         => 0,
                    'cli_exclusive'    => 0,
                    // An object even when empty: a task form reads params as
                    // a registry, and a JSON array there is a shape nothing
                    // else in #__scheduler_tasks uses.
                    'params'           => json_encode((object) self::taskParameters($routine['params'])),
                    'note'             => $routine['note'],
                    'created'          => $now,
                    'created_by'       => $userId,
                    'checked_out'      => null,
                    'checked_out_time' => null,
                ];

                // Only the columns this Joomla actually has, so a core schema
                // change cannot take the installer down with it. It is a
                // variable and not an inline cast because insertObject takes
                // its object by reference, and PHP 8 refuses to pass an
                // expression that way.
                $row = (object) array_intersect_key($candidate, $columns);

                try {
                    $db->insertObject('#__scheduler_tasks', $row);
                    $created++;
                } catch (\Throwable $failure) {
                    Log::add(
                        'VidyaLMS: cannot create the scheduled task ' . $type . ', ' . $failure->getMessage(),
                        Log::WARNING,
                        'jerror'
                    );
                }
            }

            if ($created > 0) {
                $app->enqueueMessage(Text::sprintf('LIB_VIDYALMS_CORE_PACKAGE_TASKS_CREATED', $created), 'info');
            }

            if ($disabled !== []) {
                $app->enqueueMessage(
                    Text::sprintf('LIB_VIDYALMS_CORE_PACKAGE_TASKS_DISABLED', implode(', ', $disabled)),
                    'warning',
                );
            }
        } catch (\Throwable $failure) {
            Log::add(
                'VidyaLMS: cannot create the scheduled task rows, ' . $failure->getMessage(),
                Log::WARNING,
                'jerror'
            );
        }
    }

    /**
     * Creates the Organisation administrators user group under Registered and
     * grants it vidyalms.manage_groups on com_vidyalms, so the delegated
     * organisation console is authorisable on the day the package is
     * installed.
     *
     * It goes through the same Service\UserGroupProvisioner that
     * pkg_vidyalms_pro uses for Instructors, so all three of that service's
     * rules apply here without being restated: a group of that title under
     * Registered is adopted and never rewritten, one of that title elsewhere
     * in the tree belongs to the site owner and is named rather than granted
     * anything, an action already set for that group on the asset (allow or
     * deny) is left exactly as the administrator set it, and a site whose ACL
     * cannot be read or written gets a warning in the log and a working
     * install.
     *
     * The action list is deliberately one entry long; see ORG_ADMIN_ACTIONS.
     */
    private function provisionOrganisationAdministratorsGroup(CMSApplicationInterface $app): void
    {
        $provisioner = UserGroupProvisioner::create();

        if ($provisioner === null) {
            $this->warnOrganisationGroup('cannot reach the database to provision the ' . self::ORG_ADMIN_GROUP . ' group');

            return;
        }

        $parentId = $provisioner->registeredGroupId();

        if ($parentId <= 0) {
            $this->warnOrganisationGroup(
                'cannot find the Registered user group, so the ' . self::ORG_ADMIN_GROUP . ' group was not created'
            );

            return;
        }

        $ensured = $provisioner->ensure(self::ORG_ADMIN_GROUP, $parentId);

        if ($ensured['outcome'] === UserGroupProvisioner::FOREIGN) {
            $app->enqueueMessage(
                Text::sprintf(
                    'LIB_VIDYALMS_CORE_ORG_ADMIN_GROUP_FOREIGN',
                    self::ORG_ADMIN_GROUP,
                    $ensured['foreign_id'],
                    $ensured['foreign_parent'],
                    implode(', ', self::ORG_ADMIN_ACTIONS)
                ),
                'warning'
            );

            return;
        }

        if ($ensured['id'] <= 0) {
            $this->warnOrganisationGroup('cannot create the ' . self::ORG_ADMIN_GROUP . ' group');

            return;
        }

        $granted = $provisioner->grant(self::COMPONENT_ASSET, $ensured['id'], self::ORG_ADMIN_ACTIONS);

        if ($ensured['outcome'] === UserGroupProvisioner::CREATED) {
            $app->enqueueMessage(
                Text::sprintf('LIB_VIDYALMS_CORE_ORG_ADMIN_GROUP_CREATED', self::ORG_ADMIN_GROUP, $granted),
                'success'
            );

            return;
        }

        if ($granted > 0) {
            $app->enqueueMessage(
                Text::sprintf('LIB_VIDYALMS_CORE_ORG_ADMIN_GROUP_GRANTED', self::ORG_ADMIN_GROUP, $granted),
                'info'
            );
        }
    }

    private function warnOrganisationGroup(string $what): void
    {
        Log::add('VidyaLMS: ' . $what . '.', Log::WARNING, 'jerror');
    }
};
