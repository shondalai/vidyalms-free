<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\HostFact;

final class HostFactTest extends TestCase
{
    public function testAStringOptionIsTheFact(): void
    {
        self::assertSame('every five minutes', HostFact::fromOption('every five minutes')->value());
    }

    public function testAnEmptyStringIsNoFact(): void
    {
        self::assertNull(HostFact::fromOption('')->value());
        self::assertNull(HostFact::text('')->value());
    }

    public function testAMissingOrUnusableOptionIsNoFact(): void
    {
        self::assertNull(HostFact::fromOption(null)->value());
        self::assertNull(HostFact::fromOption(42)->value());
        self::assertNull(HostFact::none()->value());
    }

    public function testAClosureOptionIsNotCalledUntilTheFactIsRead(): void
    {
        $calls = 0;
        $fact = HostFact::fromOption(function () use (&$calls): string {
            $calls++;

            return 'as the scheduled task is configured';
        });

        self::assertSame(0, $calls, 'Building the kernel must not pay for a screen nobody opened.');
        self::assertSame('as the scheduled task is configured', $fact->value());
        self::assertSame('as the scheduled task is configured', $fact->value());
        self::assertSame(1, $calls, 'Resolved at most once per kernel.');
    }

    public function testALookupThatThrowsIsNoFactRatherThanAFailedRequest(): void
    {
        $fact = HostFact::lazy(static fn (): ?string => throw new \RuntimeException('scheduler table missing'));

        self::assertNull($fact->value());
    }
}
