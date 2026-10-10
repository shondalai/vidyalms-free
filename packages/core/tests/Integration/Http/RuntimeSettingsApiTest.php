<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class RuntimeSettingsApiTest extends TestCase
{
    public function testAuthorizedReadWriteValidationAndConflict(): void
    {
        $env = TestPorts::create(true);
        $api = (new CoreKernel($env->ports))->apiKernel();
        $admin = Principal::user(1);
        $first = $api->handle(new ApiRequest('GET', '/admin/settings', $admin));
        self::assertSame(200, $first->status);
        self::assertSame(1, $first->data['configuration']['revision'] ?? null);
        $saved = $api->handle(new ApiRequest('PUT', '/admin/settings', $admin, body: [
            'expected_revision' => 1, 'configuration' => ['catalogue_per_page' => 24, 'self_enrolment' => false],
        ]));
        self::assertSame(200, $saved->status);
        self::assertSame(false, $saved->data['self_enrolment'] ?? null);
        self::assertSame(2, $saved->data['configuration']['revision'] ?? null);
        $stale = $api->handle(new ApiRequest('PUT', '/admin/settings', $admin, body: [
            'expected_revision' => 1, 'configuration' => ['catalogue_per_page' => 40],
        ]));
        self::assertSame(409, $stale->status);
        $invalid = $api->handle(new ApiRequest('PUT', '/admin/settings', $admin, body: [
            'expected_revision' => 2, 'configuration' => ['private_storage_path' => '../private'],
        ]));
        self::assertSame(422, $invalid->status);
        $badBody = $api->handle(new ApiRequest('PUT', '/admin/settings', $admin, body: [
            'configuration' => ['self_enrolment' => true],
        ]));
        self::assertSame(400, $badBody->status);
        $fresh = (new CoreKernel($env->ports))->apiKernel()->handle(new ApiRequest('GET', '/admin/settings', $admin));
        self::assertSame(24, $fresh->data['configuration']['values']['catalogue_per_page'] ?? null);
    }

    public function testUnauthorizedUsersCannotReadOrWriteConfiguration(): void
    {
        $env = TestPorts::create();
        $api = (new CoreKernel($env->ports))->apiKernel();
        foreach (['GET', 'PUT'] as $method) {
            $response = $api->handle(new ApiRequest($method, '/admin/settings', Principal::user(5), body: [
                'expected_revision' => 1, 'configuration' => ['self_enrolment' => false],
            ]));
            self::assertSame(403, $response->status);
        }
    }
}
