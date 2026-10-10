<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Provider\EnrolmentRule\OpenEnrolmentRule;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class OpenEnrolmentRuleTest extends TestCase
{
    private OpenEnrolmentRule $rule;

    protected function setUp(): void
    {
        $this->rule = new OpenEnrolmentRule(new StubTranslator());
    }

    public function testKeyAndLabel(): void
    {
        self::assertSame('open', $this->rule->key());
        self::assertSame('vidyalms.enrolment_rule.open', $this->rule->label());
    }

    public function testAllowsAnAuthenticatedUserIntoAPublishedFreeCourse(): void
    {
        self::assertTrue($this->rule->allowsSelfEnrolment(
            Principal::user(42),
            ['state' => 'published', 'price_mode' => 'free'],
        ));
    }

    public function testDeniesGuests(): void
    {
        self::assertFalse($this->rule->allowsSelfEnrolment(
            Principal::guest(),
            ['state' => 'published', 'price_mode' => 'free'],
        ));
    }

    public function testDeniesUnpublishedCourses(): void
    {
        self::assertFalse($this->rule->allowsSelfEnrolment(
            Principal::user(42),
            ['state' => 'draft', 'price_mode' => 'free'],
        ));
    }

    public function testDeniesPaidCourses(): void
    {
        self::assertFalse($this->rule->allowsSelfEnrolment(
            Principal::user(42),
            ['state' => 'published', 'price_mode' => 'paid'],
        ));
    }
}
