<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage;

/** A dedicated random key, never the sometimes-short legacy Joomla site secret. */
final class OrganisationInvitationKey
{
    private const FILE = 'organisation-invitations.key';

    public function __construct(private readonly JoomlaStorage $storage, private readonly string $webRoot)
    {
    }

    /** Existing or corrupt key files are never replaced, and queued ciphertext forbids creating a missing key. */
    public function readOrCreate(bool $allowCreate): string
    {
        if ($this->storage->isWebExposed()) {
            throw new \RuntimeException('Invitation keys require private storage outside the web root.');
        }
        $this->storage->ensurePrivateRoot();
        $root = realpath($this->storage->privateRoot());
        $web = realpath($this->webRoot);
        if ($root === false || $web === false || self::within($root, $web)) {
            throw new \RuntimeException('Invitation key storage must not resolve inside the web root.');
        }
        $directory = $root . '/.secrets';
        if (is_link($directory)) {
            throw new \RuntimeException('Invitation key storage cannot be a symbolic link.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new \RuntimeException('Invitation key storage is unavailable.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !self::same($resolved, $directory)) {
            throw new \RuntimeException('Invitation key storage resolves outside its dedicated directory.');
        }
        $path = $directory . '/' . self::FILE;
        if (is_link($path)) {
            throw new \RuntimeException('Invitation keys cannot be symbolic links.');
        }
        $created = false;
        $handle = @fopen($path, 'rb');
        if ($handle === false && $allowCreate && !file_exists($path)) {
            self::restrictDirectory($directory);
            $mask = umask(0077);
            try {
                $handle = @fopen($path, 'x+b');
            } finally {
                umask($mask);
            }
            $created = $handle !== false;
            if (!$created) {
                // A concurrent creator won. Never overwrite or regenerate its key.
                $handle = @fopen($path, 'rb');
            }
        }
        if ($handle === false) {
            throw new \RuntimeException('Invitation key is unavailable; restore its protected backup.');
        }
        try {
            if (!flock($handle, $created ? LOCK_EX : LOCK_SH)) {
                throw new \RuntimeException('Invitation key could not be locked.');
            }
            if ($created) {
                if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0600)) {
                    throw new \RuntimeException('Invitation key permissions could not be restricted.');
                }
                $key = random_bytes(32);
                if (fwrite($handle, $key) !== 32 || !fflush($handle) || !fsync($handle)) {
                    throw new \RuntimeException('Invitation key could not be saved safely.');
                }
            } else {
                if (PHP_OS_FAMILY !== 'Windows') {
                    $mode = fileperms($path);
                    if ($mode === false || ($mode & 0077) !== 0) {
                        throw new \RuntimeException('Invitation key permissions are too broad.');
                    }
                }
                $key = stream_get_contents($handle, 33);
            }
            if (!is_string($key) || strlen($key) !== 32) {
                throw new \RuntimeException('Invitation key is corrupt; restore its protected backup.');
            }
            return $key;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function restrictDirectory(string $directory): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            if (!chmod($directory, 0700)) {
                throw new \RuntimeException('Invitation key permissions could not be restricted.');
            }
            return;
        }
        self::withSetupCooldown($directory, static function () use ($directory): void {
            self::runAclProcess(self::windowsCommand($directory));
        });
    }

    /** @return list<string> */
    private static function windowsCommand(string $directory): array
    {
        $systemRoot = getenv('SystemRoot');
        $binary = is_string($systemRoot) ? $systemRoot . '/System32/WindowsPowerShell/v1.0/powershell.exe' : '';
        $script = __DIR__ . '/support/restrict-invitation-key-directory.ps1';
        if (!@is_file($binary) || !@is_file($script)) {
            throw new \RuntimeException('Windows invitation key ACL provider is unavailable.');
        }
        // No shell, encoded payload, command interpolation or user-selected executable.
        // Bypass applies only to this readable packaged script, not machine policy.
        return [$binary, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', $script, '-Directory', $directory];
    }

    /**
     * Persist the retry window BEFORE launching. A crashed/timed-out/failed attempt
     * cannot spawn another process on each subsequent kernel build. Malformed
     * state needs operator recovery rather than silently resetting the window.
     */
    private static function withSetupCooldown(string $directory, callable $setup): void
    {
        $path = \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety::path(
            $directory, 'organisation-invitations-acl.state.json',
        );
        $handle = @fopen($path, 'x+b');
        $created = $handle !== false;
        if (!$created) {
            $handle = @fopen($path, 'r+b');
        }
        if ($handle === false) {
            throw new \RuntimeException('Cannot persist invitation key ACL setup state; see the installed README.');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Invitation key ACL setup is already running.');
            }
            $stat = fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
                throw new \RuntimeException('Invitation key ACL state must be an ordinary unlinked file.');
            }
            if (!$created) {
                $contents = stream_get_contents($handle, 2049);
                $state = is_string($contents) && strlen($contents) <= 2048 ? json_decode($contents, true) : null;
                if (!is_array($state) || ($state['version'] ?? null) !== 1
                    || !is_int($state['retry_after'] ?? null) || $state['retry_after'] <= 0
                    || !in_array($state['status'] ?? null, ['running', 'failed', 'ready'], true)) {
                    throw new \RuntimeException('Invitation key ACL setup state is invalid; see the installed README for recovery.');
                }
                if ($state['retry_after'] > time()) {
                    throw new \RuntimeException('Invitation key ACL setup is cooling down; see the installed README for recovery.');
                }
            }
            $state = ['version' => 1, 'status' => 'running', 'retry_after' => time() + 900];
            self::saveSetupState($handle, $state);
            try {
                $setup();
                $state['status'] = 'ready';
                self::saveSetupState($handle, $state);
            } catch (\Throwable $failure) {
                $state['status'] = 'failed';
                try { self::saveSetupState($handle, $state); }
                catch (\Throwable) { /* The pre-launch retry window is already durable. */ }
                throw new \RuntimeException(
                    'Windows invitation key ACL setup failed; retries pause for 15 minutes. See the installed README for recovery.',
                    0,
                    $failure,
                );
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param resource $handle @param array{version: int, status: string, retry_after: int} $state */
    private static function saveSetupState($handle, array $state): void
    {
        $contents = json_encode($state, JSON_THROW_ON_ERROR) . "\n";
        if (!rewind($handle) || !ftruncate($handle, 0)
            || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !fsync($handle)) {
            throw new \RuntimeException('Invitation key ACL retry state could not be saved.');
        }
    }

    /** @param list<string> $command */
    private static function runAclProcess(array $command, float $timeoutSeconds = 10.0): void
    {
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException('Windows invitation key process functions are unavailable.');
            }
        }
        // Null-device output avoids pipe deadlocks (Windows pipe reads can block
        // despite stream_set_blocking(false)). No child output or secrets enter logs.
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes, null, null, ['bypass_shell' => true, 'create_no_window' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Windows invitation key ACL process could not start.');
        }
        $status = ['running' => true, 'exitcode' => -1];
        try {
            $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    if ($status['exitcode'] !== 0) {
                        throw new \RuntimeException('Windows invitation key ACL validation failed.');
                    }
                    return;
                }
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Windows invitation key ACL setup exceeded its time limit.');
                }
                usleep(20000);
            } while (true);
        } finally {
            if ($status['running']) {
                @proc_terminate($process, 9);
                $deadline = hrtime(true) + 1_000_000_000;
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) { break; }
                    usleep(20000);
                } while (hrtime(true) < $deadline);
            }
            // proc_close can wait indefinitely for a still-running child. Only
            // reap after confirmed exit; failed termination stays fail-closed.
            if (!$status['running']) {
                proc_close($process);
            }
        }
    }

    private static function within(string $child, string $parent): bool
    {
        return self::same($child, $parent) || str_starts_with(self::normal($child), self::normal($parent) . '/');
    }

    private static function same(string $left, string $right): bool
    {
        return self::normal($left) === self::normal($right);
    }

    private static function normal(string $path): string
    {
        $value = rtrim(str_replace('\\', '/', $path), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
    }
}
