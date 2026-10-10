<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Ai\AiBridgeCaller;
use Shondalai\VidyaLMS\Core\Application\Ai\AiCallEnvelope;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRouteCandidate;
use Shondalai\VidyaLMS\Core\Application\Ai\AiSchemaNarrower;
use Shondalai\VidyaLMS\Core\Application\Ai\InMemoryAttemptLedger;
use Shondalai\VidyaLMS\Core\Application\Ai\InMemoryReservationLedger;
use Shondalai\VidyaLMS\Core\Provider\Ai\StrictRedactor;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\PromptTrust;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\FrozenClock;
use Shondalai\VidyaLMS\Core\Tests\Unit\Ai\RecordingBridge;

/**
 * The conformance the redactor exists to satisfy, asserted **per task**.
 *
 * The rule is one sentence and it is the whole privacy claim: the set of
 * redaction-manifest entries equals the set of PromptSegment labels on the
 * request that was sent. The manifest is what the learner's right-to-know
 * view and the dry run render, so a manifest that could disagree with the
 * wire would make both of those surfaces a description of something else.
 *
 * It is asserted per task rather than once, because the thing that varies
 * between tasks is exactly what would break it: a task declaring three facts
 * puts three more spans on the wire than one declaring none, and a task
 * declaring none must put none there at all.
 *
 * Every case runs over a recording bridge that opens nothing. The free
 * build's own bridge reaches no model either, so this is not a stand-in for
 * something a paid build would do differently: it is the same path.
 */
final class AiRedactionConformanceTest extends TestCase
{
    private Registries $registries;

    protected function setUp(): void
    {
        $this->registries = ManagementFixture::create()->kernel->registries;
    }

    public function testForEveryTaskTheManifestEqualsTheSegmentLabelsOnTheWire(): void
    {
        $checked = 0;

        foreach ($this->registries->aiTasks->all() as $task) {
            $bridge = new RecordingBridge();
            $caller = $this->callerFor($task, $bridge);

            $caller->call($this->payloadFor($task));

            self::assertNotNull($bridge->request, $task->key() . ' sent nothing at all');
            self::assertSame(
                $bridge->request->payload->manifest,
                $bridge->request->payload->segmentLabels(),
                $task->key() . ': the manifest and the wire disagree',
            );
            self::assertSame(
                $bridge->request->payload->manifest,
                $caller->lastManifest(),
                $task->key() . ': the run would record a manifest the request did not carry',
            );

            ++$checked;
        }

        self::assertSame(11, $checked, 'the free build ships eleven deterministic tasks');
    }

    public function testATaskThatDeclaredNoFactsSendsNoFactSpan(): void
    {
        foreach ($this->registries->aiTasks->all() as $task) {
            if ($task instanceof RequiresFacts && $task->requiredFacts() !== []) {
                continue;
            }

            $bridge = new RecordingBridge();
            $this->callerFor($task, $bridge)->call($this->payloadFor($task));

            self::assertNotNull($bridge->request);

            foreach ($bridge->request->payload->manifest as $entry) {
                self::assertStringStartsNotWith(
                    'fact:',
                    $entry,
                    $task->key() . ' declared no facts and was still handed learner data',
                );
            }
        }
    }

    public function testEveryTaskDeclaringAFactSeesThatFactAndNothingElse(): void
    {
        foreach ($this->registries->aiTasks->all() as $task) {
            if (!$task instanceof RequiresFacts || $task->requiredFacts() === []) {
                continue;
            }

            $bridge = new RecordingBridge();
            $this->callerFor($task, $bridge)->call($this->payloadFor($task, true));

            self::assertNotNull($bridge->request);
            $sent = [];

            foreach ($bridge->request->payload->manifest as $entry) {
                if (str_starts_with($entry, 'fact:')) {
                    $sent[] = substr($entry, 5);
                }
            }

            // The undeclared fact in the payload below must not appear, which
            // is the run-time half of the registration-time rule: a task
            // cannot widen at run time what it declared at registration.
            self::assertNotContains('submission.body', $sent, $task->key() . ' was handed an undeclared fact');

            foreach ($sent as $fact) {
                self::assertContains($fact, $task->requiredFacts(), $task->key() . ' saw ' . $fact);
            }
        }
    }

    public function testNoSegmentOnTheWireIsEverATrustedInstruction(): void
    {
        foreach ($this->registries->aiTasks->all() as $task) {
            $bridge = new RecordingBridge();
            $this->callerFor($task, $bridge)->call($this->payloadFor($task, true));

            self::assertNotNull($bridge->request);

            foreach ($bridge->request->segments() as $segment) {
                self::assertNotSame(
                    PromptTrust::TrustedInstruction,
                    $segment->trust,
                    $task->key() . ' put a trusted instruction on the wire from payload content',
                );
            }
        }
    }

    public function testAnEmailAddressInACourseBodyNeverLeavesTheSite(): void
    {
        $bridge = new RecordingBridge();
        $task = $this->registries->aiTasks->get('check.readability');

        $this->callerFor($task, $bridge)->call(AiTaskPayload::create(
            AiTaskRequest::create('lesson', 12),
            [[
                'provenance' => 'lesson:12',
                'trust' => 'untrusted_content',
                'text' => 'Write to ada@example.org about the assignment, or call 5551234567.',
            ]],
        ));

        self::assertNotNull($bridge->request);
        $text = $bridge->request->segments()[0]->text;

        self::assertStringNotContainsString('ada@example.org', $text);
        self::assertStringNotContainsString('5551234567', $text);
        self::assertStringContainsString(StrictRedactor::MASK_EMAIL, $text);
        self::assertStringContainsString(StrictRedactor::MASK_NUMBER, $text);
    }

    private function payloadFor(AiTaskInterface $task, bool $withUndeclaredFact = false): AiTaskPayload
    {
        $facts = [];

        if ($task instanceof RequiresFacts) {
            foreach ($task->requiredFacts() as $fact) {
                $facts[$fact] = 'the resolved value of ' . $fact;
            }
        }

        if ($withUndeclaredFact) {
            // A caller bug, or a task trying to read something it never
            // declared. Either way it must not reach the wire.
            $facts['submission.body'] = 'A learner wrote this.';
        }

        return AiTaskPayload::create(
            AiTaskRequest::create('lesson', 12, [], ['chunk:1']),
            [['provenance' => 'chunk:1', 'trust' => 'untrusted_content', 'text' => 'Some course material.']],
            $facts,
            ['instruction' => 'Keep it brief.'],
        );
    }

    private function callerFor(AiTaskInterface $task, RecordingBridge $bridge): AiBridgeCaller
    {
        $bridges = new ModelBridgeRegistry();
        $bridges->register($bridge);
        $clock = new FrozenClock();

        $declared = $task instanceof RequiresFacts ? $task->requiredFacts() : [];

        return AiBridgeCaller::forRun(
            $bridges,
            $this->registries->aiRedactors,
            new AiSchemaNarrower(),
            new InMemoryAttemptLedger(),
            new InMemoryReservationLedger(),
            $clock,
            AiCallEnvelope::create(
                'run-' . $task->key(),
                $task->key(),
                'caller-pseudonym',
                'site-ref',
                $clock->now()->modify('+5 minutes'),
                [AiRouteCandidate::of('recording', 'model-a', CostRecord::of(CostRecord::UNIT_TOKENS, 1000.0))],
                StrictRedactor::KEY,
                StrictRedactor::LEVEL_STRICT,
                $declared,
            ),
        );
    }
}
