<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;

/**
 * The three answers the Idempotency-Key header owes a client, as HTTP
 * statuses seen through the routed kernel rather than as exception classes.
 *
 * The published IETF draft (draft-ietf-httpapi-idempotency-key-header) fixes
 * all three: a missing or malformed key is 400, a key still executing is
 * 409, and a key reused with different request input is 422. Two of them
 * were inverted here, which matters because a client cannot tell a retryable
 * refusal from a permanent one by reading prose: 422 is what tells it the
 * body is wrong and 400 is what tells it the header is, and answering 409
 * for a changed body told it to keep retrying a request that will never be
 * accepted under that key.
 */
#[CoversClass(IdempotentExecution::class)]
final class IdempotencyStatusCodesTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testAMissingOrEmptyKeyOnARequiredKeyMutationIsABadRequest(): void
    {
        foreach ([null, '', '   '] as $key) {
            $response = $this->create('A template that must not be created', $key);

            self::assertSame(400, $response->status, self::describe($response));
            self::assertNotNull($response->data);
            self::assertSame(400, $response->data['status']);
            self::assertSame('Bad request', $response->data['title']);
        }
    }

    public function testAnOversizedKeyStaysAValidationFailureBecauseTheHeaderItselfIsMalformed(): void
    {
        $response = $this->create('A template that must not be created', str_repeat('a', 191));

        self::assertSame(422, $response->status, self::describe($response));
        self::assertNotNull($response->data);
        self::assertArrayHasKey('Idempotency-Key', $response->data['errors']);
    }

    public function testTheSameKeyWithDifferentInputIsUnprocessableRatherThanAConflict(): void
    {
        $key = 'idempotency-status-codes-mismatch';
        $created = $this->create('The first create under this key', $key);
        self::assertSame(201, $created->status, self::describe($created));

        $mismatched = $this->create('A changed create requires a fresh key', $key);

        self::assertSame(422, $mismatched->status, self::describe($mismatched));
        self::assertNotNull($mismatched->data);
        self::assertSame(422, $mismatched->data['status']);
        self::assertSame('idempotency.input_mismatch', $mismatched->data['reason']);
        self::assertArrayHasKey('Idempotency-Key', $mismatched->data['errors']);
    }

    public function testAnIdenticalRetryUnderTheSameKeyStillReplaysTheStoredResponse(): void
    {
        $key = 'idempotency-status-codes-replay';
        $created = $this->create('One logical create', $key);
        $replayed = $this->create('One logical create', $key);

        self::assertSame(201, $created->status, self::describe($created));
        self::assertSame($created->status, $replayed->status);
        self::assertSame($created->data, $replayed->data);
        self::assertSame('true', $replayed->headers['Idempotent-Replayed'] ?? null);
    }

    private function create(string $name, ?string $key): ApiResponse
    {
        return $this->fixture->call(
            'POST',
            '/manage/certificate-templates',
            $this->fixture->manager(),
            ['name' => $name],
            [],
            $key,
        );
    }

    private static function describe(ApiResponse $response): string
    {
        return $response->status . ' ' . json_encode($response->data, JSON_THROW_ON_ERROR);
    }
}
