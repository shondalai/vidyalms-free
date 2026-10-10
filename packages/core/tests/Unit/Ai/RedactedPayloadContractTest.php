<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Registry\Ai\PromptSegment;
use Shondalai\VidyaLMS\Core\Registry\Ai\RedactedPayload;

/**
 * The manifest and the wire cannot disagree, as a property of the value object
 * rather than of one redactor.
 *
 * The design states the invariant, the shipped strict redactor honoured it, and
 * nothing held a registered replacement to it: `of()` took any manifest with no
 * relation to the segments at all. A redactor emitting three segments while
 * declaring one manifest entry produced a request whose stated manifest
 * under-reported what left the site, and the run row, the dry run and the
 * learner's activity view all rendered the understatement.
 */
final class RedactedPayloadContractTest extends TestCase
{
    public function testAManifestShorterThanTheSegmentsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/manifest and the wire disagree/');

        RedactedPayload::of(
            [
                PromptSegment::content('instruction', 'Group the equivalent answers.'),
                PromptSegment::content('fact:submission.body', 'my address is 12 Green Lane'),
                PromptSegment::content('item.definition', 'Describe an oolong.'),
            ],
            ['course_content'],
            'acme-loose',
            'strict',
        );
    }

    public function testAManifestNamingSomethingThatDidNotTravelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RedactedPayload::of(
            [PromptSegment::content('instruction', 'Group the equivalent answers.')],
            ['instruction', 'grade.recorded_mark'],
            'acme-loose',
            'strict',
        );
    }

    public function testAManifestOutOfEmissionOrderIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RedactedPayload::of(
            [
                PromptSegment::content('instruction', 'a'),
                PromptSegment::content('fact:submission.body', 'b'),
            ],
            ['fact:submission.body', 'instruction'],
            'acme-loose',
            'strict',
        );
    }

    public function testTheDerivingFactoryIsAlwaysConsistent(): void
    {
        $payload = RedactedPayload::ofSegments(
            [
                PromptSegment::content('instruction', 'a'),
                PromptSegment::content('fact:submission.body', 'b'),
            ],
            'strict',
            'strict',
        );

        self::assertSame(['instruction', 'fact:submission.body'], $payload->manifest);
        self::assertSame($payload->segmentLabels(), $payload->manifest);
        self::assertFalse($payload->isEmpty());
    }
}
