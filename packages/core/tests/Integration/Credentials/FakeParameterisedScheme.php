<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Credit\ComputesFromParams;
use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeInterface;

/**
 * A scheme whose amount is not a pure function of minutes, which is what
 * NASBA's elected increments and ACCME's authored points both are.
 */
final class FakeParameterisedScheme implements CreditSchemeInterface, ComputesFromParams
{
    public const KEY = 'fake.parameterised';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Fake parameterised';
    }

    public function unit(): string
    {
        return 'cpe';
    }

    public function amountFor(int $contactMinutes): string
    {
        // The conservative half-credit default, which is what the row stored
        // while the capability had no call site.
        return number_format(floor($contactMinutes / 50 * 2) / 2, 2, '.', '');
    }

    public function amountFromParams(array $params): string
    {
        $minutes = is_numeric($params['contact_minutes'] ?? null) ? (int) $params['contact_minutes'] : 0;
        $step = ($params['increment'] ?? 'half') === 'fifth' ? 0.2 : 0.5;

        return number_format(floor($minutes / 50 / $step) * $step, 2, '.', '');
    }

    public function requiredLearnerFields(): array
    {
        return [];
    }

    public function retentionYears(): int
    {
        return 5;
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
