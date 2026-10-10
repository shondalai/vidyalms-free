<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetLessonForLearner;
use Shondalai\VidyaLMS\Core\Domain\Shared\MediaRef;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;

/**
 * GET /lessons/{id}
 *
 * The player view of one lesson. Every access rule and the learner
 * presentation of the content live in the use case; this handler relays its
 * shape and, on resource lessons, swaps each private storage path for a
 * signed, expiring URL. The raw path never leaves the server: with access
 * a file entry is {name, size_bytes, url}, without access it stays
 * {name, size_bytes}.
 */
final class GetLessonHandler implements HandlerInterface
{
    private const LESSON_TYPE_RESOURCE = 'resource';
    private const SIGNED_URL_TTL_SECONDS = 3600;

    public function __construct(
        private readonly GetLessonForLearner $getLessonForLearner,
        private readonly StoragePort $storage,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $view = $this->getLessonForLearner->execute($request->principal, $request->intPathParam('id'));

        if ($view['type'] === self::LESSON_TYPE_RESOURCE && \is_array($view['content'])) {
            $view['content'] = $this->signResourceFiles($view['content']);
        }

        return ApiResponse::ok($view);
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function signResourceFiles(array $content): array
    {
        $files = $content['files'] ?? null;

        if (!\is_array($files)) {
            return $content;
        }

        $signed = [];

        foreach ($files as $file) {
            if (!\is_array($file)) {
                continue;
            }

            $entry = [
                'name' => $file['name'] ?? null,
                'size_bytes' => $file['size_bytes'] ?? null,
            ];

            $path = $file['path'] ?? null;

            // Stored content may predate authoring validation. Never sign a
            // learner artifact, export or other non-authored storage reference.
            if (\is_string($path) && MediaRef::isAuthored($path)) {
                $entry['url'] = $this->storage->signedUrl($path, self::SIGNED_URL_TTL_SECONDS);
            }

            $signed[] = $entry;
        }

        $content['files'] = $signed;

        return $content;
    }
}
