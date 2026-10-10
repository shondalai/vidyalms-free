<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

/**
 * Which presentation of a lesson's content the player view carries.
 *
 * Three answers and not a boolean, because a free preview is neither access
 * nor its absence. It used to travel as "no access", so every lesson type
 * redacted exactly the lessons their authors had chosen to show: a guest on a
 * free-preview video was handed a null url.
 */
enum LessonContentAccess
{
    /** An active or completed enrolment, or an authoring capability. */
    case Full;

    /** No usable enrolment, on a lesson its author marked free preview. */
    case Preview;

    /** The caller may open the lesson but a restriction keeps its content closed. */
    case Withheld;
}
