<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * One finding on one draft.
 *
 * A row and not an entry in a JSON list on the draft, because findings are read
 * from OUTSIDE the draft. "How many blocking duplicate findings did item
 * generation produce this month" and "which check is producing the most noise"
 * are the two questions that tune the pipeline, and a JSON column answers both
 * with a scan.
 *
 * A check returns findings and never a boolean. A check that returned a boolean
 * would eventually be wired to auto-approve, which is the outcome the design
 * forbids; one that returns findings cannot be.
 */
final class AiDraftFindingRecord
{
    public const SEVERITY_BLOCKING = 'blocking';
    public const SEVERITY_ADVISORY = 'advisory';
    public const SEVERITY_INFO = 'info';

    /** @var array<string, mixed>|null */
    public readonly ?array $evidence;

    /**
     * @param array<string, mixed>|null $evidence
     */
    private function __construct(
        public readonly int $id,
        public readonly int $draftId,
        public readonly string $checkKey,
        public readonly string $severity,
        public readonly string $code,
        public readonly ?string $path,
        public readonly string $message,
        ?array $evidence,
        public readonly int $evidenceVersion,
        public readonly bool $acknowledged,
        public readonly ?int $acknowledgedBy,
    ) {
        $this->evidence = $evidence;
    }

    /**
     * @param array<string, mixed>|null $evidence
     */
    public static function of(
        int $draftId,
        string $checkKey,
        string $severity,
        string $code,
        string $message,
        ?string $path = null,
        ?array $evidence = null,
        int $evidenceVersion = 1,
        int $id = 0,
        bool $acknowledged = false,
        ?int $acknowledgedBy = null,
    ): self {
        return new self(
            $id,
            $draftId,
            $checkKey,
            \in_array($severity, [self::SEVERITY_BLOCKING, self::SEVERITY_ADVISORY, self::SEVERITY_INFO], true)
                ? $severity
                : self::SEVERITY_ADVISORY,
            $code,
            $path,
            $message,
            $evidence,
            $evidenceVersion,
            $acknowledged,
            $acknowledgedBy,
        );
    }

    public function isBlocking(): bool
    {
        return $this->severity === self::SEVERITY_BLOCKING;
    }

    public function isAdvisory(): bool
    {
        return $this->severity === self::SEVERITY_ADVISORY;
    }
}
