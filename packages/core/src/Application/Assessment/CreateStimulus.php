<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Stimulus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StimulusRepository;

/**
 * Creates a shared passage on a course.
 */
final class CreateStimulus
{
    public function __construct(
        private readonly StimulusRepository $stimuli,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @param array<string, mixed>|null $media storage refs, never a signed URL
     */
    public function execute(
        Principal $principal,
        int $courseId,
        string $title,
        ?string $body = null,
        ?array $media = null,
        ?string $altText = null,
        ?string $longDescription = null,
    ): ManagedStimulus {
        $this->access->require($principal, $courseId, Capability::TEACH);

        $stimulus = $this->stimuli->add(new Stimulus(
            null,
            $courseId,
            StimulusRules::validate($title),
            $body,
            $media,
            $altText,
            $longDescription,
        ));

        return new ManagedStimulus($stimulus, 0);
    }
}
