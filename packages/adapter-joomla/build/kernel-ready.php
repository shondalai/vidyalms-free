<?php

/**
 * @package     VidyaLMS
 * @subpackage  Build
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * Proves that onVidyaLmsKernelReady fires in every context a Pro package needs,
 * and shows exactly what a listener receives in each.
 *
 * The event is the whole extension seam: it is the only moment at which a Pro
 * package or a third party can reach $kernel->registries, and a context where
 * it never fires is a context where Pro is silently not installed. Nothing
 * throws when that happens, which is why it needs a probe rather than a
 * docblock.
 *
 * Five contexts, one process each, because the kernel is built once per
 * process and the container caches it:
 *
 *   site           SiteApplication, a front end page
 *   administrator  AdministratorApplication, a manager screen
 *   api            ApiApplication, /api/index.php
 *   cli            the console application, a command or a script
 *   cron           the console application again, resolved the way
 *                  plg_task_vidyalms resolves it during a scheduler run
 *
 * In each one the probe does what a Pro package's plugin does: it puts a
 * listener on the application dispatcher, waits for the kernel to be built,
 * registers a provider into a registry from inside the listener, and then has
 * the core run it. Anything less proves the event fired, not that the seam
 * works.
 *
 * Usage (from any directory):
 *   php build/kernel-ready.php [--joomla=<joomla root>]
 *
 * It writes nothing: no row, no table, no file. The provider it registers
 * lives in that child process and dies with it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "kernel-ready.php runs on the command line only.\n");
    exit(1);
}

const CONTEXTS = ['site', 'administrator', 'api', 'cli', 'cron'];

// No built-in site: --joomla or VIDYALMS_JOOMLA_SITE names it, because this
// script works against whatever site it is pointed at.
$joomlaRoot = rtrim(str_replace('\\', '/', (string) getenv('VIDYALMS_JOOMLA_SITE')), '/');
$context = null;

foreach (\array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--joomla=')) {
        $joomlaRoot = rtrim(str_replace('\\', '/', substr($argument, 9)), '/');
    } elseif (str_starts_with($argument, '--context=')) {
        $context = substr($argument, 10);
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php build/kernel-ready.php [--joomla=<root>] [--context=" . implode('|', CONTEXTS) . "]\n";
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
        exit(1);
    }
}

if (!is_file($joomlaRoot . '/configuration.php')) {
    fwrite(STDERR, 'Not a Joomla root (pass --joomla=<joomla root> or set VIDYALMS_JOOMLA_SITE): ' . $joomlaRoot . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parent: one child per context, then the table
// ---------------------------------------------------------------------------

if ($context === null) {
    echo "VidyaLMS kernel-ready seam, Joomla\n";
    echo '  site : ' . $joomlaRoot . "\n";
    echo '  event: onVidyaLmsKernelReady' . "\n\n";

    $failures = 0;

    foreach (CONTEXTS as $name) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' --joomla=' . escapeshellarg($joomlaRoot)
            . ' --context=' . escapeshellarg($name);

        $output = (string) shell_exec($command . ' 2>&1');
        $line = '';

        // The child prints one JSON line; anything else on the stream is a
        // notice from the site, and worth showing rather than swallowing.
        foreach (explode("\n", $output) as $candidate) {
            if (str_starts_with(trim($candidate), '{')) {
                $line = trim($candidate);
            }
        }

        /** @var array<string, mixed>|null $result */
        $result = $line === '' ? null : json_decode($line, true);

        if (!\is_array($result)) {
            $failures++;
            printf("  [FAIL] %-14s the probe produced no result\n", $name);
            echo '         ' . str_replace("\n", "\n         ", trim($output)) . "\n";

            continue;
        }

        $ok = ($result['fired'] ?? false) === true
            && ($result['same_instance'] ?? false) === true
            && ($result['registered'] ?? false) === true
            && ($result['ran'] ?? '') !== '';

        $failures += $ok ? 0 : 1;

        printf(
            "  [%s] %-14s %s\n",
            $ok ? 'PASS' : 'FAIL',
            $name,
            $result['summary'] ?? '',
        );

        foreach (['application', 'arguments', 'kernel', 'registries', 'plugin_subscribers', 'container_before', 'ran', 'error'] as $key) {
            if (($result[$key] ?? '') !== '' && $result[$key] !== null) {
                printf("           %-19s %s\n", $key, $result[$key]);
            }
        }
    }

    echo "\n" . ($failures === 0
        ? "The seam holds in all " . \count(CONTEXTS) . " contexts.\n"
        : $failures . " context(s) failed.\n");

    exit($failures === 0 ? 0 : 1);
}

if (!\in_array($context, CONTEXTS, true)) {
    fwrite(STDERR, 'Unknown context: ' . $context . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Child: one context
// ---------------------------------------------------------------------------

$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_URI'] ??= '/' . basename($joomlaRoot) . '/';
$_SERVER['SCRIPT_NAME'] ??= '/' . basename($joomlaRoot) . '/index.php';
$_SERVER['PHP_SELF'] ??= $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_METHOD'] ??= 'GET';

\define('_JEXEC', 1);

// administrator/includes/defines.php derives JPATH_ROOT by splitting JPATH_BASE
// on DIRECTORY_SEPARATOR, so a forward-slashed path leaves every JPATH_ constant
// empty on Windows and the first require fails.
\define(
    'JPATH_BASE',
    $context === 'administrator'
        ? str_replace('/', \DIRECTORY_SEPARATOR, $joomlaRoot . '/administrator')
        : $joomlaRoot,
);

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Console\Application as ConsoleApplication;
use Joomla\Event\Event;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;

const PROBE_TASK_KEY = 'vidyalms.probe.kernel_ready';

/**
 * What a Pro package registers at kernel-ready: a provider with a key of its
 * own, which the core then runs without knowing where it came from.
 *
 * Anonymous, and built inside the listener rather than declared at the top of
 * this file, because the interface it implements is autoloaded through the
 * extension namespace map, and that map is registered further down. A named
 * class here would be linked while the file is still being read, before the
 * library the interface lives in is loadable at all.
 */
function probeTask(): ScheduledTaskHandlerInterface
{
    return new class implements ScheduledTaskHandlerInterface {
        public function key(): string
        {
            return PROBE_TASK_KEY;
        }

        public function run(ScheduledTaskRun $run): string
        {
            return 'probe ran as ' . ($run->principal->isGuest() ? 'guest' : 'user ' . $run->principal->userId());
        }
    };
}

$result = [
    'context' => $context,
    'application' => '',
    'fired' => false,
    'arguments' => '',
    'kernel' => '',
    'same_instance' => false,
    'registries' => '',
    'plugin_subscribers' => '',
    'registered' => false,
    'ran' => '',
    'container_before' => '',
    'summary' => '',
    'error' => null,
];

try {
    $container = Factory::getContainer();

    // Every application built here is built outside a request, so the session
    // service has to be the CLI one, exactly as cli/joomla.php aliases it.
    $container->alias('session', 'session.cli')
        ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
        ->alias(\Joomla\Session\Session::class, 'session.cli')
        ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

    $application = match ($context) {
        'site' => $container->get(SiteApplication::class),
        'administrator' => $container->get(AdministratorApplication::class),
        'api' => $container->get(ApiApplication::class),
        default => $container->get(ConsoleApplication::class),
    };

    Factory::$application = $application;

    // A web application built outside a request has no language yet, and
    // initialiseApp() is what normally gives it one. The component's provider
    // asks for it when the ports are built, so these are the two lines of
    // initialiseApp() this probe has to stand in for; the console application
    // brings its own and is left alone.
    if (!$application->getLanguage() instanceof \Joomla\CMS\Language\Language) {
        $language = $container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage(
            (string) ($application->get('language') ?: 'en-GB'),
            (bool) $application->get('debug_lang', false),
        );

        $application->loadLanguage($language);
        Factory::$language = $language;
    }

    // What CMSApplication::initialiseApp() and ConsoleApplication::execute()
    // both do before anything else: register the extension namespaces, then
    // put the plugin subscribers on this application's dispatcher.
    $application->createExtensionNamespaceMap();

    $dispatcher = $application->getDispatcher();

    // Only this package's plugin, by element. A development site carries other
    // people's plugins, and one of them loading badly outside a web request
    // would say nothing about this seam.
    PluginHelper::importPlugin('system', 'vidyalms', true, $dispatcher);

    // plg_system_vidyalms subscribes to onAfterInitialise and nothing else
    // here does, so this is the proof that a plugin's subscribers land on the
    // same dispatcher the kernel-ready event is dispatched on. Without that,
    // a Pro plugin could be loaded and still never hear the event.
    $result['plugin_subscribers'] = \count($dispatcher->getListeners('onAfterInitialise'))
        . ' listener(s) on onAfterInitialise after importing plg_system_vidyalms';

    $received = null;
    $arguments = [];
    $registered = false;

    $dispatcher->addListener(
        KernelFactory::KERNEL_READY_EVENT,
        static function (Event $event) use (&$received, &$arguments, &$registered): void {
            $arguments = array_keys($event->getArguments());
            $carried = $event->getArgument('kernel');

            if (!$carried instanceof CoreKernel) {
                return;
            }

            $received = $carried;

            // The point of the seam: register a provider of our own, into the
            // registries, at the one moment they are reachable.
            $carried->registries->scheduledTasks->register(probeTask());
            $registered = true;
        },
    );

    // The cron context records one fact the others cannot: whether the kernel
    // is on the container before anything asks KernelFactory for it. A web
    // request has it there already, because plg_system_vidyalms boots the
    // component on onAfterInitialise. A console application dispatches no
    // onAfterInitialise at all, so the answer is no, and a scheduled task that
    // asked the container directly would find nothing, report a missing
    // kernel and drain no queue. That is why every caller goes through
    // KernelFactory, which boots the component when the service is absent.
    if ($context === 'cron') {
        $result['container_before'] = $container->has(CoreKernel::class)
            ? 'the kernel was already on the container'
            : 'the kernel was not on the container until KernelFactory booted the component';
    }

    $kernel = KernelFactory::kernel();

    if ($context === 'cron' && !$container->has(CoreKernel::class)) {
        throw new \RuntimeException('KernelFactory did not leave the kernel on the container.');
    }

    $result['application'] = \get_class($application);
    $result['fired'] = $received instanceof CoreKernel;
    $result['arguments'] = implode(', ', $arguments);
    $result['kernel'] = $received === null ? '' : \get_class($received);
    $result['same_instance'] = $received === $kernel;
    $result['registries'] = \count((array) get_object_vars($kernel->registries)) . ' registries reachable, '
        . \count($kernel->registries->questionTypes->keys()) . ' question type(s), '
        . \count($kernel->registries->scoringPolicies->keys()) . ' scoring polic(ies)';
    $result['registered'] = $registered;

    $ran = $kernel->runScheduledTask(PROBE_TASK_KEY, 0, [], Principal::guest());
    $result['ran'] = \is_string($ran) ? $ran : '';

    $result['summary'] = $result['fired'] && $result['same_instance'] && $registered && $result['ran'] !== ''
        ? 'listener received the live kernel, registered a provider, the core ran it'
        : 'the seam did not complete';
} catch (\Throwable $failure) {
    $result['error'] = $failure::class . ': ' . $failure->getMessage();
    $result['summary'] = 'threw';
}

echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";

exit(0);
