<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Provider\Ai\Placement\ReservedFields;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckNearDuplicate;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftKinds;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskInput;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskOutput;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Ai\TargetsExtensionPoint;
use Shondalai\VidyaLMS\Core\Registry\Ai\TaskDescriptor;
use Shondalai\VidyaLMS\Core\Registry\Ai\TaskMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\PlacementContext;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The structural rules the AI design publishes, as a test class that stays.
 *
 * Every one of these is a rule that a screen spec, a docblock or a review
 * comment cannot enforce, and every one of them describes a way the product
 * could quietly stop being what it says it is. They are asserted over
 * **declared properties** and over the booted registries rather than over key
 * prefixes or file names, because a third party's key is their own namespace:
 * a vendor quality check named after their package escaped a prefix test
 * entirely, and a vendor content task whose key happened to begin `check.`
 * was wrongly constrained by one, on a key that may never change once
 * shipped.
 *
 * The golden rule underneath all of them is short. An AI helper proposes; the
 * shipped domain rules decide. No task targets a grading, availability,
 * credential or enrolment use case, no output schema binds a scored or gated
 * field, no placement lists one, no draft is reachable by a learner read
 * path, and nothing an importer wrote is ever tagged as a trusted
 * instruction.
 */
final class AiStructuralRulesTest extends TestCase
{
    /** Use case families an AI task may never write through. */
    private const FORBIDDEN_TARGETS = [
        'grading',
        'grade',
        'availability',
        'availability_rule',
        'credential',
        'certificate',
        'enrolment',
        'entitlement',
        'seat',
    ];

    private Registries $registries;

    protected function setUp(): void
    {
        $this->registries = ManagementFixture::create()->kernel->registries;
    }

    // ── The findings rules, over the property and never over a prefix ────

    public function testAFindingsOnlyTaskNeverTargetsAnExtensionPoint(): void
    {
        $checked = 0;

        foreach ($this->tasks() as $task) {
            if (!$task instanceof ProducesFindingsOnly) {
                continue;
            }

            self::assertNotInstanceOf(
                TargetsExtensionPoint::class,
                $task,
                $task->key() . ' declares findings only and also declares somewhere to write',
            );

            if ($task instanceof DeclaresTaskOutput) {
                $kind = $task->draftKind();

                self::assertTrue(
                    $kind === null || $kind === AiDraftKinds::FINDINGS,
                    $task->key() . ' declares findings only and a draft kind of ' . (string) $kind,
                );
            }

            ++$checked;
        }

        self::assertSame(8, $checked, 'the free build ships eight findings-only checks');
    }

    public function testNoJudgeCanEverWriteTheAcceptedState(): void
    {
        // The structural form of "a judge may reject and may flag, and may
        // never write the accepted state". There is no acceptor for the
        // findings kind anywhere in the product, so a check has no accept
        // path to be wired into by anybody.
        self::assertFalse($this->registries->aiDraftAcceptors->has(AiDraftKinds::FINDINGS));

        foreach (AiDraftKinds::DISPLAY_ONLY_KINDS as $kind) {
            self::assertFalse(
                $this->registries->aiDraftAcceptors->has($kind),
                $kind . ' is display only by construction and must have no acceptor',
            );
        }
    }

    /**
     * Inverted: the population is every registered acceptor kind, and the two
     * const lists are the known-free subset rather than the universe.
     *
     * The old shape read `if (!in_array($kind, ARTEFACT_KINDS)) continue;`,
     * over a five-entry const, so a task whose draft kind was outside that
     * list skipped the assertion entirely. Three of the six kinds with a
     * registered free acceptor were in neither list, and every vendor kind is
     * outside it by construction, which is the exact population the
     * extensibility story is about.
     */
    public function testEveryTaskWhoseDraftAnAcceptorCanWriteSaysWhereItWouldBeWritten(): void
    {
        $writable = $this->registries->aiDraftAcceptors->keys();
        self::assertNotSame([], $writable, 'the accept seam ships no free provider');

        foreach ($this->tasks() as $task) {
            if (!$task instanceof DeclaresTaskOutput) {
                continue;
            }

            $kind = $task->draftKind();

            if ($kind === null) {
                continue;
            }

            // An artefact kind is one an acceptor can write, plus the shipped
            // list, so a task declaring a kind whose acceptor is not installed
            // today is still held to the rule.
            if (!\in_array($kind, $writable, true) && !\in_array($kind, AiDraftKinds::ARTEFACT_KINDS, true)) {
                continue;
            }

            self::assertInstanceOf(
                TargetsExtensionPoint::class,
                $task,
                $task->key() . ' produces an artefact kind and names no extension point, so an accepted '
                . 'draft would have no acceptor to resolve',
            );
        }
    }

    /**
     * The two const lists and the registered acceptors cannot drift apart.
     *
     * module, alt_text and feedback_text each had a registered free acceptor
     * and appeared in neither list, so the rule above skipped them and the
     * learner-facing rule below passed trivially for them.
     */
    public function testEveryRegisteredAcceptorKindIsInExactlyOneOfTheTwoPublishedLists(): void
    {
        foreach ($this->registries->aiDraftAcceptors->keys() as $kind) {
            $artefact = \in_array($kind, AiDraftKinds::ARTEFACT_KINDS, true);
            $display = \in_array($kind, AiDraftKinds::DISPLAY_ONLY_KINDS, true);

            self::assertTrue(
                $artefact !== $display,
                sprintf(
                    'the acceptor kind "%s" is in %s of the two published lists, so every structural rule '
                    . 'written in terms of them either skips it or contradicts itself',
                    $kind,
                    $artefact ? 'both' : 'neither',
                ),
            );
        }
    }

    // ── The no-mark redline, as a contract rather than a screen spec ─────

    public function testNoTaskTargetsAGradingAvailabilityCredentialOrEnrolmentUseCase(): void
    {
        foreach ($this->tasks() as $task) {
            if (!$task instanceof TargetsExtensionPoint) {
                continue;
            }

            $target = strtolower($task->targetExtensionPoint());

            foreach (self::FORBIDDEN_TARGETS as $forbidden) {
                self::assertNotSame(
                    $forbidden,
                    $target,
                    $task->key() . ' targets ' . $target . ', which decides rather than proposes',
                );
            }
        }
    }

    public function testNoDeclaredOutputSchemaBindsAScoredOrGatedField(): void
    {
        foreach ($this->tasks() as $task) {
            if (!$task instanceof DeclaresTaskOutput) {
                continue;
            }

            self::assertSame(
                [],
                ReservedFields::boundBy($task->outputSchema()),
                $task->key() . ' binds a reserved field in its declared output',
            );
        }
    }

    public function testNoDeclaredInputSchemaBindsAScoredOrGatedField(): void
    {
        foreach ($this->tasks() as $task) {
            if (!$task instanceof DeclaresTaskInput) {
                continue;
            }

            self::assertSame(
                [],
                ReservedFields::boundBy($task->inputSchema()),
                $task->key() . ' takes a reserved field as an authored option',
            );
        }
    }

    public function testNoPlacementListsAScoredOrGatedFieldAndTheScoreFieldAppearsInNone(): void
    {
        $fillable = [];

        foreach ($this->placements() as $placement) {
            foreach ($placement->fillableFields() as $field) {
                self::assertFalse(
                    ReservedFields::isReserved($field),
                    $placement->key() . ' may fill ' . $field . ', which is a scored or gated field',
                );

                $fillable[] = $field;
            }
        }

        self::assertNotSame([], $fillable, 'at least one placement fills a field, or the rule is vacuous');
        self::assertNotContains('score', $fillable);
        self::assertContains(
            'feedback_to_learner',
            $fillable,
            'the gradebook helper fills the feedback field, and that is the only thing it fills',
        );
    }

    // ── The binding between a task and a placement ──────────────────────

    public function testEveryPlacementATaskNamesExistsAndAcceptsIt(): void
    {
        $context = PlacementContext::create(Principal::user(7), 'instructor_workbench', 'anchor');

        foreach ($this->tasks() as $task) {
            if (!$task instanceof TaskMetadataInterface) {
                continue;
            }

            foreach ($task->placements() as $placementKey) {
                self::assertTrue(
                    $this->registries->aiPlacements->has($placementKey),
                    $task->key() . ' asks for the placement ' . $placementKey . ', which nothing registers',
                );

                $placement = $this->registries->aiPlacements->get($placementKey);

                self::assertTrue(
                    $placement->accepts($this->descriptorFor($task, $placement), $context),
                    $task->key() . ' asks for ' . $placementKey . ', whose predicate refuses its shape',
                );
            }
        }
    }

    public function testNoDraftIsReachableByALearnerReadPath(): void
    {
        foreach ($this->placements() as $placement) {
            $surface = $this->registries->aiSurfaces->get($placement->surfaceKey());

            if ($surface->defaultDisclosureMode()->value !== 'every_interaction') {
                continue;
            }

            // A learner-facing surface may render findings and nothing that
            // becomes an artefact: the one learner-facing task in the design
            // produces no draft row at all, so there is nothing for a learner
            // read path to reach.
            //
            // The forbidden set is every kind an installed acceptor can write,
            // plus the shipped artefact list. Asserting against the const list
            // alone passed trivially for any kind outside it, which is every
            // vendor kind and, until this wave, three of the free ones.
            $writable = array_values(array_unique([
                ...$this->registries->aiDraftAcceptors->keys(),
                ...AiDraftKinds::ARTEFACT_KINDS,
            ]));

            foreach ($this->acceptedKindsOf($placement) as $kind) {
                self::assertNotContains(
                    $kind,
                    $writable,
                    $placement->key() . ' is learner facing and would render the artefact kind ' . $kind,
                );
            }
        }
    }

    public function testEveryPlacementAnchorsIntoARegisteredSurface(): void
    {
        foreach ($this->placements() as $placement) {
            self::assertTrue(
                $this->registries->aiSurfaces->has($placement->surfaceKey()),
                $placement->key() . ' anchors into the unregistered surface ' . $placement->surfaceKey(),
            );
        }
    }

    public function testNoPlacementInheritsALowerDisclosureThanItsSurface(): void
    {
        foreach ($this->placements() as $placement) {
            $surface = $this->registries->aiSurfaces->get($placement->surfaceKey());

            if ($surface->defaultDisclosureMode()->value !== 'every_interaction') {
                continue;
            }

            // A learner-facing surface discloses before every interaction, and
            // a placement able to lower that would be a placement able to opt
            // out of the disclosure obligation by registration.
            self::assertSame(
                'every_interaction',
                $placement->disclosure()->mode->value,
                $placement->key() . ' lowers the disclosure floor its surface sets',
            );
        }
    }

    // ── Facts ───────────────────────────────────────────────────────────

    public function testEveryDeclaredFactIsSuppliedByARegisteredProvider(): void
    {
        foreach ($this->tasks() as $task) {
            if (!$task instanceof RequiresFacts) {
                continue;
            }

            foreach ($task->requiredFacts() as $fact) {
                self::assertTrue(
                    $this->registries->aiFactProviders->has($fact),
                    $task->key() . ' declares the fact ' . $fact . ', which no provider supplies',
                );
            }
        }
    }

    public function testAFactOverASetOfPeopleDeclaresItselfAsMany(): void
    {
        $many = [];
        $one = [];

        foreach ($this->registries->aiFactProviders->all() as $fact) {
            match ($fact->resolvesToUsers()) {
                FactCardinality::Many => $many[] = $fact->key(),
                FactCardinality::One => $one[] = $fact->key(),
                FactCardinality::None => null,
            };
        }

        // The cardinality is what drives the subject write: a fact whose
        // values resolve to more than one user yields one subject row per
        // user, so the answer-grouping run is honestly recorded and every
        // learner in it can see it in their own activity view.
        self::assertContains('response.body_set', $many);
        self::assertContains('submission.body', $one);
        self::assertContains('response.body', $one);
        self::assertNotContains('item.facility', $many, 'an aggregate over people is not a fact about them');
        self::assertNotContains('report.dataset', $many, 'a cohort report must never become a subject list');
    }

    public function testTheEighteenShippedFactsAreAllRegistered(): void
    {
        $expected = [
            'item.facility', 'item.discrimination', 'item.option_frequencies', 'item.non_response',
            'item.definition', 'item.author_rationale', 'item.stem_redacted',
            'submission.body', 'submission.rubric', 'assignment.brief',
            'grade.recorded_mark', 'report.dataset', 'attempt.closed', 'attempt.recorded_mark',
            'response.body', 'response.body_set', 'bank.sibling_digests', 'bank.sibling_vectors',
        ];

        $registered = $this->registries->aiFactProviders->keys();
        sort($expected);
        sort($registered);

        self::assertSame($expected, $registered);
    }

    // ── Acceptors ───────────────────────────────────────────────────────

    public function testTheSixFreeAcceptorsAreRegisteredAndThePaidThreeAreNot(): void
    {
        foreach (['question', 'lesson', 'module', 'course_outline', 'alt_text', 'feedback_text'] as $kind) {
            self::assertTrue($this->registries->aiDraftAcceptors->has($kind), $kind . ' has no free acceptor');
        }

        foreach (['bank_entry', 'rubric', 'translation'] as $kind) {
            // The published miss: the draft cannot be accepted, the review
            // screen names the package that would supply the acceptor, and
            // nothing is written.
            self::assertFalse($this->registries->aiDraftAcceptors->has($kind), $kind . ' is a paid acceptor');
        }
    }

    // ── The free recipe ─────────────────────────────────────────────────

    public function testTheFreeRecipeIsAnOrderedListOfRegisteredTasks(): void
    {
        $recipe = $this->registries->aiRecipes->get('outline.structural_build');
        $steps = $recipe->steps();

        self::assertGreaterThan(1, \count($steps), 'a one-step recipe is a task with extra ceremony');
        self::assertNull($steps[0]->fansOutOverDraftKind, 'the first step fans out over nothing');

        foreach ($steps as $position => $step) {
            self::assertTrue(
                $this->registries->aiTasks->has($step->taskKey),
                'step ' . $position . ' names the unregistered task ' . $step->taskKey,
            );
        }

        foreach (\array_slice($steps, 1) as $step) {
            self::assertNotNull(
                $step->fansOutOverDraftKind,
                'every later step fans out over what an earlier one produced',
            );
            self::assertTrue(
                $step->skippableWhenEmpty,
                'a step over nothing is skipped rather than failing a whole build',
            );
        }
    }

    // ── The two duplicate thresholds ────────────────────────────────────

    public function testBothDuplicateThresholdsArePublishedAndSiteConfigurable(): void
    {
        self::assertSame(0.65, CheckNearDuplicate::DEFAULT_ITEM_THRESHOLD);
        self::assertSame(0.85, CheckNearDuplicate::DEFAULT_PROSE_THRESHOLD);

        $source = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Provider/Ai/Task/CheckNearDuplicate.php',
        );

        // Read from the authored options rather than from the constant, so a
        // site that typed its own number gets its own number.
        self::assertStringContainsString("TaskInputs::number(\$request, 'item_threshold'", $source);
    }

    // ── The trust marking ───────────────────────────────────────────────

    public function testNothingOutsideTheSeamEverTagsASegmentAsATrustedInstruction(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(\dirname(__DIR__, 3) . '/src') as $relative => $path) {
            if (str_starts_with($relative, 'Registry/Ai/')) {
                // The vocabulary lives here: the enum case and the factory
                // that names it. Declaring a level is not using one.
                continue;
            }

            $contents = (string) file_get_contents($path);

            if (str_contains($contents, 'TrustedInstruction') || str_contains($contents, 'PromptSegment::instruction')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'a trusted instruction is the task own prompt and nothing else. Anything an importer, a lesson '
            . 'body or a submission wrote is untrusted content, and the marking must not be defeasible.',
        );
    }

    // ── No task names a bridge, no bridge names a task ──────────────────

    public function testNoTaskNamesABridgeAndNoBridgeNamesATask(): void
    {
        $taskNames = $this->shortNamesOf($this->tasks());
        $bridgeNames = $this->shortNamesOf($this->registries->modelBridges->all());

        foreach ($this->phpFiles(\dirname(__DIR__, 3) . '/src/Provider/Ai/Task') as $relative => $path) {
            $contents = (string) file_get_contents($path);

            foreach ($bridgeNames as $bridge) {
                self::assertStringNotContainsString(
                    $bridge,
                    $contents,
                    'Provider/Ai/Task/' . $relative . ' names the bridge ' . $bridge,
                );
            }
        }

        foreach ($this->phpFiles(\dirname(__DIR__, 3) . '/src/Provider/Ai') as $relative => $path) {
            if (!str_ends_with($relative, 'ModelBridge.php')) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            foreach ($taskNames as $task) {
                self::assertStringNotContainsString(
                    $task,
                    $contents,
                    'the bridge ' . $relative . ' names the task ' . $task,
                );
            }
        }
    }

    public function testPackagesCoreNamesNoModelVendorAndNoHostAiComponent(): void
    {
        // The same enumeration the CI grep runs, kept here so the rule fails a
        // test run as well as a build step. The names are assembled from
        // fragments so this file does not itself carry one.
        $forbidden = ['Ai' . 'ServiceClient', 'com_' . 'aicore', 'Bed' . 'rock', 'Open' . 'AI', 'Anthro' . 'pic'];
        $offenders = [];

        foreach ($this->phpFiles(\dirname(__DIR__, 3) . '/src') as $relative => $path) {
            $contents = (string) file_get_contents($path);

            foreach ($forbidden as $name) {
                if (str_contains($contents, $name)) {
                    $offenders[] = $relative . ' names ' . $name;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * @return list<AiTaskInterface>
     */
    private function tasks(): array
    {
        return $this->registries->aiTasks->all();
    }

    /**
     * @return list<AiPlacementInterface>
     */
    private function placements(): array
    {
        return $this->registries->aiPlacements->all();
    }

    /**
     * The draft kinds a placement would draw, discovered by asking its own
     * predicate rather than by reading a list it does not publish.
     *
     * The predicate is the contract, so probing it is what the mount does and
     * what a picker does. Reflecting into the private list instead would
     * assert something a third-party placement has no obligation to have.
     *
     * @return list<string>
     */
    private function acceptedKindsOf(AiPlacementInterface $placement): array
    {
        $context = PlacementContext::create(
            Principal::user(7),
            $placement->surfaceKey(),
            $placement->anchor(),
        );
        $accepted = [];

        foreach (array_merge(AiDraftKinds::ARTEFACT_KINDS, AiDraftKinds::DISPLAY_ONLY_KINDS) as $kind) {
            $descriptor = TaskDescriptor::of(
                'probe.' . $kind,
                [$placement->surfaceKey()],
                $kind,
                $placement->scopeLevel(),
            );

            if ($placement->accepts($descriptor, $context)) {
                $accepted[] = $kind;
            }
        }

        return $accepted;
    }

    private function descriptorFor(AiTaskInterface $task, AiPlacementInterface $placement): TaskDescriptor
    {
        return TaskDescriptor::of(
            $task->key(),
            [$placement->surfaceKey()],
            $task instanceof DeclaresTaskOutput ? $task->draftKind() : null,
            $placement->scopeLevel(),
            $task instanceof TargetsExtensionPoint ? $task->targetExtensionPoint() : null,
        );
    }

    /**
     * @param list<object> $providers
     *
     * @return list<string>
     */
    private function shortNamesOf(array $providers): array
    {
        $names = [];

        foreach ($providers as $provider) {
            $names[] = (new \ReflectionClass($provider))->getShortName();
        }

        return $names;
    }

    /**
     * @return array<string, string> relative path to absolute path
     */
    private function phpFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $file->getRealPath();
            $files[str_replace('\\', '/', substr($path, \strlen($root) + 1))] = $path;
        }

        ksort($files);

        return $files;
    }
}
