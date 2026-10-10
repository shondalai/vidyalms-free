<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeInterface;

/**
 * A second scheme, so a course can carry credit under two of them at once,
 * which is the reason certificate_credits is a child table.
 */
final class FakeSecondScheme implements CreditSchemeInterface
{
    public const KEY = 'fake.second';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Fake second';
    }

    public function unit(): string
    {
        return 'ceu';
    }

    public function amountFor(int $contactMinutes): string
    {
        return number_format($contactMinutes / 600, 2, '.', '');
    }

    public function requiredLearnerFields(): array
    {
        return [];
    }

    public function retentionYears(): int
    {
        return 7;
    }

    public function validate(array $params): array
    {
        return [];
    }

    public function currentShape(): int
    {
        return 1;
    }
}
