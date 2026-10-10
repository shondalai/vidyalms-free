<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Files;

use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety;
use Shondalai\VidyaLMS\Core\Upload\UploadedBytes;
use Shondalai\VidyaLMS\Core\Upload\UploadIntake;
use Shondalai\VidyaLMS\Core\Upload\UploadPurpose;
use Shondalai\VidyaLMS\WordPress\Port\WpStorage;

defined('ABSPATH') || exit;

/** WordPress transport, with private staging and guaranteed cleanup. */
final class WpUploadReceiver
{
    /** @param array<string, mixed> $file */
    public static function read(array $file, UploadPurpose $purpose, WpStorage $storage, int $hostCeiling): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw ApiProblem::validation(['file' => 'No complete file was uploaded.']);
        }
        $ceiling = $hostCeiling > 0 ? min($purpose->maxBytes, $hostCeiling) : $purpose->maxBytes;
        $contents = UploadedBytes::read($file['tmp_name'], $ceiling);
        $inspection = UploadIntake::inspect($purpose, $contents, (string) ($file['name'] ?? ''), $ceiling);
        if ($purpose->isPublic()) {
            $staging = $storage->publicPath('.upload-staging');
        } else {
            $storage->ensurePrivateRoot();
            $staging = $storage->privatePath('.upload-staging');
        }
        // Private wp_handle_upload staging requires local 0700 directories and immediate local cleanup.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (!is_dir($staging) && !mkdir($staging, 0700, true) && !is_dir($staging)) {
            throw new \RuntimeException('Cannot create private upload staging.');
        }
        self::cleanStaging($staging);
        $directory = LocalStorageSafety::path($staging, bin2hex(random_bytes(16)));
        // Private wp_handle_upload staging requires local 0700 directories and immediate local cleanup.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Cannot create private upload staging.');
        }
        $filter = static fn (array $uploads): array => [
            'path' => $directory, 'basedir' => $directory, 'subdir' => '',
            'url' => '', 'baseurl' => '', 'error' => false,
        ];
        // A generated single-extension name also protects WordPress hooks from
        // archive/client filenames. The original is only display metadata.
        $file['name'] = 'upload.' . $inspection['extension'];
        $file['size'] = strlen($contents);
        $file['type'] = $inspection['mime'];
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        add_filter('upload_dir', $filter, PHP_INT_MAX);
        try {
            $result = wp_handle_upload($file, [
                'test_form' => false,
                // UploadIntake already validated bytes, extension and purpose.
                // WordPress's generic MIME list does not recognise LMS backup
                // formats and aliases such as MBZ or application/xml.
                'test_type' => false,
                'mimes' => [$inspection['extension'] => $inspection['mime']],
            ]);
            if (isset($result['error']) || !is_string($result['file'] ?? null)) {
                throw ApiProblem::validation(['file' => 'WordPress refused the uploaded file.']);
            }
            $expected = LocalStorageSafety::path($directory, basename($result['file']));
            if (str_replace('\\', '/', $result['file']) !== $expected
                || UploadedBytes::read($expected, $ceiling) !== $contents) {
                throw new \RuntimeException('The WordPress upload result failed verification.');
            }
            return $contents;
        } finally {
            remove_filter('upload_dir', $filter, PHP_INT_MAX);
            self::removeStage($directory);
        }
    }

    /** Reclaims files left only by interrupted requests, never saved uploads. */
    public static function cleanStaging(string $staging): void
    {
        LocalStorageSafety::noLinks($staging);
        foreach (new \DirectoryIterator($staging) as $entry) {
            if ($entry->isDot() || !preg_match('/^[a-f0-9]{32}$/D', $entry->getFilename())) {
                continue;
            }
            if ($entry->isDir() && !$entry->isLink() && $entry->getMTime() < time() - 86400) {
                self::removeStage($entry->getPathname());
            }
        }
    }

    private static function removeStage(string $directory): void
    {
        LocalStorageSafety::noLinks($directory);
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                LocalStorageSafety::noLinks($entry->getPathname());
                wp_delete_file($entry->getPathname());
            }
        }
        // Private wp_handle_upload staging requires local 0700 directories and immediate local cleanup.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        rmdir($directory);
    }
}
