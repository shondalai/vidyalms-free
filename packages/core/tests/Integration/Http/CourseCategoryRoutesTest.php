<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;

final class CourseCategoryRoutesTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $categories = $this->fixture->env->courseCategories;
        $categories->managementUrl = 'https://example.test/native/categories';
        $categories->add(
            11,
            'Leadership',
            10,
            'Business / Leadership',
            CourseCategoryState::Published,
            'https://example.test/native/categories/11/edit',
        );
        $categories->add(10, 'Business');
        $categories->add(12, 'Leadership', 10, 'Business / Leadership (legacy)', CourseCategoryState::Archived);
        $categories->add(13, 'Removed', null, null, CourseCategoryState::Trashed);
    }

    public function testInstructorGetsAnExactStableServerPage(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/course-categories',
            $this->fixture->teacher(),
            [],
            ['q' => 'lead', 'sort' => 'name', 'order' => 'asc', 'page' => '2', 'per_page' => '1'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['items', 'total', 'page', 'per_page', 'management_url'], array_keys($response->data));
        self::assertSame(2, $response->data['total']);
        self::assertSame(2, $response->data['page']);
        self::assertSame(1, $response->data['per_page']);
        self::assertSame('https://example.test/native/categories', $response->data['management_url']);
        self::assertCount(1, $response->data['items']);
        self::assertSame(12, $response->data['items'][0]['id'], 'equal names use id as the stable tie break');
        self::assertSame(
            ['id', 'name', 'parent_id', 'label', 'state', 'edit_url'],
            array_keys($response->data['items'][0]),
        );
    }

    public function testExactLookupReturnsATrashedCategoryInsteadOfCallingItMissing(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/course-categories/13',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('trashed', $response->data['state']);
        self::assertSame('Removed', $response->data['label']);
    }

    public function testSiteManagerMayAlsoReadTheNativeCategorySource(): void
    {
        self::assertSame(
            200,
            $this->fixture->call('GET', '/manage/course-categories', $this->fixture->manager())->status,
        );
    }

    public function testCategoryPageCanWithholdANativeManagerDestination(): void
    {
        $this->fixture->env->courseCategories->managementUrl = null;

        $response = $this->fixture->call(
            'GET',
            '/manage/course-categories',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('management_url', $response->data);
        self::assertNull($response->data['management_url']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function categoryReads(): array
    {
        return [
            'list' => ['/manage/course-categories'],
            'exact' => ['/manage/course-categories/11'],
        ];
    }

    #[DataProvider('categoryReads')]
    public function testCategoryReadsRefuseAuthenticatedUsersWithoutTeachOrManage(string $path): void
    {
        self::assertSame(403, $this->fixture->call('GET', $path, $this->fixture->learner())->status);
    }

    #[DataProvider('categoryReads')]
    public function testCategoryReadsRequireAuthentication(string $path): void
    {
        self::assertSame(
            401,
            $this->fixture->call('GET', $path, Principal::guest())->status,
        );
    }

    public function testMissingExactCategoryAnswersNotFound(): void
    {
        self::assertSame(
            404,
            $this->fixture->call('GET', '/manage/course-categories/999', $this->fixture->teacher())->status,
        );
    }

    public function testCourseWritesResolveNewCategoryAssignmentsThroughTheNativePort(): void
    {
        $teacher = $this->fixture->teacher();
        $created = $this->fixture->call('POST', '/manage/courses', $teacher, [
            'title' => 'Category contract',
            'category_id' => 11,
        ]);

        self::assertSame(201, $created->status);
        self::assertNotNull($created->data);
        self::assertSame(11, $created->data['category_id']);
        $path = '/manage/courses/' . $created->data['id'];

        foreach ([999, 13] as $categoryId) {
            $refused = $this->fixture->call('PATCH', $path, $teacher, ['category_id' => $categoryId]);
            self::assertSame(422, $refused->status, 'category ' . $categoryId);
            self::assertArrayHasKey('category_id', $refused->data['errors'] ?? []);
        }

        $updated = $this->fixture->call('PATCH', $path, $teacher, ['category_id' => 10]);
        self::assertSame(200, $updated->status);
        self::assertSame(10, $updated->data['category_id'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function invalidQueries(): array
    {
        return [
            'sort' => ['/manage/course-categories', ['sort' => 'label']],
            'order' => ['/manage/course-categories', ['order' => 'sideways']],
            'page' => ['/manage/course-categories', ['page' => '0']],
            'per page floor' => ['/manage/course-categories', ['per_page' => '0']],
            'per page ceiling' => ['/manage/course-categories', ['per_page' => '101']],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueryAndPathValuesAnswerValidationProblems(string $path, array $query): void
    {
        $response = $this->fixture->call('GET', $path, $this->fixture->teacher(), [], $query);

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertIsArray($response->data['errors']);
    }
}
