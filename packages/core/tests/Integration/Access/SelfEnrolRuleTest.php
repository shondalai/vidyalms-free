<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Access\SelfEnrol;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\Enrolment\ContextAwareRefusal;
use Shondalai\VidyaLMS\Core\Tests\Support\Enrolment\RefusingEnrolmentRule;

/**
 * The course's own enrolment rule, which no registered provider could reach
 * before the column existed: the seam was there and nothing could enter it.
 */
final class SelfEnrolRuleTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $freeCourseId = 0;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->users->add(77, 'Nina Newcomer', 'nina@example.test');

        $course = $this->fixture->call('POST', '/manage/courses', $this->fixture->teacher(), [
            'title' => 'Open to all',
        ]);
        $this->freeCourseId = (int) ($course->data['id'] ?? 0);

        // A course publishes only with something in it, so the fixture builds
        // the smallest course that can be published rather than asserting on
        // one that never was.
        $module = $this->fixture->call(
            'POST',
            '/manage/courses/' . $this->freeCourseId . '/modules',
            $this->fixture->teacher(),
            ['title' => 'Only module'],
        );
        $this->fixture->call(
            'POST',
            '/manage/modules/' . (int) ($module->data['id'] ?? 0) . '/lessons',
            $this->fixture->teacher(),
            ['title' => 'Only lesson', 'type' => 'reading', 'content' => ['body' => '<p>Hello.</p>']],
        );

        $published = $this->fixture->call(
            'POST',
            '/manage/courses/' . $this->freeCourseId . '/publish',
            $this->fixture->teacher(),
        );
        self::assertSame(200, $published->status, (string) json_encode($published->data));
    }

    public function testTheDefaultRuleAdmitsALearner(): void
    {
        $learner = Principal::user(77);

        self::assertTrue($this->fixture->kernel->selfEnrol->isAvailableFor($learner, $this->freeCourseId));

        $enrolment = $this->fixture->kernel->selfEnrol->execute($learner, $this->freeCourseId);
        self::assertTrue($enrolment->isActive());
    }

    /**
     * The rule named on the row is the rule consulted, which is the whole
     * point of the column.
     */
    public function testTheCourseRuleIsTheOneConsulted(): void
    {
        $this->fixture->kernel->registries->enrolmentRules->register(new RefusingEnrolmentRule());
        $this->nameRule(RefusingEnrolmentRule::KEY);

        $learner = Principal::user(77);

        self::assertFalse($this->fixture->kernel->selfEnrol->isAvailableFor($learner, $this->freeCourseId));

        $this->expectException(ConflictException::class);
        $this->fixture->kernel->selfEnrol->execute($learner, $this->freeCourseId);
    }

    /**
     * The button and the POST answer from one resolution, which is exactly
     * what the method's docblock warns about when they do not.
     */
    public function testAContextAwareRuleAnswersBothTheButtonAndThePost(): void
    {
        $this->fixture->kernel->registries->enrolmentRules->register(new ContextAwareRefusal());
        $this->nameRule(ContextAwareRefusal::KEY);

        $learner = Principal::user(77);

        self::assertFalse($this->fixture->kernel->selfEnrol->isAvailableFor($learner, $this->freeCourseId));

        $this->expectException(ConflictException::class);
        $this->fixture->kernel->selfEnrol->execute($learner, $this->freeCourseId);
    }

    /**
     * A course naming a rule whose package was removed closes rather than
     * opening, and it does so as a refusal rather than as an exception
     * escaping into a route.
     */
    public function testAMissingRuleRefusesRatherThanThrowingOut(): void
    {
        $this->nameRule('acme.gone');

        $learner = Principal::user(77);

        self::assertFalse($this->fixture->kernel->selfEnrol->isAvailableFor($learner, $this->freeCourseId));

        $this->expectException(ConflictException::class);
        $this->fixture->kernel->selfEnrol->execute($learner, $this->freeCourseId);
    }

    public function testTheColumnDefaultsToTheOpenRule(): void
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('courses')->select(['enrolment_rule'])->where('id', '=', $this->freeCourseId),
        );

        self::assertNotNull($row);
        self::assertSame(SelfEnrol::DEFAULT_RULE_KEY, (string) $row['enrolment_rule']);
    }

    private function nameRule(string $ruleKey): void
    {
        $this->fixture->env->database->update(
            'courses',
            ['enrolment_rule' => $ruleKey],
            ['id' => $this->freeCourseId],
        );
    }
}
