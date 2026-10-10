<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Answer;

/**
 * One validity failure on one control. The summary links to controlId; the
 * message is repeated verbatim inline and in the summary, so the authored
 * constraint message survives rather than being replaced by a generic one.
 */
final class AnswerError
{
    public function __construct(
        public readonly string $controlId,
        public readonly string $kind,
        public readonly string $message,
        public readonly ?string $partId = null,
    ) {
        if (!ErrorKind::isWellFormed($kind)) {
            throw new \InvalidArgumentException(sprintf(
                'An error kind is either a core kind or a namespaced x_<vendor>_ key, got "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $kind,
            ));
        }
    }

    public static function of(string $controlId, ErrorKind $kind, string $message, ?string $partId = null): self
    {
        return new self($controlId, $kind->value, $message, $partId);
    }

    /**
     * @return array{control_id: string, kind: string, message: string, part_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'control_id' => $this->controlId,
            'kind' => $this->kind,
            'message' => $this->message,
            'part_id' => $this->partId,
        ];
    }
}
