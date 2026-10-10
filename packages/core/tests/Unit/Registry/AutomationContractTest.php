<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Automation\CascadeChain;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DirectOutboxOnly;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\EventTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\SweepTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;

/**
 * The automation action contract, and the three shipped things it leans on.
 *
 * Everything asserted here is a property a later wave could break without any
 * other gate noticing: a digest that stopped carrying the subject, a preview
 * that started claiming the key a real run needs, a cascade that restarted at
 * zero, or a trigger the builder would offer that the fan-out cannot resolve
 * a subject from.
 */
final class AutomationContractTest extends TestCase
{
    private const NOW = '2026-09-07 10:00:00';

    /**
     * The digest is a function of the nine canonical components and of
     * nothing else, so two runners composing one step agree.
     */
    public function testTheCanonicalDigestIsDeterministicAndCarriedReadonly(): void
    {
        $first = self::context();
        $second = self::context();

        self::assertSame($first->idempotencyDigest, $second->idempotencyDigest);
        self::assertSame(64, \strlen($first->idempotencyDigest));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->idempotencyDigest);
    }

    /**
     * The failure the absent idempotencyKeyFor() exists to prevent: two
     * subjects of one fan-out must never share a key, whatever else is equal.
     */
    public function testTwoSubjectsOfOneFanOutNeverShareAKey(): void
    {
        $one = self::context(subjectId: 11);
        $other = self::context(subjectId: 12);

        self::assertNotSame($one->idempotencyDigest, $other->idempotencyDigest);
    }

    /**
     * dry_run is inside the digest rather than beside it, because one code
     * path serves the preview and the real run. Without it, previewing a rule
     * for a subject claims the key the real run needs, the real run replays
     * the preview's outcome, and with re-entry defaulting to never the
     * subject is suppressed for good.
     */
    public function testAPreviewNeverClaimsTheKeyARealRunNeeds(): void
    {
        self::assertNotSame(
            self::context(dryRun: true)->idempotencyDigest,
            self::context(dryRun: false)->idempotencyDigest,
        );
    }

    /**
     * A declared discriminator is appended, never substituted, so a provider
     * can only ever split a key further.
     */
    public function testADiscriminatorAppendsAndCannotCollideTwoSubjects(): void
    {
        $base = self::context(subjectId: 11);
        $split = $base->withDiscriminator('page:2');

        self::assertNotSame($base->idempotencyDigest, $split->idempotencyDigest);

        // Appended and not substituted: the canonical digest is still the
        // whole head of the key.
        self::assertSame($base->idempotencyDigest, substr($split->idempotencyDigest, 0, 64));

        // The nine canonical components are still in the key, so a constant
        // discriminator returned for every subject still leaves two subjects
        // apart. This is the whole safety property of appending.
        $otherSubject = self::context(subjectId: 12)->withDiscriminator('page:2');
        self::assertNotSame($split->idempotencyDigest, $otherSubject->idempotencyDigest);

        // An empty discriminator is the same as declaring none.
        self::assertSame($base->idempotencyDigest, $base->withDiscriminator('')->idempotencyDigest);
    }

    /**
     * Loop protection mechanisms one and two, read off the context they are
     * carried on.
     */
    public function testTheCascadeChainAnswersAnyRuleAndNotOnlyThisOne(): void
    {
        $context = self::context(cascadeDepth: 2, cascadeChain: [7, 9]);

        // The chain is normalised into the one vocabulary, whatever a caller
        // held: a run's stored ids in, a chain out.
        self::assertInstanceOf(CascadeChain::class, $context->cascadeChain);
        self::assertSame([7, 9], $context->cascadeChain->ruleIds);

        self::assertTrue($context->hasRuleOnCascade(7));
        self::assertTrue($context->hasRuleOnCascade(9));
        self::assertFalse($context->hasRuleOnCascade(42));

        self::assertTrue($context->isWithinCascadeCeiling(3));
        self::assertFalse($context->isWithinCascadeCeiling(2));
    }

    /**
     * The two digests agree about what a repetition is, because the step
     * hands its own occurrence key and its own producer-qualified reference
     * to whatever engine it delegates to.
     */
    public function testTheStepDelegatesItsOwnOccurrenceAndItsOwnProducerReference(): void
    {
        $context = self::context();

        self::assertSame('2026-W37', $context->occurrenceKey);
        self::assertSame('automation:42', $context->producerReference());
    }

    /**
     * Every shipped trigger constructs the occurrence exactly as it did, and
     * answers the honest default about a cascade it knows nothing of.
     */
    public function testAShippedSweepStillConstructsAnOccurrenceUnchanged(): void
    {
        $occurrence = TriggerOccurrence::forSweep('vidyalms.mail.entitlement_expiring', 5, '2026-09-07');

        self::assertSame(0, $occurrence->cascadeDepth);
        self::assertNull($occurrence->emittingRuleId);
        self::assertFalse($occurrence->isAutomationEmitted());
        self::assertSame('sweep:vidyalms.mail.entitlement_expiring:0:2026-09-07', $occurrence->eventReference);
    }

    /**
     * Mechanism two has something to inherit: a run created from an
     * automation-emitted event carries the emitting run's depth plus one and
     * the emitting rule, rather than starting at the column default.
     */
    public function testAnEmittedOccurrenceInheritsTheDepthAndTheEmittingRule(): void
    {
        $occurrence = TriggerOccurrence::forSweep('vidyalms.mail.enrolled', 5, '2026-09-07')
            ->inheritingCascade(3, 42);

        self::assertSame(3, $occurrence->cascadeDepth);
        self::assertSame(42, $occurrence->emittingRuleId);
        self::assertTrue($occurrence->isAutomationEmitted());

        // Everything else is the occurrence it already was.
        self::assertSame('2026-09-07', $occurrence->occurrenceKey);
        self::assertSame(5, $occurrence->recipientUserId);
    }

    /**
     * The shipped decision object serves a target with no channel, on a
     * published key, with the fold unchanged.
     */
    public function testTheTargetKeyConventionCarriesAChannellessTarget(): void
    {
        $step = PolicyDecision::TARGET_AUTOMATION_STEP;
        self::assertSame('automation.step', $step);
        self::assertSame('ai.run', PolicyDecision::TARGET_AI_RUN);

        $until = new \DateTimeImmutable('2026-09-07 18:00:00');
        $deferred = PolicyDecision::defer([$step], $until);
        self::assertSame([$step => $until], $deferred->deferred);
        self::assertSame([], $deferred->denied);

        $denied = PolicyDecision::deny([$step], 'automation.ceiling_exceeded');
        $folded = $deferred->merge($denied);

        // The fold needs no special case: a deny beats a defer on the same
        // key whether that key is a channel or a step.
        self::assertSame([$step => 'automation.ceiling_exceeded'], $folded->denied);
        self::assertSame([], $folded->deferred);
        self::assertFalse($folded->isEmpty());

        // A mail decision on the same object is untouched by the convention.
        $mail = PolicyDecision::defer(['email'], $until);
        self::assertSame(['email' => $until], $mail->deferred);
    }

    /**
     * Every shipped trigger is one the fan-out can resolve a subject from, or
     * says out loud that it is not.
     *
     * The rule builder's picker reads exactly this: an event or sweep trigger
     * that does not declare DirectOutboxOnly. A trigger of a third kind that
     * declared nothing would reach the picker and produce a rule that cannot
     * fan out, which is a run-time discovery of a design-time fact.
     */
    public function testEveryShippedTriggerIsAnAutomationCandidateOrSaysItIsNot(): void
    {
        $classes = self::shippedTriggerClasses();
        self::assertGreaterThanOrEqual(8, \count($classes), 'the scan found almost nothing, which is the real failure');

        $directOutbox = [];

        foreach ($classes as $class) {
            $isEvent = is_a($class, EventTriggerInterface::class, true);
            $isSweep = is_a($class, SweepTriggerInterface::class, true);
            $isDirect = is_a($class, DirectOutboxOnly::class, true);

            self::assertTrue(
                $isEvent || $isSweep || $isDirect,
                $class . ' is a third kind of trigger and declares nothing about it',
            );

            self::assertFalse(
                $isDirect && ($isEvent || $isSweep),
                $class . ' declares DirectOutboxOnly and is also a kind the fan-out can resolve',
            );

            if ($isDirect) {
                $directOutbox[] = $class;
            }
        }

        // Exactly one shipped provider is the third kind, and it is the one
        // whose whole reason for existing is that the secret never reaches a
        // domain event.
        self::assertSame(
            ['Shondalai\\VidyaLMS\\Core\\Provider\\Notification\\Trigger\\OrganisationInvitationTrigger'],
            $directOutbox,
        );
    }

    /**
     * The nine action capabilities exist, and the three whose names collide
     * with an availability namesake say so where their author works.
     *
     * The collision is deliberate: an author who has met one has met both,
     * and the enforcement is the same. But PHP namespaces are the only thing
     * keeping them apart, so a provider class implementing an availability
     * condition and an automation action needs an aliased use statement, and
     * that is worth reading in the file rather than discovering in a fatal.
     */
    public function testTheNineActionCapabilitiesNameTheirAvailabilityNamesakes(): void
    {
        $namespace = 'Shondalai\\VidyaLMS\\Core\\Registry\\Automation\\';
        $availability = 'Shondalai\\VidyaLMS\\Core\\Registry\\Availability\\';

        $capabilities = [
            'SupportsDryRun', 'RequiresApprovalBeforeEffect', 'ProducesReviewItem', 'DeclaresCost',
            'SupportsBatch', 'DeclaresReferences', 'ValidatesPayload', 'SupportsPayloadVersions',
            'DeclaresIdempotencyDiscriminator',
        ];

        foreach ($capabilities as $name) {
            self::assertTrue(interface_exists($namespace . $name), $namespace . $name);

            $docblock = (new \ReflectionClass($namespace . $name))->getDocComment();
            self::assertIsString($docblock, $name . ' carries no docblock');

            // Every capability publishes what its absence means, which is the
            // rule the standards page already carries.
            self::assertStringContainsString('Absent means', $docblock, $name);

            if (interface_exists($availability . $name)) {
                self::assertStringContainsString(
                    'collides deliberately',
                    $docblock,
                    $name . ' shares its name with an availability capability and does not say so',
                );
            }
        }

        // Exactly three collide today. A fourth added later has to say so.
        $colliding = array_values(array_filter(
            $capabilities,
            static fn (string $name): bool => interface_exists($availability . $name),
        ));
        self::assertSame(['DeclaresReferences', 'ValidatesPayload', 'SupportsPayloadVersions'], $colliding);
    }

    /**
     * The privacy contributor seam is not one of the eleven, and the shipped
     * file says so where a reader would otherwise infer it from position.
     *
     * It arrived from the organisation work in a later edit and sits directly
     * beneath the AI block's docblock, so without a note of its own that
     * block appears to govern it: seated empty on purpose, filled by an AI
     * stage, deferring the free-provider rule. All three are false of it.
     */
    public function testThePrivacyContributorSeamIsNotGovernedByTheAiReasoning(): void
    {
        $file = file_get_contents(__DIR__ . '/../../../src/Registry/Registries.php');
        self::assertIsString($file);

        $position = strpos($file, 'public readonly PrivacyContributorRegistry $privacyContributors');
        self::assertIsInt($position);

        $preceding = substr($file, max(0, $position - 1600), min(1600, $position));

        self::assertStringContainsString('Not one of the eleven above', $preceding);
        self::assertStringContainsString('touch-once claim', $preceding);
    }

    /**
     * Plan item 12, verified rather than redone.
     *
     * The reconciliation wave replaced the fixed grantable-source list with a
     * capability probed on the commerce source, and the hardcoded digest
     * channel list with one probed on the channel handler. Both are closed;
     * this records that they are, so a later wave that reintroduces either
     * constant fails here rather than passing review.
     */
    public function testTheTwoHardcodedListsStayReplacedByCapabilities(): void
    {
        self::assertTrue(interface_exists('Shondalai\\VidyaLMS\\Core\\Registry\\Commerce\\GrantsEntitlements'));
        self::assertTrue(interface_exists('Shondalai\\VidyaLMS\\Core\\Registry\\Notification\\SupportsDigest'));

        $grant = file_get_contents(__DIR__ . '/../../../src/Application/Access/GrantEntitlement.php');
        self::assertIsString($grant);
        self::assertStringNotContainsString('GRANTABLE_SOURCES', $grant);
        self::assertStringContainsString('GrantsEntitlements', $grant);

        $centre = file_get_contents(__DIR__ . '/../../../src/Application/Notification/NotificationPreferenceCentre.php');
        self::assertIsString($centre);
        self::assertStringNotContainsString('DIGEST_CHANNELS', $centre);
        self::assertStringContainsString('SupportsDigest', $centre);
    }

    /**
     * Every concrete notification trigger the free build ships, by scanning
     * the provider directory rather than by a list a later provider would not
     * be added to.
     *
     * @return list<class-string>
     */
    private static function shippedTriggerClasses(): array
    {
        $directory = __DIR__ . '/../../../src/Provider/Notification/Trigger';
        $files = glob($directory . '/*.php');
        self::assertIsArray($files);

        $classes = [];

        foreach ($files as $file) {
            $class = 'Shondalai\\VidyaLMS\\Core\\Provider\\Notification\\Trigger\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;
            }

            $reflected = new \ReflectionClass($class);

            if ($reflected->isAbstract() || !$reflected->implementsInterface(NotificationTriggerInterface::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param list<int> $cascadeChain
     */
    private static function context(
        int $subjectId = 11,
        bool $dryRun = false,
        int $cascadeDepth = 0,
        array $cascadeChain = [],
    ): AutomationActionContext {
        $now = new \DateTimeImmutable(self::NOW);

        return AutomationActionContext::forStep(
            ruleId: 42,
            ruleVersion: 3,
            runId: 7,
            stepIndex: 1,
            actionKey: 'notify',
            occurrenceKey: '2026-W37',
            principal: Principal::user(5),
            now: $now,
            deadline: $now->modify('+5 minutes'),
            subjectType: 'user',
            subjectId: $subjectId,
            dryRun: $dryRun,
            cascadeDepth: $cascadeDepth,
            cascadeChain: $cascadeChain,
        );
    }
}
