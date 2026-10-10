<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StimulusRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * One shared passage, for the caller who may teach its course.
 */
final class GetStimulus
{
    public function __construct(
        private readonly StimulusRepository $stimuli,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $stimulusId): ManagedStimulus
    {
        $this->access->requireSignedIn($principal);

        $stimulus = $this->stimuli->byId($stimulusId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Stimulus', $stimulusId);

        $this->access->require($principal, $stimulus->courseId, Capability::TEACH);

        $counts = $this->stimuli->questionCounts([$stimulusId]);

        return new ManagedStimulus($stimulus, $counts[$stimulusId] ?? 0);
    }
}
