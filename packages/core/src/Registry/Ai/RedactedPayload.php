<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * What a redactor produced, and the only thing a bridge request may be built
 * from.
 *
 * It carries the segments that may cross a model boundary and the manifest of
 * what they were, because a redactor that cannot produce a manifest is
 * redacting nothing that can later be shown to anybody. The manifest is what
 * the learner's right-to-know view and the dry run render, so it is part of
 * the object rather than something reconstructed afterwards from the segments.
 *
 * **The manifest and the wire cannot disagree, and that is now a property of
 * this object rather than of one redactor.** The design states it as an
 * invariant, and the shipped strict redactor honoured it; nothing held a
 * registered replacement to it, so a redactor emitting three segments while
 * declaring one manifest entry produced a request whose stated manifest
 * under-reported what left the site, and the run row, the dry run and the
 * learner's activity view all rendered the understatement. So the constructor
 * refuses a manifest that is not exactly the segment labels in emission
 * order, and `ofSegments()` is the factory for the ordinary case where a
 * redactor has nothing to add.
 *
 * A refusal here is a programmer error and is raised as one. The two callers
 * that run a registered redactor both catch it and turn it into a typed
 * refusal of the run, so a broken third-party redactor stops a run rather
 * than leaking through it or throwing out of an HTTP handler.
 */
final class RedactedPayload
{
    /** @var list<PromptSegment> */
    public readonly array $segments;

    /** @var list<string> */
    public readonly array $manifest;

    /**
     * @param list<PromptSegment> $segments
     * @param list<string>        $manifest provenance labels, in the order they were resolved
     */
    private function __construct(
        array $segments,
        array $manifest,
        public readonly string $redactorKey,
        public readonly string $redactionLevel,
    ) {
        $this->segments = $segments;
        $this->manifest = $manifest;
    }

    /**
     * @param list<PromptSegment> $segments
     * @param list<string>        $manifest must be the segment labels, in emission order
     *
     * @throws \InvalidArgumentException when the manifest and the segments disagree
     */
    public static function of(
        array $segments,
        array $manifest,
        string $redactorKey,
        string $redactionLevel,
    ): self {
        $payload = new self($segments, $manifest, $redactorKey, $redactionLevel);

        if ($manifest !== $payload->segmentLabels()) {
            throw new \InvalidArgumentException(sprintf(
                'The redactor "%s" declared a manifest of %d entry or entries for %d segment(s), so the '
                . 'manifest and the wire disagree. A manifest enumerates one entry per emitted segment, in '
                . 'emission order, because it is what the dry run and the learner are shown.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $redactorKey,
                \count($manifest),
                \count($segments),
            ));
        }

        return $payload;
    }

    /**
     * The ordinary case: the manifest is the segment labels, so a redactor
     * with nothing to add does not restate them and cannot get them wrong.
     *
     * @param list<PromptSegment> $segments
     */
    public static function ofSegments(
        array $segments,
        string $redactorKey,
        string $redactionLevel,
    ): self {
        $payload = new self($segments, [], $redactorKey, $redactionLevel);

        return new self($segments, $payload->segmentLabels(), $redactorKey, $redactionLevel);
    }

    /**
     * The provenance labels of the segments themselves, which is the manifest
     * a caller gets for free when the redactor supplied none of its own.
     *
     * @return list<string>
     */
    public function segmentLabels(): array
    {
        return array_values(array_map(
            static fn (PromptSegment $segment): string => $segment->label,
            $this->segments,
        ));
    }

    /**
     * Whether anything at all is left to send. A redactor that dropped
     * everything produced a payload the runner refuses rather than a call it
     * pays for.
     */
    public function isEmpty(): bool
    {
        return $this->segments === [];
    }
}
