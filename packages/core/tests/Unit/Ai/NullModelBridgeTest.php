<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Provider\Ai\NullModelBridge;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\DeclaresDataHandling;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\Embeds;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\EstimatesCost;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\GeneratesText;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\HonoursIdempotency;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\MetersCredits;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\Moderates;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\ProducesStructuredOutput;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\ReconcilesUsage;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\ReportsUsage;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\Streams;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\PromptSegment;
use Shondalai\VidyaLMS\Core\Registry\Ai\RedactedPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\BridgeContext;

/**
 * The free bridge, checked directly rather than through the caller, because
 * two of its answers are defences the caller never triggers and a defence
 * nothing exercises is a comment.
 */
final class NullModelBridgeTest extends TestCase
{
    public function testItDeclaresOnlyTheCapabilitiesItHonestlyHas(): void
    {
        // Read as the seam rather than as the class, which is how the caller
        // sees it: the probe is an instanceof against an interface handle, so
        // asserting it against the concrete type would be asserting something
        // the compiler already knows and the caller never asks.
        $bridge = self::asSeam(new NullModelBridge());

        $declared = [GeneratesText::class, EstimatesCost::class, HonoursIdempotency::class,
            ReportsUsage::class, DeclaresDataHandling::class];
        $absent = [ProducesStructuredOutput::class, Streams::class, Embeds::class,
            Moderates::class, MetersCredits::class, ReconcilesUsage::class];

        foreach ($declared as $capability) {
            self::assertInstanceOf($capability, $bridge);
        }

        foreach ($absent as $capability) {
            self::assertNotInstanceOf($capability, $bridge);
        }
    }

    /**
     * The bridge as the registry hands it back, so a capability probe is a
     * real question rather than a compile-time fact.
     */
    private static function asSeam(ModelBridgeInterface $bridge): ModelBridgeInterface
    {
        return $bridge;
    }

    public function testItSaysNothingLeavesTheSite(): void
    {
        $handling = (new NullModelBridge())->dataHandling();

        self::assertTrue($handling->isDeclared());
        self::assertFalse($handling->trainsOnInput);
        self::assertSame('local', $handling->processingRegion);
        self::assertSame(0, $handling->retentionDays);
    }

    public function testItReportsZeroRatherThanUnknown(): void
    {
        $result = (new NullModelBridge())->run($this->request(), $this->context());

        self::assertInstanceOf(BridgeResult::class, $result);
        self::assertSame(0, $result->tokensIn);
        self::assertSame(0, $result->tokensOut);
        self::assertTrue($result->cost->isKnown());
        self::assertSame(CostRecord::UNIT_NONE, $result->cost->unit);
        self::assertSame(CostRecord::UNIT_NONE, (new NullModelBridge())->unit());
    }

    public function testAnElapsedDeadlineIsARefusalAndNotAnEmptyAnswer(): void
    {
        $expired = BridgeContext::create(
            new \DateTimeImmutable('2026-01-15 10:00:00'),
            new \DateTimeImmutable('2026-01-15 09:00:00'),
            'key',
        );

        $refusal = (new NullModelBridge())->run($this->request(), $expired);

        self::assertInstanceOf(BridgeRefusal::class, $refusal);
        self::assertSame(RefusalKind::DeadlineExceeded, $refusal->kind);
    }

    public function testAnEmptyPayloadIsRefusedRatherThanAnswered(): void
    {
        $empty = BridgeRequest::fromRedactedPayload(
            RedactedPayload::of([], [], 'strict', 'strict'),
            new \DateTimeImmutable('2026-01-15 11:00:00'),
            'key',
            CostRecord::none(),
            'caller',
        );

        $refusal = (new NullModelBridge())->run($empty, $this->context());

        self::assertInstanceOf(BridgeRefusal::class, $refusal);
        self::assertSame(RefusalKind::InputRejected, $refusal->kind);
    }

    public function testASchemaIsRefusedRatherThanIgnored(): void
    {
        $refusal = (new NullModelBridge())->run(
            $this->request(['type' => 'object']),
            $this->context(),
        );

        self::assertInstanceOf(BridgeRefusal::class, $refusal);
        self::assertSame(RefusalKind::UnsupportedCapability, $refusal->kind);
    }

    /**
     * @param array<string, mixed>|null $schema
     */
    private function request(?array $schema = null): BridgeRequest
    {
        return BridgeRequest::fromRedactedPayload(
            RedactedPayload::of(
                [PromptSegment::content('lesson:1', 'A body.')],
                ['lesson:1'],
                'strict',
                'strict',
            ),
            new \DateTimeImmutable('2026-01-15 11:00:00'),
            'key',
            CostRecord::none(),
            'caller',
            $schema,
        );
    }

    private function context(): BridgeContext
    {
        return BridgeContext::create(
            new \DateTimeImmutable('2026-01-15 10:00:00'),
            new \DateTimeImmutable('2026-01-15 11:00:00'),
            'key',
        );
    }
}
