<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

final class CourseSettingsContractTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testEveryDeclaredSettingsFieldPersistsAndReadsBackThroughTheContract(): void
    {
        $path = $this->fixture->path('/manage/courses/{course}');
        $body = [
            'enrolment_rule' => 'manual',
            'progression' => 'linear',
            'timezone' => 'Asia/Kolkata',
            'starts_at' => '2026-10-01 04:30:00',
            'late_policy_key' => 'none',
            'late_policy_params' => ['grace_minutes' => 15],
        ];

        $patched = $this->fixture->call('PATCH', $path, $this->fixture->teacher(), $body);

        self::assertSame(200, $patched->status);
        self::assertNotNull($patched->data);
        foreach ($body as $field => $value) {
            self::assertSame($value, $patched->data[$field], $field);
        }

        $read = $this->fixture->call('GET', $path, $this->fixture->teacher());
        self::assertSame(200, $read->status);
        self::assertNotNull($read->data);
        foreach ($body as $field => $value) {
            self::assertSame($value, $read->data[$field], $field);
        }

        $row = $this->fixture->env->database->selectOne(
            Query::from('courses')->where('id', '=', $this->fixture->courseId),
        );
        self::assertNotNull($row);
        self::assertSame('manual', $row['enrolment_rule']);
        self::assertSame('linear', $row['progression']);
        self::assertSame('Asia/Kolkata', $row['timezone']);
        self::assertSame('2026-10-01 04:30:00', $row['starts_at']);
        self::assertSame('none', $row['late_policy_key']);
        self::assertEquals(1, $row['late_policy_params_version']);
        $stored = json_decode((string) $row['late_policy_params'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, StoredShape::versionOf($stored));
        self::assertSame(['grace_minutes' => 15], StoredShape::bodyOf($stored));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidSettings(): array
    {
        return [
            'unregistered enrolment rule' => [['enrolment_rule' => 'gone']],
            'long enrolment rule' => [['enrolment_rule' => str_repeat('x', 31)]],
            'progression' => [['progression' => 'random']],
            'timezone' => [['timezone' => 'Mars/Olympus']],
            'starts at type' => [['starts_at' => 42]],
            'starts at format' => [['starts_at' => '2026-10-01T04:30:00Z']],
            'unregistered late policy' => [['late_policy_key' => 'gone']],
            'late params list' => [['late_policy_params' => ['first', 'second']]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidSettings')]
    public function testDeclaredSettingsStillApplyStrictContractAndRegistryValidation(array $body): void
    {
        $response = $this->fixture->call(
            'PATCH',
            $this->fixture->path('/manage/courses/{course}'),
            $this->fixture->teacher(),
            $body,
        );

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertIsArray($response->data['errors']);
    }
}
