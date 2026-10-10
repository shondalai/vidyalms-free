<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The error vocabulary of the /v1 contract as clients observe it: problem
 * responses for guests, unknown resources, wrong methods and invalid bodies,
 * and the public endpoints that must keep working without authentication.
 */
final class HandlerContractTest extends TestCase
{
    private const INSTRUCTOR_ID = 1;

    private TestPorts $env;
    private CoreKernel $kernel;
    private ApiKernel $api;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::INSTRUCTOR_ID, Capability::TEACH);
        $this->kernel = new CoreKernel($this->env->ports);
        $this->api = $this->kernel->apiKernel();
    }

    public function testGuestGets401OnMyEnrolments(): void
    {
        $response = $this->api->handle(new ApiRequest('GET', '/me/enrolments', Principal::guest()));

        self::assertSame(401, $response->status);
        self::assertProblem($response);
        self::assertNotNull($response->data);
        self::assertSame('Authentication required', $response->data['title']);
    }

    public function testUnknownCourseSlugIs404(): void
    {
        $response = $this->api->handle(new ApiRequest('GET', '/courses/does-not-exist', Principal::guest()));

        self::assertSame(404, $response->status);
        self::assertProblem($response);
    }

    public function testWrongMethodIs405(): void
    {
        $response = $this->api->handle(new ApiRequest('DELETE', '/courses', Principal::guest()));

        self::assertSame(405, $response->status);
        self::assertProblem($response);
    }

    public function testInvalidEnrolBodyIs422WithFieldErrors(): void
    {
        $response = $this->api->handle(new ApiRequest('POST', '/enrolments', Principal::user(7), body: []));

        self::assertSame(422, $response->status);
        self::assertProblem($response);
        self::assertNotNull($response->data);
        self::assertSame('Validation failed', $response->data['title']);
        self::assertIsArray($response->data['errors']);
        self::assertArrayHasKey('course_id', $response->data['errors']);
    }

    public function testCertificateVerificationIsPublicAndCalmForUnknownCodes(): void
    {
        $response = $this->api->handle(new ApiRequest('GET', '/certificates/UNKNOWNCODE1/verify', Principal::guest()));

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertFalse($response->data['valid']);
        self::assertSame('not_found', $response->data['reason']);
        self::assertNull($response->data['course_title']);
        self::assertNull($response->data['learner_name']);
    }

    public function testCurriculumOfAnUnpublishedCourseIsNotFoundForGuests(): void
    {
        $instructor = Principal::user(self::INSTRUCTOR_ID);
        $course = $this->kernel->createCourse->execute($instructor, 'Hidden Draft');
        $module = $this->kernel->addModule->execute($instructor, $course->id, 'Module One');
        $this->kernel->addLesson->execute($instructor, $module->id, 'Draft reading', 'reading', ['body' => '<p>Soon.</p>']);

        $guestView = $this->api->handle(new ApiRequest('GET', '/courses/' . $course->id . '/curriculum', Principal::guest()));
        self::assertSame(404, $guestView->status);
        self::assertProblem($guestView);

        // The author still sees the draft structure.
        $authorView = $this->api->handle(new ApiRequest('GET', '/courses/' . $course->id . '/curriculum', $instructor));
        self::assertSame(200, $authorView->status);
        self::assertNotNull($authorView->data);
        self::assertIsArray($authorView->data['modules']);
        self::assertCount(1, $authorView->data['modules']);
    }

    private static function assertProblem(ApiResponse $response): void
    {
        self::assertSame('application/problem+json', $response->headers['Content-Type'] ?? null);
        self::assertNotNull($response->data);
        self::assertSame($response->status, $response->data['status']);
    }
}
