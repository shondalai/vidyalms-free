<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting;

/**
 * One built xAPI statement, with the few facts a listener needs without
 * parsing the document.
 *
 * The core builds statements and emits them. It does not hold an LRS, does
 * not forward, and does not queue: a package that wants an LRS subscribes to
 * the event and posts what it is handed.
 */
final class XapiStatement
{
    /** @var array<string, mixed> */
    public readonly array $document;

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        public readonly string $id,
        public readonly string $verb,
        public readonly ?int $questionId,
        /**
         * When the statement says it happened, which is the moment the
         * event carries too: a listener that forwards these must not stamp
         * the fan-out time onto a fact that happened at submit.
         */
        public readonly \DateTimeImmutable $occurredAt,
        array $document,
    ) {
        $this->document = $document;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->document;
    }
}
