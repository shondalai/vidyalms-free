<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * A shared passage: the reading text, the case, the diagram that several
 * questions are about.
 *
 * It is an entity and never a zero-point pseudo question. A "description" or
 * "text only" pseudo question pollutes item counts, item statistics and the
 * answered display, and duplicates the passage once per question.
 *
 * The accessibility alternatives live inside the row rather than beside it,
 * so a round trip through another system preserves them.
 *
 * The authoring rule stated beside the form: if the parts can be answered,
 * scored and reported independently, use a stimulus; if they only make sense
 * together and share one mark, use a composite item.
 */
final class Stimulus
{
    /** @var array<string, mixed>|null */
    public readonly ?array $media;

    /**
     * @param array<string, mixed>|null $media storage refs, never a signed URL
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $courseId,
        public readonly string $title,
        public readonly ?string $body = null,
        ?array $media = null,
        public readonly ?string $altText = null,
        public readonly ?string $longDescription = null,
    ) {
        $this->media = $media;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->courseId, $this->title, $this->body, $this->media, $this->altText, $this->longDescription);
    }
}
