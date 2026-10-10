<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Stimulus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StimulusRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * Full-state replacement of a shared passage.
 *
 * Attempts already begun are unaffected, and that is a property of the
 * manifest rather than a check here: the presentation each attempt carries
 * is frozen on attempt_questions, so correcting a passage never rewrites
 * what a learner was shown.
 */
final class UpdateStimulus
{
    public function __construct(
        private readonly StimulusRepository $stimuli,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @param array<string, mixed>|null $media
     */
    public function execute(
        Principal $principal,
        int $stimulusId,
        string $title,
        ?string $body = null,
        ?array $media = null,
        ?string $altText = null,
        ?string $longDescription = null,
    ): ManagedStimulus {
        $this->access->requireSignedIn($principal);

        $existing = $this->stimuli->byId($stimulusId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Stimulus', $stimulusId);

        $this->access->require($principal, $existing->courseId, Capability::TEACH);

        $updated = new Stimulus(
            $existing->id,
            $existing->courseId,
            StimulusRules::validate($title),
            $body,
            $media,
            $altText,
            $longDescription,
        );

        $this->stimuli->save($updated);

        $counts = $this->stimuli->questionCounts([$stimulusId]);

        return new ManagedStimulus($updated, $counts[$stimulusId] ?? 0);
    }
}
