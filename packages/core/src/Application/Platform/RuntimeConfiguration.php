<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\ConfigurationRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Typed, allowlisted runtime settings. One request-scoped snapshot, shared by
 * the adapter's ports and the kernel. No cross-request TTL for security rules.
 * Long-running workers must reset() between jobs. Only committed writes replace
 * the snapshot; a failed validation, conflict or transaction leaves it intact.
 */
final class RuntimeConfiguration
{
    /** @var array{revision: int, values: array<string, mixed>}|null */
    private ?array $snapshot = null;

    /** @param (callable(): array<string, mixed>)|null $legacy */
    public function __construct(private readonly ConfigurationRepository $repository, private readonly mixed $legacy = null)
    {
    }

    /** @return array<string, string|int|bool> */
    public static function defaults(): array
    {
        return [
            'academy_name' => '', 'site_base_url' => '', 'site_logo_url' => '',
            'verification_base_url' => '', 'credential_base_url' => '', 'status_list_base_url' => '',
            'unsubscribe_base_url' => '', 'envelope_domain' => '',
            'catalogue_per_page' => 12, 'self_enrolment' => true,
            'learner_navigation' => 'top',
            'learning_idle_timeout_seconds' => 300,
            'learner_header_display' => 'full', 'learner_header_title' => '', 'learner_header_tagline' => '',
            'learner_header_show_tagline' => true, 'learner_header_logo_url' => '', 'learner_header_show_logo' => true,
            'learner_header_logo_size' => 42, 'learner_header_density' => 'comfortable', 'learner_header_divider' => true,
            'import_max_upload_mb' => 64, 'upload_max_resource_mb' => 64, 'upload_max_image_mb' => 8,
            'import_source_retention_days' => PruneImportSources::DEFAULT_RETENTION_DAYS,
            'integrations_http_allowlist' => '', 'integrations_http_timeout' => 15,
        ];
    }

    /** @return array{revision: int, values: array<string, mixed>} */
    public function snapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }
        $stored = $this->repository->read();
        if ($stored === null) {
            $legacy = $this->legacy === null ? [] : ($this->legacy)();
            $values = self::defaults();
            foreach ($values as $key => $default) {
                if (\array_key_exists($key, $legacy)) {
                    $value = $legacy[$key];
                    $values[$key] = \is_bool($default) ? (bool) $value : (\is_int($default) ? (int) $value : (string) $value);
                }
            }
            // Preserve the installed configuration verbatim; edits use strict validation below.
            $this->repository->initialize($values);
            $stored = $this->repository->read();
        }
        if ($stored === null) {
            throw new \RuntimeException('VidyaLMS settings could not be initialized.');
        }
        // Removed settings must not remain writable or leak through the settings API.
        $stored['values'] = array_intersect_key($stored['values'], self::defaults()) + self::defaults();
        return $this->snapshot = $stored;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->snapshot()['values'][$key] ?? $fallback;
    }

    public function reset(): void
    {
        $this->snapshot = null;
    }

    /** @param array<string, mixed> $changes */
    public function save(int $expectedRevision, array $changes): void
    {
        $values = $this->snapshot()['values'];
        $defaults = self::defaults();
        $limits = [
            'learner_header_logo_size' => 80,
            'learning_idle_timeout_seconds' => 3600,
            'catalogue_per_page' => 50, 'import_max_upload_mb' => 2048, 'upload_max_resource_mb' => 2048,
            'upload_max_image_mb' => 512,
            'integrations_http_timeout' => \Shondalai\VidyaLMS\Core\Port\Http\HttpPolicy::MAX_TIMEOUT_SECONDS,
            'import_source_retention_days' => 3650,
        ];
        foreach ($changes as $key => $value) {
            if (\in_array($key, ['private_storage_path', 'private_storage_dir'], true) && $value !== ($defaults[$key] ?? null)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($key, 'Private storage is configured by the server owner using VIDYALMS_PRIVATE_DIR.');
            }
            if (!\array_key_exists($key, $defaults)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($key, 'This setting is not supported.');
            }
            if (\get_debug_type($value) !== \get_debug_type($defaults[$key])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($key, 'The setting has an invalid value type.');
            }
            $minimum = $key === 'learner_header_logo_size' ? 24 : ($key === 'learning_idle_timeout_seconds' ? 30 : 1);
            if (isset($limits[$key]) && ($value < $minimum || $value > $limits[$key])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($key, 'Enter a whole number between ' . $minimum . ' and ' . $limits[$key] . '.');
            }
            if (\is_string($value)) {
                $value = trim($value);
                if ($key === 'learner_navigation' && !\in_array($value, ['top', 'sidebar'], true)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ValidationException::forField($key, 'Choose top navigation or a left sidebar.');
                }
                if ($key === 'learner_header_display' && !\in_array($value, ['full', 'navigation', 'hidden'], true)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ValidationException::forField($key, 'Choose a full header, navigation only or a hidden header.');
                }
                if ($key === 'learner_header_density' && !\in_array($value, ['comfortable', 'compact'], true)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ValidationException::forField($key, 'Choose comfortable or compact spacing.');
                }
                $textLimit = match ($key) {
                    'learner_header_title' => 120,
                    'learner_header_tagline' => 180,
                    default => null,
                };
                if ($textLimit !== null && (mb_strlen($value) > $textLimit || preg_match('/[\r\n\t]/', $value))) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ValidationException::forField($key, 'Use one line of text with at most ' . $textLimit . ' characters.');
                }
                if (
                    strlen($value) > ($key === 'integrations_http_allowlist' ? 8192 : 2048)
                    || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)
                ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ValidationException::forField($key, 'The setting contains invalid text or is too long.');
                }
                if (str_ends_with($key, '_url') && $value !== '') {
                    // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
                    $parts = parse_url($value);
                    if (
                        !filter_var($value, FILTER_VALIDATE_URL) || !\in_array($parts['scheme'] ?? '', ['https', 'http'], true)
                        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                    ) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        throw ValidationException::forField($key, 'Enter an absolute HTTP or HTTPS URL without credentials or a fragment.');
                    }
                    if ($key === 'unsubscribe_base_url' && ($parts['scheme'] ?? '') !== 'https') {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        throw ValidationException::forField($key, 'The unsubscribe URL must use HTTPS.');
                    }
                }
                if ($key === 'envelope_domain' && $value !== '' && !filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ValidationException::forField($key, 'Enter a domain without a scheme or path.');
                }
                if ($key === 'integrations_http_allowlist') {
                    foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $host) {
                        if (!filter_var(ltrim($host, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                            throw ValidationException::forField($key, 'Enter host names separated by commas or new lines.');
                        }
                    }
                }
                if ($key === 'private_storage_dir' && !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ValidationException::forField($key, 'Use one folder name with letters, numbers, underscores or hyphens.');
                }
                if ($key === 'private_storage_path' && $value !== '') {
                    $path = str_replace('\\', '/', $value);
                    if (!preg_match('#^(?:/|[A-Za-z]:/)#', $path) || \in_array('..', explode('/', $path), true)) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        throw ValidationException::forField($key, 'Use an absolute filesystem path without traversal.');
                    }
                }
            }
            $values[$key] = $value;
        }
        $this->repository->replace($expectedRevision, $values);
        $this->snapshot = ['revision' => $expectedRevision + 1, 'values' => $values];
    }
}
