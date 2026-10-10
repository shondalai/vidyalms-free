<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * The resolved form of a request, and the object a redactor is actually
 * handed.
 *
 * It carries the chunk text already fetched with each chunk's trust level,
 * the values of the facts the task declared, and the authored options, with
 * every entry carrying its own provenance label ("lesson:88",
 * "submission:412", "imported:qti", "fact:item.facility"). The provenance is
 * what the redaction manifest, the learner's right-to-know view and the dry
 * run all render, so an entry with no label is a gap in three surfaces at
 * once.
 */
final class AiTaskPayload
{
    /**
     * @param list<array{provenance: string, trust: string, text: string}> $chunks
     * @param array<string, mixed>                                        $facts
     * @param array<string, mixed>                                        $options
     */
    private function __construct(
        public readonly AiTaskRequest $request,
        public readonly array $chunks,
        public readonly array $facts,
        public readonly array $options,
    ) {
    }

    /**
     * @param list<array{provenance: string, trust: string, text: string}> $chunks
     * @param array<string, mixed>                                        $facts
     * @param array<string, mixed>                                        $options
     */
    public static function create(
        AiTaskRequest $request,
        array $chunks = [],
        array $facts = [],
        array $options = [],
    ): self {
        return new self($request, $chunks, $facts, $options);
    }

    /**
     * The same payload with every fact the task did not declare removed.
     *
     * It is applied by the two callers that run a registered redactor rather
     * than inside the shipped redactor, because a rule enforced by one
     * provider is a rule a replacement provider does not have. A task cannot
     * widen at run time what it declared at registration, and now it cannot
     * be widened for it either.
     *
     * @param list<string> $declaredFactKeys
     */
    public function onlyFacts(array $declaredFactKeys): self
    {
        $facts = [];

        foreach ($this->facts as $key => $value) {
            if (\in_array((string) $key, $declaredFactKeys, true)) {
                $facts[(string) $key] = $value;
            }
        }

        return $facts === $this->facts ? $this : new self($this->request, $this->chunks, $facts, $this->options);
    }

    /**
     * Every provenance label in this payload, which is the manifest a
     * redaction record enumerates one entry per.
     *
     * @return list<string>
     */
    public function provenanceLabels(): array
    {
        $labels = [];

        foreach ($this->chunks as $chunk) {
            $labels[] = $chunk['provenance'];
        }

        foreach (array_keys($this->facts) as $fact) {
            $labels[] = 'fact:' . $fact;
        }

        return $labels;
    }
}
