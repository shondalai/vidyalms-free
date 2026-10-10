<?php

/**
 * VidyaLMS: proves vidyalms_kernel_ready fires in every context a Pro package
 * needs, and shows exactly what a listener receives in each.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 *
 * The action is the whole extension seam: it is the only moment at which a Pro
 * package or a third party can reach $kernel->registries, and a context where
 * it never fires is a context where Pro is silently not installed. Nothing
 * throws when that happens, which is why it needs a probe rather than a
 * docblock.
 *
 * The listener is a real must-use plugin, written before the run and deleted
 * after it, because that is what a third party actually is: code loaded by
 * WordPress before this plugin, registering on an action that has not been
 * declared yet. A listener this script added after wp-load would prove the
 * action fires and nothing about the order.
 *
 * Five contexts:
 *
 *   site           a front end page over HTTP
 *   administrator  a wp-admin load, in process (an HTTP one needs a login)
 *   api            the REST route over HTTP
 *   cli            WP_CLI, in process
 *   cron           DOING_CRON with the queue drain hook fired, in process,
 *                  which is what WP-Cron does to this plugin
 *
 * One process each, because the kernel is built once per request and cached.
 *
 * Usage (from any directory):
 *   php build/kernel-ready.php [--wp=<wordpress root>] [--url=<site url>]
 *
 * It writes two files and removes both: the must-use plugin and its log. It
 * writes no row and creates no table. The provider it registers lives in the
 * request that registered it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "kernel-ready.php runs on the command line only.\n");
    exit(1);
}

const CONTEXTS = ['site', 'administrator', 'api', 'cli', 'cron'];
const PROBE_FILE = 'vidyalms-kernel-ready-probe.php';
const PROBE_TASK_KEY = 'vidyalms.probe.kernel_ready';

// No built-in site: --wp or VIDYALMS_WORDPRESS_SITE names it, because this
// script works against whatever site it is pointed at.
$wpRoot = rtrim(str_replace('\\', '/', (string) getenv('VIDYALMS_WORDPRESS_SITE')), '/');
$siteUrl = null;
$context = null;

foreach (\array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--wp=')) {
        $wpRoot = rtrim(str_replace('\\', '/', substr($argument, 5)), '/');
    } elseif (str_starts_with($argument, '--url=')) {
        $siteUrl = rtrim(substr($argument, 6), '/');
    } elseif (str_starts_with($argument, '--context=')) {
        $context = substr($argument, 10);
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php build/kernel-ready.php [--wp=<root>] [--url=<site url>] [--context="
            . implode('|', CONTEXTS) . "]\n";
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
        exit(1);
    }
}

if (!is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, 'Not a WordPress root (pass --wp=<wordpress root> or set VIDYALMS_WORDPRESS_SITE): ' . $wpRoot . "\n");
    exit(1);
}

$muDirectory = $wpRoot . '/wp-content/mu-plugins';
$muFile = $muDirectory . '/' . PROBE_FILE;
$logFile = $muDirectory . '/vidyalms-kernel-ready-probe.log';

// ---------------------------------------------------------------------------
// The must-use plugin: what a Pro package's bootstrap does
// ---------------------------------------------------------------------------

function probePluginBody(string $logFile): string
{
    $log = var_export($logFile, true);

    return <<<PHP
    <?php

    /**
     * Plugin Name: VidyaLMS kernel-ready probe
     * Description: Written by packages/adapter-wordpress/build/kernel-ready.php and deleted by it.
     *              If this file is still here, that run died; deleting it is safe.
     */

    defined('ABSPATH') || exit;

    add_action('vidyalms_kernel_ready', static function (\$kernel): void {
        \$record = [
            'context' => defined('WP_CLI') && WP_CLI ? 'cli'
                : (defined('DOING_CRON') && DOING_CRON ? 'cron'
                : (defined('REST_REQUEST') && REST_REQUEST ? 'api'
                : (is_admin() ? 'administrator' : 'site'))),
            'received' => is_object(\$kernel) ? get_class(\$kernel) : gettype(\$kernel),
            'registered' => false,
            'registries' => 0,
            'question_types' => 0,
            'scoring_policies' => 0,
            'ran' => '',
            'error' => null,
        ];

        try {
            // Anonymous, and built here rather than at file scope: a must-use
            // plugin is loaded before the VidyaLMS plugin registers the
            // autoloader the interface comes from.
            \$provider = new class implements \\Shondalai\\VidyaLMS\\Core\\Registry\\ScheduledTask\\ScheduledTaskHandlerInterface {
                public function key(): string
                {
                    return 'vidyalms.probe.kernel_ready';
                }

                public function run(\\Shondalai\\VidyaLMS\\Core\\Registry\\ScheduledTask\\ScheduledTaskRun \$run): string
                {
                    return 'probe ran as ' . (\$run->principal->isGuest() ? 'guest' : 'user ' . \$run->principal->userId());
                }
            };

            \$kernel->registries->scheduledTasks->register(\$provider);

            \$record['registered'] = true;
            \$record['registries'] = count(get_object_vars(\$kernel->registries));
            \$record['question_types'] = count(\$kernel->registries->questionTypes->keys());
            \$record['scoring_policies'] = count(\$kernel->registries->scoringPolicies->keys());
            \$record['ran'] = (string) \$kernel->runScheduledTask(
                'vidyalms.probe.kernel_ready',
                0,
                [],
                \\Shondalai\\VidyaLMS\\Core\\Application\\Shared\\Principal::guest()
            );
        } catch (\\Throwable \$failure) {
            \$record['error'] = get_class(\$failure) . ': ' . \$failure->getMessage();
        }

        \$handle = @fopen({$log}, 'ab');

        if (\$handle !== false) {
            @flock(\$handle, LOCK_EX);
            @fwrite(\$handle, json_encode(\$record, JSON_UNESCAPED_SLASHES) . "\\n");
            @flock(\$handle, LOCK_UN);
            @fclose(\$handle);
        }
    }, 10, 1);
    PHP;
}

// ---------------------------------------------------------------------------
// Child: one in-process context
// ---------------------------------------------------------------------------

if ($context !== null) {
    if (!\in_array($context, ['administrator', 'cli', 'cron'], true)) {
        fwrite(STDERR, 'Not an in-process context: ' . $context . "\n");
        exit(1);
    }

    $_SERVER['HTTP_HOST'] ??= 'localhost';
    $_SERVER['REQUEST_URI'] ??= '/' . basename($wpRoot) . '/';
    $_SERVER['SCRIPT_NAME'] ??= '/' . basename($wpRoot) . '/index.php';
    $_SERVER['PHP_SELF'] ??= $_SERVER['SCRIPT_NAME'];
    $_SERVER['REQUEST_METHOD'] ??= 'GET';

    // What each context's own entry point defines before it loads WordPress:
    // wp-admin/admin.php defines WP_ADMIN, wp-cron.php defines DOING_CRON, and
    // WP-CLI defines WP_CLI.
    match ($context) {
        'administrator' => \define('WP_ADMIN', true),
        'cron' => \define('DOING_CRON', true),
        default => \define('WP_CLI', true),
    };

    \define('WP_USE_THEMES', false);

    require_once $wpRoot . '/wp-load.php';

    if (!function_exists('vidyalms_kernel')) {
        fwrite(STDERR, "The vidyalms plugin is not active on this site.\n");
        exit(1);
    }

    if ($context === 'cron') {
        // WP-Cron fires the recurring event, and the queue runner is what asks
        // for the kernel. Nothing else in a cron request would.
        do_action(\Shondalai\VidyaLMS\WordPress\Plugin::HOOK_RUN_JOBS);
    } else {
        vidyalms_kernel();
    }

    exit(0);
}

// ---------------------------------------------------------------------------
// Parent
// ---------------------------------------------------------------------------

$muDirectoryExisted = is_dir($muDirectory);

if (!$muDirectoryExisted && !mkdir($muDirectory, 0775, true) && !is_dir($muDirectory)) {
    fwrite(STDERR, 'Cannot create ' . $muDirectory . "\n");
    exit(1);
}

$body = probePluginBody($logFile);

if (file_put_contents($muFile, $body) === false) {
    fwrite(STDERR, 'Cannot write ' . $muFile . "\n");
    exit(1);
}

@unlink($logFile);

// However this run ends, the site is left without the probe: a must-use plugin
// loads on every request, including a person's, and this one is nobody's
// business but this script's.
register_shutdown_function(static function () use ($muFile, $logFile, $muDirectory, $muDirectoryExisted): void {
    @unlink($muFile);
    @unlink($logFile);

    // A site that had no mu-plugins folder gets none back. rmdir refuses a
    // folder with anything else in it, which is the behaviour we want.
    if (!$muDirectoryExisted) {
        @rmdir($muDirectory);
    }
});

if ($siteUrl === null) {
    $siteUrl = 'http://localhost/' . basename($wpRoot);
}

echo "VidyaLMS kernel-ready seam, WordPress\n";
echo '  site  : ' . $wpRoot . "\n";
echo '  url   : ' . $siteUrl . "\n";
echo '  action: vidyalms_kernel_ready, from a must-use plugin loaded before this plugin' . "\n\n";

/**
 * @return array{status: int, body: string}
 */
function fetch(string $url): array
{
    $context = stream_context_create([
        'http' => ['timeout' => 30, 'ignore_errors' => true, 'header' => "User-Agent: vidyalms-kernel-ready-probe\r\n"],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;

    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $found) === 1) {
            $status = (int) $found[1];
        }
    }

    return ['status' => $status, 'body' => $body === false ? '' : $body];
}

$notes = [];

// site and api are real requests: they are the two contexts a person or an app
// actually reaches, and in process they would prove nothing about routing.
$catalogue = fetch($siteUrl . '/index.php/courses/');
$notes['site'] = 'GET /courses/ answered ' . $catalogue['status'];

$rest = fetch($siteUrl . '/index.php/wp-json/vidyalms/v1/courses');
$notes['api'] = 'GET /wp-json/vidyalms/v1/courses answered ' . $rest['status'];

foreach (['administrator', 'cli', 'cron'] as $inProcess) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --wp=' . escapeshellarg($wpRoot)
        . ' --context=' . escapeshellarg($inProcess);

    $output = trim((string) shell_exec($command . ' 2>&1'));
    $notes[$inProcess] = $output === '' ? 'loaded WordPress with its own constants' : $output;
}

// ---------------------------------------------------------------------------
// What the listener recorded
// ---------------------------------------------------------------------------

$records = [];

foreach (explode("\n", is_file($logFile) ? (string) file_get_contents($logFile) : '') as $line) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    /** @var array<string, mixed>|null $record */
    $record = json_decode($line, true);

    if (\is_array($record) && \is_string($record['context'] ?? null)) {
        $records[$record['context']] = $record;
    }
}

$failures = 0;

foreach (CONTEXTS as $name) {
    $record = $records[$name] ?? null;

    if ($record === null) {
        $failures++;
        printf("  [FAIL] %-14s the listener never fired\n", $name);
        printf("           %-19s %s\n", 'note', $notes[$name] ?? '');

        continue;
    }

    $ok = ($record['received'] ?? '') === 'Shondalai\\VidyaLMS\\Core\\Boot\\CoreKernel'
        && ($record['registered'] ?? false) === true
        && ($record['ran'] ?? '') !== '';

    $failures += $ok ? 0 : 1;

    printf(
        "  [%s] %-14s %s\n",
        $ok ? 'PASS' : 'FAIL',
        $name,
        $ok ? 'listener received the live kernel, registered a provider, the core ran it' : 'the seam did not complete',
    );

    printf("           %-19s %s\n", 'kernel', $record['received'] ?? '');
    printf(
        "           %-19s %d registries reachable, %d question type(s), %d scoring polic(ies)\n",
        'registries',
        (int) ($record['registries'] ?? 0),
        (int) ($record['question_types'] ?? 0),
        (int) ($record['scoring_policies'] ?? 0),
    );
    printf("           %-19s %s\n", 'ran', $record['ran'] ?? '');
    printf("           %-19s %s\n", 'note', $notes[$name] ?? '');

    if (($record['error'] ?? null) !== null) {
        printf("           %-19s %s\n", 'error', $record['error']);
    }
}

echo "\n" . ($failures === 0
    ? 'The seam holds in all ' . \count(CONTEXTS) . " contexts.\n"
    : $failures . " context(s) failed.\n");

exit($failures === 0 ? 0 : 1);
