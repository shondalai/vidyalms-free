<?php

/**
 * @package     VidyaLMS
 * @subpackage  Build
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * Isolated regression for the package script's clock provisioning. No CMS, no
 * database and no site.
 *
 * The method is the whole reason a scheduled report ever fires. Every
 * registered periodic task type in this product, this release's
 * `reports.schedule` included, is started by the `vidyalms.sweeps` routine and
 * run by `vidyalms.drain_jobs`; neither has a task row of its own and neither
 * should. What that means is that a site missing either routine, or holding a
 * disabled copy of one, has a queue that never drains and sweeps that never
 * start, with nothing anywhere saying so.
 *
 * Three properties, and a live install would take a release to show any of
 * them:
 *
 *   - a site with no rows gets all five routines, enabled, once;
 *   - a site that already has a routine gets no second copy of it, whatever
 *     its state, because an administrator who changed a cadence made a
 *     decision an upgrade must not undo;
 *   - a site whose routine is present and disabled is TOLD, and the routine is
 *     left exactly as it is. Switching a site's scheduler back on behind the
 *     administrator's back would be worse than a sweep that does not run.
 *
 * The private method is reached by reflection on purpose. The alternative is
 * driving postflight(), which would also enable plugins, provision a user
 * group, backfill two tables and write a schema version row, and a test that
 * needed all of that to say something about five rows would prove nothing
 * about any of them.
 *
 * Run: php build/scheduled-tasks-test.php
 */

declare(strict_types=1);

namespace {
    if (PHP_SAPI !== 'cli') {
        exit(1);
    }

    \define('_JEXEC', 1);
    // The installer declares namespace locations even though this harness only
    // invokes scheduler provisioning and never reads the installed locations.
    \define('JPATH_ADMINISTRATOR', __DIR__ . '/uninstalled/administrator');
    \define('JPATH_LIBRARIES', __DIR__ . '/uninstalled/libraries');

    $failures = 0;

    function verify(bool $condition, string $what, string $evidence = ''): void
    {
        global $failures;

        $failures += $condition ? 0 : 1;
        printf("  [%s] %s%s\n", $condition ? 'PASS' : 'FAIL', $what, $evidence === '' ? '' : ' (' . $evidence . ')');
    }
}

namespace Joomla\CMS\Installer {
    interface InstallerScriptInterface
    {
    }

    class InstallerAdapter
    {
    }
}

namespace Joomla\Database {
    interface DatabaseInterface
    {
    }

    final class ParameterType
    {
        public const STRING = 2;
    }
}

namespace Joomla\CMS\Log {
    final class Log
    {
        public const WARNING = 8;

        /** @var list<string> */
        public static array $lines = [];

        public static function add(string $message, int $priority = 0, string $category = ''): void
        {
            self::$lines[] = $message;
        }
    }
}

namespace Joomla\CMS\Language {
    final class Text
    {
        public static function _(string $key): string
        {
            return $key;
        }

        public static function sprintf(string $key, mixed ...$arguments): string
        {
            return $key . '|' . implode('|', array_map('strval', $arguments));
        }
    }
}

namespace Joomla\CMS {
    final class Version
    {
        public const MAJOR_VERSION = 6;
    }

    /** The smallest date a package script uses: a SQL stamp and two formats. */
    final class Date
    {
        public function toSql(): string
        {
            return '2026-09-08 00:00:00';
        }

        public function format(string $format): string
        {
            return $format === 'd' ? '08' : '00:00:00';
        }
    }

    final class Factory
    {
        public static mixed $database = null;

        public static function getDate(): Date
        {
            return new Date();
        }

        public static function getContainer(): object
        {
            return new class () {
                public function get(string $key): mixed
                {
                    return Factory::$database;
                }
            };
        }

        public static function getApplication(): mixed
        {
            return null;
        }
    }
}

namespace Joomla\CMS\Application {
    interface CMSApplicationInterface
    {
    }

    class AdministratorApplication
    {
    }
}

namespace {
    use Joomla\CMS\Factory;
    use Joomla\CMS\Log\Log;

    /**
     * The smallest query builder the method uses: select, from, where, bind.
     * It records the bound task type, which is the only thing the fake database
     * needs in order to answer.
     */
    final class FakeQuery
    {
        public string $boundType = '';

        public function select(string $column): self
        {
            return $this;
        }

        public function from(string $table): self
        {
            return $this;
        }

        public function where(string $condition): self
        {
            return $this;
        }

        public function bind(string $placeholder, mixed $value, int $type = 0): self
        {
            $this->boundType = (string) $value;

            return $this;
        }
    }

    /**
     * A scheduler table with whatever rows the case under test needs.
     */
    final class FakeDatabase implements \Joomla\Database\DatabaseInterface
    {
        /** @var list<object> */
        public array $inserted = [];

        private ?FakeQuery $query = null;

        /**
         * @param array<string, list<int>> $states  task type => the states its rows carry
         */
        public function __construct(
            private readonly array $states,
            private readonly bool $hasSchedulerTable = true,
        ) {
        }

        public function replacePrefix(string $name): string
        {
            return str_replace('#__', 'jos_', $name);
        }

        /** @return list<string> */
        public function getTableList(): array
        {
            return $this->hasSchedulerTable ? ['jos_scheduler_tasks'] : ['jos_content'];
        }

        /** @return array<string, string> */
        public function getTableColumns(string $table): array
        {
            return array_fill_keys([
                'asset_id', 'title', 'type', 'execution_rules', 'cron_rules', 'state', 'last_exit_code',
                'last_execution', 'next_execution', 'times_executed', 'times_failed', 'locked', 'priority',
                'ordering', 'cli_exclusive', 'params', 'note', 'created', 'created_by', 'checked_out',
                'checked_out_time',
            ], 'int');
        }

        public function createQuery(): FakeQuery
        {
            return new FakeQuery();
        }

        public function quoteName(string $name): string
        {
            return '`' . $name . '`';
        }

        public function setQuery(FakeQuery $query): self
        {
            $this->query = $query;

            return $this;
        }

        /** @return list<int> */
        public function loadColumn(): array
        {
            return $this->states[$this->query?->boundType ?? ''] ?? [];
        }

        public function insertObject(string $table, object $row): bool
        {
            $this->inserted[] = $row;

            return true;
        }
    }

    /**
     * The application half: it only has to hold an identity and take messages.
     *
     * It implements the interface the method's own signature names rather than
     * extending AdministratorApplication, because the signature is what a
     * package script is held to and a subclass of a stub would prove nothing
     * about it.
     */
    final class FakeApplication implements \Joomla\CMS\Application\CMSApplicationInterface
    {
        /** @var list<array{0: string, 1: string}> */
        public array $messages = [];

        public function getIdentity(): object
        {
            return new class () {
                public int $id = 42;
            };
        }

        public function enqueueMessage(string $message, string $type = 'message'): void
        {
            $this->messages[] = [$message, $type];
        }
    }

    /**
     * @param array<string, list<int>> $states
     *
     * @return array{inserted: list<string>, messages: list<array{0: string, 1: string}>, log: list<string>}
     */
    function provision(array $states, bool $hasSchedulerTable = true): array
    {
        Log::$lines = [];
        $database = new FakeDatabase($states, $hasSchedulerTable);
        Factory::$database = $database;

        $script = require __DIR__ . '/../pkg_script.php';
        $application = new FakeApplication();

        $method = new \ReflectionMethod($script, 'provisionScheduledTasks');
        $method->setAccessible(true);
        $method->invoke($script, $application);

        return [
            'inserted' => array_map(static fn (object $row): string => (string) $row->type, $database->inserted),
            'messages' => $application->messages,
            'log' => Log::$lines,
        ];
    }

    echo "== VidyaLMS package script: the clock every registered task type rides\n\n";

    // One: a site with no rows gets all five, enabled, once.
    $fresh = provision([]);

    verify(
        $fresh['inserted'] === [
            'vidyalms.easycommerce_sync',
            'vidyalms.drain_jobs',
            'vidyalms.sweeps',
            'vidyalms.expire_entitlements',
            'vidyalms.prune',
        ],
        'a fresh site gets all five routines, once each',
        implode(', ', $fresh['inserted']),
    );

    $created = array_filter(
        $fresh['messages'],
        static fn (array $message): bool => str_starts_with($message[0], 'LIB_VIDYALMS_CORE_PACKAGE_TASKS_CREATED'),
    );

    verify(
        \count($created) === 1 && $created[array_key_first($created)][1] === 'info',
        'the fresh install says how many it created, once, as information',
        json_encode(array_values($created)) ?: '',
    );

    // Two: a site that already has them gets no second copy, whatever the state
    // of the rows it has. An administrator who moved a routine to a different
    // interval made a decision an upgrade must not undo.
    $enabled = ['vidyalms.easycommerce_sync' => [1], 'vidyalms.drain_jobs' => [1], 'vidyalms.sweeps' => [1],
        'vidyalms.expire_entitlements' => [1], 'vidyalms.prune' => [1]];
    $again = provision($enabled);

    verify(
        $again['inserted'] === [] && $again['messages'] === [],
        'an upgrade of a site that already has all five creates nothing and says nothing',
        \count($again['inserted']) . ' inserted, ' . \count($again['messages']) . ' message(s)',
    );

    // Three: the property this release adds. A disabled sweeps routine is the
    // one state in which reports.schedule is registered, periodic, and never
    // fires, so it is reported rather than passed over in silence.
    $disabled = provision(['vidyalms.easycommerce_sync' => [1], 'vidyalms.drain_jobs' => [1], 'vidyalms.sweeps' => [0],
        'vidyalms.expire_entitlements' => [1], 'vidyalms.prune' => [1]]);

    $warnings = array_values(array_filter(
        $disabled['messages'],
        static fn (array $message): bool => $message[1] === 'warning',
    ));

    verify(
        $disabled['inserted'] === []
        && \count($warnings) === 1
        && str_starts_with($warnings[0][0], 'LIB_VIDYALMS_CORE_PACKAGE_TASKS_DISABLED')
        && str_contains($warnings[0][0], 'Start periodic sweeps'),
        'a disabled sweeps routine is named in a warning and left exactly as it is',
        json_encode($warnings) ?: '',
    );

    // A second, disabled copy of a routine beside an enabled one is a thing a
    // site may keep on purpose, so one enabled row is enough.
    $twoCopies = provision(['vidyalms.easycommerce_sync' => [1], 'vidyalms.drain_jobs' => [1], 'vidyalms.sweeps' => [0, 1],
        'vidyalms.expire_entitlements' => [1], 'vidyalms.prune' => [1]]);

    verify(
        $twoCopies['inserted'] === [] && $twoCopies['messages'] === [],
        'one enabled row is enough, so a deliberately kept disabled copy is not a warning',
        json_encode($twoCopies['messages']) ?: '',
    );

    // Four: a site with no com_scheduler is warned and left working. Refusing
    // an install over housekeeping would be this script inventing a
    // requirement.
    $noScheduler = provision([], false);

    verify(
        $noScheduler['inserted'] === [] && $noScheduler['log'] !== [],
        'a site without com_scheduler gets a logged warning and a working extension',
        implode(' | ', $noScheduler['log']),
    );

    echo "\n", $failures === 0 ? "ALL CHECKS PASSED\n" : $failures . " CHECK(S) FAILED\n";

    exit($failures === 0 ? 0 : 1);
}
