<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Enrolment;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleInterface;

/**
 * A rule of the shipped kind, which answers a bare boolean.
 */
final class RefusingEnrolmentRule implements EnrolmentRuleInterface
{
    public const KEY = 'test.refusing';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Refusing';
    }

    public function allowsSelfEnrolment(Principal $principal, array $course): bool
    {
        return false;
    }
}
