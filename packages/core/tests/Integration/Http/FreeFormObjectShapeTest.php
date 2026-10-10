<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;

/**
 * openapi.yaml declares a handful of fields as free-form maps
 * ("type: object", "additionalProperties: true") and the generated SDK types
 * each of them as a record. PHP has one array type for both a map and a
 * list, so an empty payload leaves as "[]" unless the presenter says
 * otherwise, and the answer then contradicts its own schema.
 *
 * This walks whole responses rather than naming fields one at a time: every
 * key below must decode as a JSON object wherever it appears, at any depth.
 */
final class FreeFormObjectShapeTest extends TestCase
{
    /**
     * The response fields openapi.yaml declares as free-form objects.
     *
     * @var list<string>
     */
    private const OBJECT_FIELDS = ['content', 'definition', 'presentation', 'options', 'totals'];

    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testALessonWithNoStoredPayloadPresentsContentAsAnObject(): void
    {
        $response = $this->fixture->call(
            'GET',
            $this->fixture->path('/manage/lessons/{assignment_lesson}'),
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertInstanceOf(\stdClass::class, $response->data['content']);
        self::assertStringContainsString('"content":{}', self::encode($response));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function responsesCarryingFreeFormMaps(): array
    {
        return [
            'authoring curriculum' => ['GET', '/manage/courses/{course}/curriculum'],
            'authoring lesson' => ['GET', '/manage/lessons/{lesson}'],
            'assignment lesson' => ['GET', '/manage/lessons/{assignment_lesson}'],
            'quiz authoring' => ['GET', '/manage/quizzes/{quiz}'],
            'course gradebook' => ['GET', '/manage/courses/{course}/gradebook'],
            'learner lesson' => ['GET', '/lessons/{lesson}'],
            'learner curriculum' => ['GET', '/courses/{course}/curriculum'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('responsesCarryingFreeFormMaps')]
    public function testEveryFreeFormMapDecodesAsAnObject(string $method, string $pattern): void
    {
        $response = $this->fixture->call($method, $this->fixture->path($pattern), $this->fixture->teacher());

        self::assertSame(200, $response->status, $method . ' ' . $pattern);

        $decoded = json_decode(self::encode($response), false, 512, \JSON_THROW_ON_ERROR);
        self::assertObjectsAllTheWayDown($decoded, $method . ' ' . $pattern);
    }

    private static function encode(ApiResponse $response): string
    {
        return json_encode($response->data, \JSON_THROW_ON_ERROR);
    }

    private static function assertObjectsAllTheWayDown(mixed $node, string $where, string $path = '$'): void
    {
        if (\is_array($node)) {
            foreach ($node as $index => $child) {
                self::assertObjectsAllTheWayDown($child, $where, $path . '[' . $index . ']');
            }

            return;
        }

        if (!$node instanceof \stdClass) {
            return;
        }

        foreach (get_object_vars($node) as $key => $value) {
            $childPath = $path . '.' . $key;

            if (\in_array($key, self::OBJECT_FIELDS, true) && \is_array($value)) {
                self::fail(sprintf('%s: %s is a JSON array where the contract declares an object.', $where, $childPath));
            }

            self::assertObjectsAllTheWayDown($value, $where, $childPath);
        }
    }
}
