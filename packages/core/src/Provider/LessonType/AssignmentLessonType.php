<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\LessonType;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\LessonType\DefaultContentVersion;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;

/**
 * The assignment lesson type. Authored content is only the instructions;
 * everything gradable lives in the Assignments context. A graded submission
 * completes the lesson.
 */
final class AssignmentLessonType implements LessonTypeInterface, PresentsFreePreview
{
    use DefaultContentVersion;

    public const KEY = 'assignment';

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
        return $this->translator->translate('vidyalms.lesson_type.assignment');
    }

    public function completionMode(): string
    {
        return self::COMPLETION_ON_SUBMISSION;
    }

    public function validateContent(array $content): void
    {
        if (\array_key_exists('instructions', $content) && $content['instructions'] !== null && !\is_string($content['instructions'])) {
            throw ValidationException::forField('instructions', 'Instructions must be a string.');
        }
    }

    /**
     * The instructions are the brief, so a reader without access does not
     * get them: the whole point of a gated assignment is that the task is
     * not disclosed before it opens.
     */
    public function presentForLearner(array $content, bool $hasAccess): array
    {
        $instructions = $content['instructions'] ?? null;

        return [
            'instructions' => $hasAccess && \is_string($instructions) ? $instructions : null,
        ];
    }

    /**
     * A previewed assignment shows its brief. Marking it free preview is the
     * author's decision to disclose the task, and nothing gradable is in
     * lesson content. Submitting still needs an active enrolment, which
     * SubmitAssignment requires on its own account. Recorded in
     * 13-learning-flow-and-credentials.md.
     */
    public function presentForPreview(array $content): array
    {
        return $this->presentForLearner($content, true);
    }
}
