<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\LessonType;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One kind of lesson. The free build ships five (video embed, reading,
 * resource download, assignment, quiz); Pro adds live sessions, protected
 * video, audio; Business adds SCORM, xAPI and cmi5.
 */
interface LessonTypeInterface extends ProviderInterface
{
    /** Progress marks the lesson complete when the learner has viewed it. */
    public const COMPLETION_ON_VIEW = 'view';

    /** A passing quiz attempt completes the lesson. */
    public const COMPLETION_ON_PASS = 'pass';

    /** A graded submission completes the lesson. */
    public const COMPLETION_ON_SUBMISSION = 'submission';

    public function label(): string;

    /**
     * How this lesson type participates in the completion engine. One of the
     * COMPLETION_* constants.
     */
    public function completionMode(): string;

    /**
     * Validates authored lesson content, throwing
     * Domain\Shared\ValidationException with field errors when invalid.
     *
     * @param array<string, mixed> $content
     */
    public function validateContent(array $content): void;

    /**
     * The learner-facing shape of the content. Implementations must withhold
     * anything a learner without access may not see (download paths, join
     * links); $hasAccess is false when a restriction keeps the content
     * closed. A free preview is not that case: it is asked through the
     * PresentsFreePreview capability, and falls back to $hasAccess false
     * only for a type that does not declare it.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function presentForLearner(array $content, bool $hasAccess): array;

    /**
     * The version of the content shape this provider writes today. See
     * QuestionTypeInterface::definitionVersion() for why a stored
     * provider-shaped payload carries one; DefaultContentVersion is the shape
     * that has never changed.
     */
    public function contentVersion(): int;

    /**
     * Brings content written by an older version of this provider up to
     * contentVersion(). Called by the repository on read, so validation and
     * presentation only ever deal with the current shape. $fromVersion is 0
     * for a row stored before versions existed.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function upgradeContent(array $content, int $fromVersion): array;
}
