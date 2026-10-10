<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwardContext;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwarderInterface;

/**
 * An LRS package whose endpoint is down, which is the normal state of a
 * network integration rather than an exotic one.
 *
 * It exists to prove the containment: forwarding is optional by design, so a
 * forwarder that cannot reach its endpoint must never fail the attempt that
 * produced the statement.
 */
final class FailingStatementForwarder implements StatementForwarderInterface
{
    public function key(): string
    {
        return 'unreachable-lrs';
    }

    public function label(): string
    {
        return 'An LRS that is not there';
    }

    public function eventNames(): array
    {
        return [];
    }

    public function forward(XapiStatement $statement, StatementForwardContext $context): void
    {
        throw new \RuntimeException('connection refused');
    }
}
