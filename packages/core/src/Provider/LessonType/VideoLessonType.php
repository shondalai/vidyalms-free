<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\LessonType;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\LessonType\DefaultContentVersion;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;

final class VideoLessonType implements LessonTypeInterface, PresentsFreePreview
{
    use DefaultContentVersion;

    public const KEY = 'video';

    private const PROVIDERS = ['youtube', 'vimeo', 'url'];

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
        return $this->translator->translate('vidyalms.lesson_type.video');
    }

    public function completionMode(): string
    {
        return self::COMPLETION_ON_VIEW;
    }

    public function validateContent(array $content): void
    {
        LessonPresentation::validate($content, self::KEY);
        $provider = $content['provider'] ?? null;

        if (!\is_string($provider) || !\in_array($provider, self::PROVIDERS, true)) {
            throw ValidationException::forField('provider', 'The provider must be youtube, vimeo or url.');
        }

        $url = $content['url'] ?? null;

        if (!\is_string($url) || !$this->isHttpUrl($url)) {
            throw ValidationException::forField('url', 'A valid http or https URL is required.');
        }

        if (\array_key_exists('caption_url', $content) && $content['caption_url'] !== null) {
            $captionUrl = $content['caption_url'];

            if (!\is_string($captionUrl) || !$this->isHttpUrl($captionUrl)) {
                throw ValidationException::forField('caption_url', 'The caption URL must be a valid http or https URL.');
            }
        }
    }

    /**
     * The provider name survives without access because it is metadata a
     * catalogue page may legitimately show; the URLs do not, because they are
     * the content and a video URL is playable by anyone who holds it.
     */
    public function presentForLearner(array $content, bool $hasAccess): array
    {
        return [
            'provider' => $content['provider'] ?? null,
            'url' => $hasAccess ? ($content['url'] ?? null) : null,
            'caption_url' => $hasAccess ? ($content['caption_url'] ?? null) : null,
        ] + LessonPresentation::present($content, self::KEY, $hasAccess);
    }

    /**
     * The video is the preview. The author marked it to be watched without
     * an enrolment, so the playable URLs travel; the player sends no
     * heartbeat without an enrolment, so watching it records nothing.
     */
    public function presentForPreview(array $content): array
    {
        return $this->presentForLearner($content, true);
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return preg_match('/^https?:\/\//i', $url) === 1;
    }
}
