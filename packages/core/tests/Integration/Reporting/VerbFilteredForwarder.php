<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwardContext;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwarderInterface;

/**
 * A warehouse package that wants one kind of fact and not the rest.
 *
 * It is the shape the interface's own docblock promises is possible: a
 * warehouse taking completions while an LRS takes everything, neither
 * filtering the other's traffic.
 */
final class VerbFilteredForwarder implements StatementForwarderInterface
{
    /** @var list<string> the statement ids this forwarder was handed */
    public array $seen = [];

    public function __construct(
        private readonly string $verb,
        private readonly string $key = 'completions-warehouse',
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'A warehouse that wants ' . $this->verb;
    }

    public function eventNames(): array
    {
        return [$this->verb];
    }

    public function forward(XapiStatement $statement, StatementForwardContext $context): void
    {
        unset($context);

        $this->seen[] = $statement->id;
    }
}
