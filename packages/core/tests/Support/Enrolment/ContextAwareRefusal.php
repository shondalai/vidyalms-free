<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Enrolment;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\Context\EnrolmentContext;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\ContextAwareEnrolmentRule;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentDecision;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleInterface;

/**
 * A rule of the context-aware kind, which can say why.
 *
 * Its boolean method deliberately says yes: the point of the test that uses
 * it is that the context-aware answer is the one consulted, so the two must
 * disagree.
 */
final class ContextAwareRefusal implements EnrolmentRuleInterface, ContextAwareEnrolmentRule
{
    public const KEY = 'test.context_aware';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Context aware';
    }

    public function allowsSelfEnrolment(Principal $principal, array $course): bool
    {
        return true;
    }

    public function allowsSelfEnrolmentIn(EnrolmentContext $context): EnrolmentDecision
    {
        return EnrolmentDecision::refuse('test.closed', ['course_id' => $context->courseId()]);
    }
}
