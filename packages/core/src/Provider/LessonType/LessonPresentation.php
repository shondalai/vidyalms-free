<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\LessonType;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/** Optional delivery overrides. Never expose unknown provider fields to learners. */
final class LessonPresentation
{
    private const FIELDS = [
        'video' => ['poster_url', 'transcript', 'resume', 'picture_in_picture', 'download', 'captions'],
        'reading' => ['reading_width', 'show_contents', 'allow_print'],
        'resource' => ['introduction', 'show_file_size', 'new_tab'],
    ];

    private const BOOLEANS = [
        'resume', 'picture_in_picture', 'download', 'show_contents', 'allow_print', 'show_file_size', 'new_tab',
    ];

    /** @param array<string, mixed> $content */
    public static function validate(array $content, string $type): void
    {
        if (!array_key_exists('presentation', $content)) {
            return;
        }

        $value = $content['presentation'];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw ValidationException::forField('presentation', 'Presentation must be an object.');
        }

        foreach (self::FIELDS[$type] ?? [] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $field = $value[$key];
            $valid = match (true) {
                in_array($key, self::BOOLEANS, true) => is_bool($field),
                $key === 'poster_url' => is_string($field) && ($field === '' || self::httpUrl($field)),
                $key === 'reading_width' => in_array($field, ['comfortable', 'wide', 'full'], true),
                $key === 'captions' => self::validCaptions($field),
                default => is_string($field) && mb_strlen($field) <= 100000,
            };
            if (!$valid) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField('presentation.' . $key, 'The presentation setting is invalid.');
            }
        }
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public static function present(array $content, string $type, bool $hasAccess): array
    {
        $value = $content['presentation'] ?? null;
        if (!$hasAccess || !is_array($value)) {
            return [];
        }

        $public = array_intersect_key($value, array_flip(self::FIELDS[$type] ?? []));
        if (isset($public['captions']) && is_array($public['captions'])) {
            $public['captions'] = array_map(
                static fn (mixed $track): array => is_array($track)
                    ? array_intersect_key($track, array_flip(['label', 'language', 'url'])) : [],
                $public['captions'],
            );
        }

        return ['presentation' => (object) $public];
    }

    private static function httpUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $value) === 1;
    }

    private static function validCaptions(mixed $tracks): bool
    {
        if (!is_array($tracks) || !array_is_list($tracks) || count($tracks) > 20) {
            return false;
        }
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                return false;
            }
            foreach (['label', 'language', 'url'] as $key) {
                if (!is_string($track[$key] ?? null) || trim($track[$key]) === '') {
                    return false;
                }
            }
            if (!self::httpUrl($track['url'])) {
                return false;
            }
        }
        return true;
    }
}
