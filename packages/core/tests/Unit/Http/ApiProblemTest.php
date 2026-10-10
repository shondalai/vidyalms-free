<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;

/**
 * RFC 9457's own members are not extensible.
 *
 * An extension named "status" replaced the problem's own status, so a 409
 * answered {"status": "revoked"} where openapi declares an integer and the
 * generated SDK types one. Every test asserting on the response's HTTP status
 * still passed, because the collision is in the body alone.
 */
final class ApiProblemTest extends TestCase
{
    public function testAnExtensionCannotRewriteTheProblemsOwnMembers(): void
    {
        $problem = ApiProblem::conflict('This certificate is no longer in force.', [
            'status' => 'revoked',
            'title' => 'Something else',
            'type' => 'https://example.test/problem',
            'detail' => 'Something else again',
            'superseded_by_id' => 3,
        ]);

        $body = $problem->toResponse()->data;
        self::assertIsArray($body);

        self::assertSame(409, $body['status']);
        self::assertSame('Conflict', $body['title']);
        self::assertSame('about:blank', $body['type']);
        self::assertSame('This certificate is no longer in force.', $body['detail']);
        self::assertSame(3, $body['superseded_by_id']);
    }

    public function testEveryProblemsStatusMemberIsItsHttpStatus(): void
    {
        $problems = [
            ApiProblem::badRequest('no'),
            ApiProblem::unauthorized(),
            ApiProblem::forbidden(),
            ApiProblem::notFound(),
            ApiProblem::conflict('no', ['credential_status' => 'suspended']),
            ApiProblem::validation(['name' => 'required']),
            ApiProblem::tooManyRequests(30),
            ApiProblem::internal(),
        ];

        foreach ($problems as $problem) {
            $response = $problem->toResponse();

            self::assertNotNull($response->data);
            self::assertSame($response->status, $response->data['status']);
        }
    }
}
