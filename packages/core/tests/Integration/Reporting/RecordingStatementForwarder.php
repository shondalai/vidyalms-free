<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwardContext;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwarderInterface;

/**
 * A forwarder that keeps what it was handed, standing in for the LRS package
 * this seam exists for.
 */
final class RecordingStatementForwarder implements StatementForwarderInterface
{
    /** @var list<string> */
    public array $ids = [];

    /** @var list<int> */
    public array $courseIds = [];

    public function key(): string
    {
        return 'recording-lrs';
    }

    public function label(): string
    {
        return 'A forwarder that remembers';
    }

    public function eventNames(): array
    {
        return [];
    }

    public function forward(XapiStatement $statement, StatementForwardContext $context): void
    {
        $this->ids[] = $statement->id;
        $this->courseIds[] = $context->courseId;
    }
}
