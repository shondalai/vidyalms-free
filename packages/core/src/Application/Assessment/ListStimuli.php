<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Stimulus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StimulusRepository;

/**
 * The shared passages of one course, with the count of questions using each.
 */
final class ListStimuli
{
    public function __construct(
        private readonly StimulusRepository $stimuli,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @return list<ManagedStimulus>
     */
    public function execute(Principal $principal, int $courseId): array
    {
        $this->access->require($principal, $courseId, Capability::TEACH);

        $rows = $this->stimuli->forCourse($courseId);
        $counts = $this->stimuli->questionCounts(array_values(array_filter(array_map(
            static fn (Stimulus $stimulus): ?int => $stimulus->id,
            $rows,
        ))));

        $managed = [];

        foreach ($rows as $stimulus) {
            $managed[] = new ManagedStimulus($stimulus, $counts[$stimulus->id ?? 0] ?? 0);
        }

        return $managed;
    }
}
