<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\LessonType;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\LessonType\DefaultContentVersion;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;

final class ReadingLessonType implements LessonTypeInterface, PresentsFreePreview
{
    use DefaultContentVersion;

    public const KEY = 'reading';

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
        return $this->translator->translate('vidyalms.lesson_type.reading');
    }

    public function completionMode(): string
    {
        return self::COMPLETION_ON_VIEW;
    }

    public function validateContent(array $content): void
    {
        LessonPresentation::validate($content, self::KEY);
        $body = $content['body'] ?? null;

        if (!\is_string($body) || trim($body) === '') {
            throw ValidationException::forField('body', 'A non-empty HTML body is required.');
        }
    }

    /**
     * The body is the whole lesson, so a reader without access gets the
     * placeholder shape and not the prose.
     *
     * The parameter was ignored here, which made the gate advisory on the one
     * surface that is supposed to withhold content: a drip-locked reading
     * lesson correctly reported blocked in its access block and returned its
     * full body in the same response.
     */
    public function presentForLearner(array $content, bool $hasAccess): array
    {
        return [
            'body' => $hasAccess ? ($content['body'] ?? null) : null,
        ] + LessonPresentation::present($content, self::KEY, $hasAccess);
    }

    /**
     * The body is the lesson, so a preview without it is a preview of
     * nothing; that is what every visitor was shown ("Nothing to read yet")
     * while previews travelled as "no access".
     */
    public function presentForPreview(array $content): array
    {
        return $this->presentForLearner($content, true);
    }
}
