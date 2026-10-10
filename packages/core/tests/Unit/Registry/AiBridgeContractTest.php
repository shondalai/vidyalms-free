<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\PromptSegment;
use Shondalai\VidyaLMS\Core\Registry\Ai\PromptTrust;
use Shondalai\VidyaLMS\Core\Registry\Ai\RedactedPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;

/**
 * The bridge value objects, and the two properties they exist to make
 * structural rather than advisory.
 *
 * Redaction and transmission are one object, so nothing can compose its own
 * segments and skip the redactor; and a refusal is a closed kind, so the one
 * distinction the retry decision turns on is not a spelling convention.
 */
final class AiBridgeContractTest extends TestCase
{
    /**
     * The twelve, against the published list rather than against a copy of
     * itself. A thirteenth added in code and not in the specification, or the
     * reverse, fails here.
     */
    public function testTheRefusalKindsAreExactlyTheTwelveThePublishedSpecNames(): void
    {
        // The specification lives in the development monorepo's docs, which
        // the public free source does not carry. Keyed on the folder, so a
        // missing file inside the monorepo still fails.
        if (!is_dir(__DIR__ . '/../../../../../docs/technical')) {
            self::markTestSkipped('docs/technical is not part of this checkout.');
        }

        $spec = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../docs/technical/ai/spec.json'),
            true,
        );

        self::assertIsArray($spec);
        self::assertArrayHasKey('refusal_kinds', $spec);
        self::assertIsArray($spec['refusal_kinds']);

        $published = [];
        $retryable = [];

        foreach ($spec['refusal_kinds'] as $entry) {
            self::assertIsArray($entry);
            $published[] = $entry['key'];

            if ($entry['retryable'] === true) {
                $retryable[] = $entry['key'];
            }
        }

        self::assertCount(12, $published);
        self::assertSame($published, RefusalKind::keys());

        // The load-bearing pair: a model that declined is a content signal
        // and is not retryable; a transport that failed is retryable and says
        // nothing at all about the content. Collapsing them pays for the same
        // refusal eight times.
        self::assertSame($retryable, array_values(array_map(
            static fn (RefusalKind $kind): string => $kind->value,
            array_filter(RefusalKind::cases(), static fn (RefusalKind $kind): bool => $kind->isRetryable()),
        )));

        self::assertTrue(RefusalKind::UpstreamUnavailable->isRetryable());
        self::assertFalse(RefusalKind::UpstreamRefused->isRetryable());
    }

    /**
     * The free string is gone while nothing constructs one, which is the only
     * moment the correction is free.
     */
    public function testATaskRefusalCarriesTheClosedKindAndNotAString(): void
    {
        $refusal = AiRefusal::of(RefusalKind::CeilingExceeded, 'ai.refusal.ceiling');

        self::assertSame(RefusalKind::CeilingExceeded, $refusal->kind);
        self::assertFalse($refusal->isRetryable());

        $property = new \ReflectionProperty(AiRefusal::class, 'kind');
        self::assertSame(RefusalKind::class, (string) $property->getType());
        self::assertFalse(property_exists(AiRefusal::class, 'code'));
    }

    /**
     * A bridge request is built from a redacted payload and from nothing
     * else, so a caller cannot compose its own segments and put an
     * unredacted submission on the wire without touching the redactor.
     */
    public function testARequestCanOnlyBeBuiltFromARedactedPayload(): void
    {
        $constructor = (new \ReflectionClass(BridgeRequest::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());

        $factories = [];

        foreach ((new \ReflectionClass(BridgeRequest::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic()) {
                continue;
            }

            $factories[] = $method->getName();
            $first = $method->getParameters()[0] ?? null;

            self::assertNotNull($first, $method->getName() . ' takes nothing at all');
            self::assertSame(
                RedactedPayload::class,
                (string) $first->getType(),
                $method->getName() . ' takes something other than a redacted payload first',
            );
        }

        self::assertSame(['fromRedactedPayload'], $factories);
    }

    /**
     * The request and the context between them name no course, no learner and
     * no principal, which is a stronger boundary than describing the
     * redaction stage alone: it holds even when the redactor is replaced.
     */
    public function testARequestNamesNobodyAndNothingItCouldLeak(): void
    {
        $payload = RedactedPayload::of(
            [PromptSegment::content('lesson:88', 'The delimited text.')],
            ['lesson:88'],
            'strict',
            'strict',
        );

        $now = new \DateTimeImmutable('2026-09-07 10:00:00');
        $request = BridgeRequest::fromRedactedPayload(
            $payload,
            $now->modify('+30 seconds'),
            'idem-1',
            CostRecord::of(CostRecord::UNIT_TOKENS, 2000.0),
            'caller-9f2',
        );

        self::assertSame(['lesson:88'], $request->payload->manifest);
        self::assertFalse($request->expectsStructure());
        self::assertSame(PromptTrust::UntrustedContent, $request->segments()[0]->trust);

        foreach ((new \ReflectionClass(BridgeRequest::class))->getProperties() as $property) {
            self::assertNotContains(
                $property->getName(),
                ['principal', 'courseId', 'userId', 'learner', 'subjectId'],
                'a bridge request that can name a course can leak one',
            );
        }
    }

    /**
     * One object answers a call that produced something and a different
     * object answers a call that did not, so no caller has to decide which of
     * two fields is authoritative about an empty result.
     */
    public function testAResultCarriesNoRefusalBecauseTheReturnIsAUnion(): void
    {
        self::assertFalse(property_exists(BridgeResult::class, 'refusal'));

        $result = BridgeResult::text('Two sentences.', 'model-a', CostRecord::of(CostRecord::UNIT_TOKENS, 12.0));
        self::assertTrue($result->isSchemaEnforced());
        self::assertSame('model-a', $result->model);

        // A bridge that dropped a keyword produced a result the runner flags
        // rather than one it trusts.
        $lossy = BridgeResult::structured(['a' => 1], 'model-a', true, null, null, null, null, null, null, null, false, ['minItems']);
        self::assertFalse($lossy->isSchemaEnforced());

        // Unknown is not zero: a bridge that meters nothing says so.
        self::assertFalse($lossy->cost->isKnown());
    }

    /**
     * The one refusal whose honest answer is an instant carries it, rather
     * than leaving a runner to parse a message for a time.
     */
    public function testARateLimitCarriesTheInstantTheUpstreamGave(): void
    {
        $at = new \DateTimeImmutable('2026-09-07 10:05:00');
        $refusal = BridgeRefusal::rateLimited($at);

        self::assertSame(RefusalKind::UpstreamRateLimited, $refusal->kind);
        self::assertSame($at, $refusal->retryAfter);
        self::assertTrue($refusal->isRetryable());

        $declined = BridgeRefusal::of(RefusalKind::UpstreamRefused, 'ai.refusal.upstream_refused');
        self::assertNull($declined->retryAfter);
        self::assertFalse($declined->isRetryable());
    }

    /**
     * The author's free instruction is a third level and never a promotion,
     * because the hostile-input inventory this design opens with begins with
     * content authored by anyone holding instructor rights.
     */
    public function testTheAuthorInstructionSitsBelowTheTasksOwnPrompt(): void
    {
        self::assertGreaterThan(
            PromptTrust::AuthorInstruction->rank(),
            PromptTrust::TrustedInstruction->rank(),
        );
        self::assertGreaterThan(
            PromptTrust::UntrustedContent->rank(),
            PromptTrust::AuthorInstruction->rank(),
        );
        self::assertSame(PromptTrust::TrustedInstruction, PromptSegment::instruction('task', 'Do it.')->trust);
        self::assertSame(PromptTrust::UntrustedContent, PromptSegment::content('lesson:1', 'Body.')->trust);
    }
}
