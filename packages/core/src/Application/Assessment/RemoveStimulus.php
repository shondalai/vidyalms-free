<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StimulusRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * Removes a shared passage no question uses.
 *
 * One still referenced refuses with the count, because removing it would
 * leave those questions with a dangling reference and their screens without
 * a passage. This is the one authoring row in the assessment surface that is
 * genuinely deleted rather than archived, and it is safe precisely because
 * the refusal makes it unreachable while anything points at it: an attempt
 * already served carries its own frozen copy of what the learner saw.
 */
final class RemoveStimulus
{
    public function __construct(
        private readonly StimulusRepository $stimuli,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $stimulusId): void
    {
        $this->access->requireSignedIn($principal);

        $stimulus = $this->stimuli->byId($stimulusId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Stimulus', $stimulusId);

        $this->access->require($principal, $stimulus->courseId, Capability::TEACH);

        $used = $this->stimuli->questionCounts([$stimulusId])[$stimulusId] ?? 0;

        if ($used > 0) {
            throw ConflictException::with(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('This passage is used by %d question(s) and cannot be removed.', $used),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                ['question_count' => $used],
            );
        }

        $this->stimuli->remove($stimulusId);
    }
}
