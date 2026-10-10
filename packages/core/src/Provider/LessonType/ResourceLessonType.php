<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\LessonType;

use Shondalai\VidyaLMS\Core\Domain\Shared\MediaRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\LessonType\DefaultContentVersion;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;

final class ResourceLessonType implements LessonTypeInterface, PresentsFreePreview
{
    use DefaultContentVersion;

    public const KEY = 'resource';

    public function __construct(
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.lesson_type.resource');
    }

    public function completionMode(): string
    {
        return self::COMPLETION_ON_VIEW;
    }

    public function validateContent(array $content): void
    {
        LessonPresentation::validate($content, self::KEY);
        $files = $content['files'] ?? null;

        if (!\is_array($files) || $files === []) {
            throw ValidationException::forField('files', 'At least one file is required.');
        }

        foreach (array_values($files) as $index => $file) {
            if (!\is_array($file)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField(sprintf('files.%d', $index), 'Each file must be an object.');
            }

            $path = $file['path'] ?? null;

            if (!\is_string($path) || trim($path) === '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField(sprintf('files.%d.path', $index), 'A non-empty storage path is required.');
            }

            if (!MediaRef::isAuthored($path)) {
                throw ValidationException::forField(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('files.%d.path', $index),
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    'The file must reference authored media under: ' . MediaRef::authoredPrefixes(),
                );
            }

            $name = $file['name'] ?? null;

            if (!\is_string($name) || trim($name) === '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField(sprintf('files.%d.name', $index), 'A non-empty file name is required.');
            }

            if (\array_key_exists('size_bytes', $file) && $file['size_bytes'] !== null) {
                $size = $file['size_bytes'];

                if (!\is_int($size) || $size < 0) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ValidationException::forField(sprintf('files.%d.size_bytes', $index), 'The size must be a non-negative integer.');
                }
            }
        }
    }

    /**
     * Storage paths are private; a visitor without access sees names and
     * sizes only, so no private path ever leaks to non-enrolled visitors.
     */
    public function presentForLearner(array $content, bool $hasAccess): array
    {
        $files = $content['files'] ?? [];
        $presented = [];

        if (\is_array($files)) {
            foreach ($files as $file) {
                if (!\is_array($file)) {
                    continue;
                }

                $entry = [
                    'name' => $file['name'] ?? null,
                    'size_bytes' => $file['size_bytes'] ?? null,
                ];

                if ($hasAccess) {
                    $entry['path'] = $file['path'] ?? null;
                }

                $presented[] = $entry;
            }
        }

        return ['files' => $presented] + LessonPresentation::present($content, self::KEY, $hasAccess);
    }

    /**
     * A previewed resource is downloadable: the author marked the handout as
     * something to give away. The path reaches the lesson route exactly as
     * it does for an enrolled learner, and the route swaps it for a signed,
     * expiring URL, so the raw path still never leaves the server.
     */
    public function presentForPreview(array $content): array
    {
        return $this->presentForLearner($content, true);
    }
}
