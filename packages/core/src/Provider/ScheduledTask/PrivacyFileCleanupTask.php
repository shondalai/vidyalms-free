<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Platform\PrivacyFileDeletion;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

final class PrivacyFileCleanupTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public function __construct(private readonly PrivacyFileDeletion $files) {}
    public function key(): string { return 'privacy.files.cleanup'; }
    public function run(ScheduledTaskRun $run): string
    {
        return implode(' ', $this->files->drain()) ?: 'Private file cleanup completed.';
    }
    public function binCount(): int { return 1; }
    public function periodBucket(\DateTimeImmutable $now): string { return $now->format('Y-m-d-H'); }
    public function periodicDedupeKey(string $periodBucket, int $binIndex): string { return $this->key() . ':' . $periodBucket; }
}
