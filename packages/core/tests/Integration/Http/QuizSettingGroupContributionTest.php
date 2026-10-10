<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Tests\Support\ProctoringSettingGroup;
use Shondalai\VidyaLMS\Core\Tests\Support\SilentSettingGroup;

/**
 * The quiz settings form is a registry all the way out, not only inside its
 * fields.
 *
 * Its five groups and their field lists used to be a fixed method, so a
 * package could contribute an option to one of the four provider-keyed
 * fields the core declares and could not contribute a setting of its own
 * without editing the core. These prove the seam: a registration puts a
 * group on the form, the free build with nothing registered answers exactly
 * what it always did, and a contribution cannot displace a core group by
 * claiming its key.
 */
final class QuizSettingGroupContributionTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testAFreeSiteAnswersTheFiveCoreGroupsAndNoMore(): void
    {
        self::assertSame(
            ['behaviour', 'presentation', 'availability', 'grading', 'item_session'],
            $this->groupKeys(),
        );
    }

    public function testARegisteredGroupAppearsOnTheFormWithNoCoreEdit(): void
    {
        $this->fixture->kernel->registries->quizSettingGroups->register(new ProctoringSettingGroup());

        $data = $this->schema();
        self::assertSame(
            ['behaviour', 'presentation', 'availability', 'grading', 'item_session', 'proctoring'],
            array_map(static fn (array $group): string => $group['key'], $data['groups']),
        );

        $contributed = $data['groups'][5];
        self::assertSame(['key', 'label', 'description', 'fields'], array_keys($contributed));
        self::assertSame('Proctoring', $contributed['label']);
        self::assertSame('lockdown', $contributed['fields'][0]['key']);
    }

    public function testTheGroupIsToldWhichQuizItIsDescribing(): void
    {
        $group = new ProctoringSettingGroup();
        $this->fixture->kernel->registries->quizSettingGroups->register($group);

        $this->schema();

        self::assertSame([$this->fixture->quizId], $group->quizIds());
        self::assertSame(['highest'], $group->aggregations());
    }

    public function testAGroupWithNothingToSayIsAbsentRatherThanEmpty(): void
    {
        $this->fixture->kernel->registries->quizSettingGroups->register(new SilentSettingGroup());

        self::assertNotContains('silent', $this->groupKeys());
    }

    public function testAContributionCannotClaimACoreGroupKey(): void
    {
        $this->fixture->kernel->registries->quizSettingGroups->register(
            new ProctoringSettingGroup('grading'),
        );

        $keys = $this->groupKeys();

        self::assertSame(
            ['behaviour', 'presentation', 'availability', 'grading', 'item_session'],
            $keys,
        );
        self::assertCount(1, array_keys($keys, 'grading', true));
    }

    /**
     * @return list<string>
     */
    private function groupKeys(): array
    {
        return array_map(
            static fn (array $group): string => $group['key'],
            $this->schema()['groups'],
        );
    }

    /**
     * @return array{quiz_id: int, groups: list<array<string, mixed>>}
     */
    private function schema(): array
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/quizzes/' . $this->fixture->quizId . '/settings-schema',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);

        /** @var array{quiz_id: int, groups: list<array<string, mixed>>} $data */
        $data = $response->data;

        return $data;
    }
}
