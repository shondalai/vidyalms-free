<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Ai\AiGroundingIndexer;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * One per course: re-indexes its authored text into citable chunks.
 *
 * It is keyed on the course rather than run periodically, because re-indexing is
 * a response to an edit and not to a clock: a site with four thousand courses
 * would otherwise re-read all of them nightly to find the three that changed.
 *
 * Every chunk it writes is untrusted, which is the whole safety contribution of
 * the chunker, and its keys are content hashes, which is what makes a citation
 * stored in March still resolve in June.
 */
final class AiChunkTask implements ScheduledTaskHandlerInterface
{
    public const TYPE = 'vidyalms.ai.chunk';

    public function __construct(private readonly AiGroundingIndexer $indexer)
    {
    }

    public function key(): string
    {
        return self::TYPE;
    }

    /** One pending re-index per course, so twenty edits queue one run. */
    public static function dedupeKeyFor(int $courseId): string
    {
        return self::TYPE . ':' . $courseId;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $courseId = $run->int('course_id');

        if ($courseId <= 0) {
            return 'ai_chunk course=0 status=skipped';
        }

        $result = $this->indexer->reindexCourse($courseId);

        return sprintf(
            'ai_chunk course=%d lessons=%d chunks=%d trust=untrusted',
            $courseId,
            $result['lessons'],
            $result['chunks'],
        );
    }
}
