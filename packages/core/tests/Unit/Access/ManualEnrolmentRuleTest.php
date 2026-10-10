<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Provider\EnrolmentRule\ManualEnrolmentRule;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class ManualEnrolmentRuleTest extends TestCase
{
    private ManualEnrolmentRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ManualEnrolmentRule(new StubTranslator());
    }

    public function testKeyAndLabel(): void
    {
        self::assertSame('manual', $this->rule->key());
        self::assertSame('vidyalms.enrolment_rule.manual', $this->rule->label());
    }

    public function testNeverAllowsSelfEnrolmentEvenForAFreePublishedCourse(): void
    {
        self::assertFalse($this->rule->allowsSelfEnrolment(
            Principal::user(42),
            ['state' => 'published', 'price_mode' => 'free'],
        ));
    }

    public function testNeverAllowsGuests(): void
    {
        self::assertFalse($this->rule->allowsSelfEnrolment(Principal::guest(), []));
    }
}
