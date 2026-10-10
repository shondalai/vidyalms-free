<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * Shared passages: an entity of their own, never a zero-point pseudo
 * question, and a free table because the attempt payload carries them.
 */
final class StimuliSurfaceTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testAPassageIsCreatedReadListedAndReplaced(): void
    {
        $created = $this->create();

        self::assertSame(201, $created->status, json_encode($created->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($created->data);
        self::assertSame([
            'id',
            'course_id',
            'title',
            'body',
            'media',
            'alt_text',
            'long_description',
            'question_count',
        ], array_keys($created->data));
        self::assertSame(0, $created->data['question_count']);

        $id = (int) $created->data['id'];

        $read = $this->fixture->call('GET', '/manage/stimuli/' . $id, $this->fixture->teacher());
        self::assertSame(200, $read->status);
        self::assertSame('The oolong passage', $read->data['title'] ?? null);

        $list = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/stimuli',
            $this->fixture->teacher(),
        );
        self::assertSame(200, $list->status);
        self::assertCount(1, $list->data['items'] ?? []);

        $replaced = $this->fixture->call('PUT', '/manage/stimuli/' . $id, $this->fixture->teacher(), [
            'title' => 'The corrected passage',
            'body' => '<p>Oolong is partly oxidised.</p>',
            'alt_text' => null,
        ]);
        self::assertSame(200, $replaced->status);
        self::assertNotNull($replaced->data);
        self::assertSame('The corrected passage', $replaced->data['title']);
        self::assertArrayHasKey('alt_text', $replaced->data);
        self::assertNull($replaced->data['alt_text'], 'A replace is a replace.');
    }

    public function testAPassageNothingUsesIsRemoved(): void
    {
        $id = (int) ($this->create()->data['id'] ?? 0);

        $removed = $this->fixture->call('DELETE', '/manage/stimuli/' . $id, $this->fixture->teacher());

        self::assertSame(204, $removed->status);
        self::assertSame(404, $this->fixture->call(
            'GET',
            '/manage/stimuli/' . $id,
            $this->fixture->teacher(),
        )->status);
    }

    public function testAPassageAQuestionPointsAtRefusesRemovalAndSaysHowMany(): void
    {
        $id = (int) ($this->create()->data['id'] ?? 0);

        $this->fixture->env->database->update(
            'questions',
            ['stimulus_id' => $id],
            ['id' => $this->fixture->questionId],
        );

        $refused = $this->fixture->call('DELETE', '/manage/stimuli/' . $id, $this->fixture->teacher());

        self::assertSame(409, $refused->status);
        self::assertNotNull($refused->data);
        self::assertSame(1, $refused->data['question_count'] ?? null);

        $listed = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/stimuli',
            $this->fixture->teacher(),
        );
        self::assertSame(1, $listed->data['items'][0]['question_count'] ?? null);
    }

    public function testAPassageWithoutATitleIsRefused(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/courses/' . $this->fixture->courseId . '/stimuli',
            $this->fixture->teacher(),
            ['body' => 'No title.'],
        );

        self::assertSame(422, $response->status);
    }

    public function testThePerCourseRuleGuardsEveryPassageRoute(): void
    {
        $id = (int) ($this->create()->data['id'] ?? 0);

        self::assertSame(401, $this->fixture->call(
            'GET',
            '/manage/stimuli/' . $id,
            Principal::guest(),
        )->status);
        self::assertSame(403, $this->fixture->call(
            'GET',
            '/manage/stimuli/' . $id,
            $this->fixture->outsider(),
        )->status);
        self::assertSame(403, $this->fixture->call(
            'PUT',
            '/manage/stimuli/' . $id,
            $this->fixture->outsider(),
            ['title' => 'Mine now'],
        )->status);
        self::assertSame(403, $this->fixture->call(
            'DELETE',
            '/manage/stimuli/' . $id,
            $this->fixture->outsider(),
        )->status);
        self::assertSame(404, $this->fixture->call(
            'GET',
            '/manage/stimuli/9999',
            $this->fixture->teacher(),
        )->status);
    }

    private function create(): \Shondalai\VidyaLMS\Core\Http\ApiResponse
    {
        return $this->fixture->call(
            'POST',
            '/manage/courses/' . $this->fixture->courseId . '/stimuli',
            $this->fixture->teacher(),
            [
                'title' => 'The oolong passage',
                'body' => '<p>Oolong sits between green and black.</p>',
                'media' => ['image' => ['path' => 'stimuli/oolong.png']],
                'alt_text' => 'A leaf.',
                'long_description' => 'A long description of the leaf.',
            ],
        );
    }
}
