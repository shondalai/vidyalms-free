<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * One queue row, as a handler sees it.
 *
 * The job id is here because a handler may write an artefact named after the
 * row it came from (the report generator does), and the principal because a
 * queue row names the actor a job runs as. A handler that needs neither
 * ignores both.
 */
final class ScheduledTaskRun
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $jobId,
        public readonly array $payload,
        public readonly Principal $principal,
    ) {
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->payload[$key] ?? null;

        return \is_int($value) || \is_string($value) || \is_float($value) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->payload[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function arrayValue(string $key): array
    {
        $value = $this->payload[$key] ?? null;

        return \is_array($value) ? $value : [];
    }
}
