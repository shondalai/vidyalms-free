<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Application\Assessment\SignedMedia;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\MediaBearingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;

/**
 * A hotspot-shaped type: an image over which regions are named, with the
 * storage ref carried on the projection and the signed URL minted at read
 * time.
 *
 * It stands in for select_media, order_media, associate_media, point_media,
 * signal_media, artifact_drawing and artifact_recording, all of which specify
 * a signed expiring media URL in their learner projection and none of which
 * could obtain one before MediaBearingInterface existed.
 */
final class MediaBearingType implements QuestionTypeInterface, MediaBearingInterface
{
    use DefaultDefinitionVersion;

    public const KEY = 'x_acme_hotspot';
    public const REF = 'questions/8811/heart.png';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Hotspot';
    }

    public function mediaRefs(array $presented): iterable
    {
        $ref = $presented['media'][SignedMedia::REF_KEY] ?? null;

        if (\is_string($ref) && $ref !== '') {
            yield 'media.' . SignedMedia::REF_KEY => $ref;
        }
    }

    public function validateDefinition(array $definition): void
    {
    }

    public function presentForLearner(array $definition): array
    {
        $media = $definition['media'] ?? [];

        return [
            'prompt' => $definition['prompt'] ?? '',
            // The projection freezes the ref, never a signature: a frozen
            // signature would be dead the moment it expired.
            'media' => \is_array($media) ? $media : [],
        ];
    }

    public function grade(array $definition, mixed $answer): GradeResult
    {
        return GradeResult::incorrect();
    }
}
