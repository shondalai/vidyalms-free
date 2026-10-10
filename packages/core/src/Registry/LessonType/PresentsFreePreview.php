<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\LessonType;

/**
 * A lesson type that states what its free preview shows.
 *
 * A free-preview lesson is one its author chose to show to a visitor with no
 * enrolment, a guest or a signed-in person alike, so a preview is a third
 * answer beside "with access" and "without": the visitor is meant to see the
 * lesson, and is still not learning it. Progress, completion, attempts and
 * submissions all stay closed without an enrolment, and they are refused by
 * their own use cases rather than by this presentation.
 *
 * Probed with instanceof, because presentForLearner() is shipped and a new
 * method on LessonTypeInterface would break every third-party lesson type.
 *
 * Absent: the lesson is previewed through presentForLearner($content, false),
 * the no-access presentation. That fails closed: a type written before this
 * capability decided only what a reader without access may see, and guessing
 * that its preview may disclose more would hand out a join link or a
 * protected stream nobody chose to show.
 */
interface PresentsFreePreview
{
    /**
     * The learner-facing shape of the content for a visitor previewing it.
     * Never more than presentForLearner($content, true) returns.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function presentForPreview(array $content): array;
}
