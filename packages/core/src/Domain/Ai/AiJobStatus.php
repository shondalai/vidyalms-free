<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * Every status an ai_jobs row can be in.
 *
 * The first five follow import_jobs exactly, so the admin screen, the stall
 * detector reading updated_at and the abandon path are the same code. Two are
 * new, and each exists because money and regulation change what a status has to
 * be able to say:
 *
 * - **Deferred** exists because a ceiling policy defers rather than denies. A
 *   two hundred item build that trips the monthly ceiling at item forty had only
 *   Failed available, which left forty real drafts under a row that reads as a
 *   breakage. A defer moves the job here with a real resume instant, and the
 *   scheduled task re-arms itself under the same dedupe key, so a resume is one
 *   row rather than a second job.
 * - **Cancelled** is the Article 14 stop. Recording the exercise of a
 *   regulatory control as an ordinary failure would file the evidence in the
 *   same "Needs attention" list as a genuine breakage.
 *
 * There is deliberately no completed_with_problems here. An import's problems
 * are rows it rejected; an AI job's are findings on drafts a person has still to
 * review, and the review queue is where they are counted.
 */
enum AiJobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';

    /** A dry run's terminal status. A preview is not an applied run. */
    case Previewed = 'previewed';

    /** A ceiling policy said "not now", with a resume instant. */
    case Deferred = 'deferred';

    case Failed = 'failed';

    /** The site-wide stop, observed between units. */
    case Cancelled = 'cancelled';

    /**
     * Every status a run can end on. One list, because a reader that has to
     * enumerate them itself is a reader that will be missing the next one.
     *
     * Deferred is terminal for THIS attempt and not for the job: the row is
     * finished as far as the runner is concerned and the scheduler re-arms it,
     * which is exactly what makes the resume one row.
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Completed, self::Previewed, self::Deferred, self::Failed, self::Cancelled];
    }

    public function isFinished(): bool
    {
        return \in_array($this, self::terminal(), true);
    }

    /** Whether the scheduler should arm another attempt for this row. */
    public function resumes(): bool
    {
        return $this === self::Deferred;
    }
}
