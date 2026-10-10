<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\QuestionType;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\FillBlankQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\MultipleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\OrderingQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\ShortAnswerQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\SingleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\TrueFalseQuestionType;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Context\PresentationContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ContextAwarePresenting;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionSchemaCheck;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

/**
 * The core invariant of the Assessment context: quiz correctness never
 * leaves the server before the item session closes.
 *
 * This is the structural form of the check, and the structure is the point.
 * The old version listed six providers and three giveaway field names by
 * hand, so a seventh type was covered only if somebody remembered to add it,
 * and a third-party type was covered by nothing at all. It now walks the
 * registry and drives every assertion from each provider's own
 * DefinitionContractInterface, so a type nobody here has reviewed is covered
 * the moment it registers.
 *
 * That is also why the contract carries exampleDefinition(): a structural
 * check needs a definition that actually populates every secret key, or the
 * hiding assertion proves nothing.
 */
final class AnswerHidingTest extends TestCase
{
    /**
     * Every provider this site registers, including one a third party could
     * add. The free six are registered explicitly rather than through the
     * kernel so the unit suite needs no database.
     */
    private static function registry(): QuestionTypeRegistry
    {
        $translator = new StubTranslator();
        $registry = new QuestionTypeRegistry();
        $registry->register(new SingleChoiceQuestionType($translator));
        $registry->register(new MultipleChoiceQuestionType($translator));
        $registry->register(new TrueFalseQuestionType($translator));
        $registry->register(new ShortAnswerQuestionType($translator));
        $registry->register(new FillBlankQuestionType($translator));
        $registry->register(new OrderingQuestionType($translator));

        return $registry;
    }

    /**
     * @return iterable<string, array{QuestionTypeInterface}>
     */
    public static function registeredTypes(): iterable
    {
        foreach (self::registry()->all() as $type) {
            yield $type->key() => [$type];
        }
    }

    #[DataProvider('registeredTypes')]
    public function testEveryTypeDeclaresItsDefinitionContract(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(
            DefinitionContractInterface::class,
            $type,
            'a type without a definition contract is unvalidated and uncovered by this test',
        );
    }

    #[DataProvider('registeredTypes')]
    public function testTheExampleDefinitionIsValidAndPopulatesEverySecretKey(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        $definition = $type->exampleDefinition();
        $type->validateDefinition($definition);

        $encoded = json_encode($definition, JSON_THROW_ON_ERROR);

        foreach ($type->secretKeys() as $secret) {
            self::assertStringContainsString(
                '"' . $secret . '"',
                $encoded,
                sprintf('%s declares "%s" secret but its example never sets it', $type->key(), $secret),
            );
        }
    }

    #[DataProvider('registeredTypes')]
    public function testThePresentedShapeCarriesNoSecretKey(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        $presented = $type->presentForLearner($type->exampleDefinition());
        $encoded = json_encode($presented, JSON_THROW_ON_ERROR);

        foreach ($type->secretKeys() as $secret) {
            self::assertStringNotContainsString(
                $secret,
                $encoded,
                sprintf('%s leaked "%s" into the learner projection', $type->key(), $secret),
            );
        }
    }

    #[DataProvider('registeredTypes')]
    public function testThePresentedShapeUsesOnlyPresentableKeys(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        $presented = $type->presentForLearner($type->exampleDefinition());
        $allowed = $type->presentableKeys();

        foreach (array_keys($presented) as $key) {
            self::assertContains(
                $key,
                $allowed,
                sprintf('%s presented "%s", which is not on its own allowlist', $type->key(), (string) $key),
            );
        }
    }

    /**
     * The context-taking path is the one an attempt actually uses, so it is
     * checked as well as the context-free one. A type that shuffled its
     * options and copied the key through would pass the first check and fail
     * here.
     */
    #[DataProvider('registeredTypes')]
    public function testTheContextAwarePresentationLeaksNothingEither(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        if (!$type instanceof ContextAwarePresenting) {
            // The context-free path is already covered above.
            $this->addToAssertionCount(1);

            return;
        }

        $presented = $type->presentForLearnerInContext(
            $type->exampleDefinition(),
            PresentationContext::unseeded(),
        );
        $encoded = json_encode($presented, JSON_THROW_ON_ERROR);

        foreach ($type->secretKeys() as $secret) {
            self::assertStringNotContainsString($secret, $encoded, $type->key());
        }
    }

    /**
     * The three names the hand-written version checked, kept as a
     * belt-and-braces assertion over every type at once: whatever a provider
     * declares, these three may never appear in a projection.
     */
    #[DataProvider('registeredTypes')]
    public function testTheUniversallyForbiddenNamesNeverAppear(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        $encoded = json_encode($type->presentForLearner($type->exampleDefinition()), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('correct', $encoded);
        self::assertStringNotContainsString('accepted', $encoded);
        self::assertStringNotContainsString('feedback', $encoded);
    }

    /**
     * A secret key is a leaf name, and a projection must not carry it at any
     * depth. This is the check a nested definition would otherwise slip
     * past.
     */
    #[DataProvider('registeredTypes')]
    public function testASecretKeyIsHiddenAtEveryDepth(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        // array_walk_recursive invokes its callback only for leaf values, so
        // a secret key whose value is an array was never visited by this
        // check at all. The walk below visits array-valued keys as well.
        self::assertSame(
            [],
            self::secretKeysIn($type->presentForLearner($type->exampleDefinition()), $type->secretKeys()),
            $type->key(),
        );
    }

    /**
     * The two allowlists must partition the schema.
     *
     * Without it a type can add an authorable field and name it in neither
     * list, and every check here is then silent about it: the projection
     * assertions only look at what the example happens to set, and the example
     * is written by the same vendor. Making the partition explicit turns an
     * undeclared key into a test failure rather than a blind spot.
     */
    #[DataProvider('registeredTypes')]
    public function testEveryAuthorableFieldIsEitherPresentableOrSecret(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        $properties = $type->definitionSchema()['properties'] ?? [];
        self::assertIsArray($properties);
        self::assertNotSame([], $properties, $type->key() . ' declares no schema properties');

        $declared = [...$type->presentableKeys(), ...$type->secretKeys(), ...self::STRUCTURAL_KEYS];

        foreach (array_keys($properties) as $field) {
            self::assertContains(
                (string) $field,
                $declared,
                sprintf(
                    '%s can be authored with "%s" but names it in neither presentableKeys() nor '
                    . 'secretKeys(), so nothing here says whether it may reach a learner.',
                    $type->key(),
                    (string) $field,
                ),
            );
        }
    }

    /**
     * The projection assertions driven by a definition the example does not
     * describe.
     *
     * The example is written by the same vendor as the allowlist, so a field a
     * real definition can carry but the example omits was exercised by
     * nothing. This fills every authorable field the schema declares with a
     * recognisable marker and asserts the secret ones still never appear.
     */
    #[DataProvider('registeredTypes')]
    public function testASecretFieldTheExampleOmitsIsStillHidden(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        $definition = $type->exampleDefinition();

        foreach ($type->secretKeys() as $secret) {
            /** @var array<string, mixed> $definition */
            $definition = self::markEverywhere($definition, $secret);
        }

        $encoded = json_encode($type->presentForLearner($definition), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::MARKER, $encoded, sprintf(
            '%s copied a value from a secret key through to the learner projection',
            $type->key(),
        ));
    }

    /** The marker written into every secret field, at every depth. */
    private const MARKER = 'x-secret-marker-x';

    /**
     * Fields that configure how an item is marked or how its payload is
     * stored, rather than being authored content of it.
     *
     * They belong to neither allowlist by construction: they carry no
     * correctness, so they are not secret, and they are deliberately not
     * presented, so they are not presentable. Naming them here keeps the
     * partition honest rather than turning the assertion into a rubber stamp
     * with an open-ended escape hatch.
     */
    private const STRUCTURAL_KEYS = [
        'schema_version',
        'policy',
        'scoring_intent',
        'shuffle',
        'case_sensitive',
    ];

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private static function markEverywhere(array $node, string $secret): array
    {
        foreach ($node as $key => $value) {
            if ($key === $secret) {
                $node[$key] = self::MARKER;

                continue;
            }

            if (\is_array($value)) {
                $node[$key] = self::markEverywhere($value, $secret);
            }
        }

        return $node;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string>            $secrets
     *
     * @return list<string>
     */
    private static function secretKeysIn(array $node, array $secrets): array
    {
        $found = [];

        foreach ($node as $key => $value) {
            if (\is_string($key) && \in_array($key, $secrets, true)) {
                $found[] = $key;
            }

            if (\is_array($value)) {
                $found = [...$found, ...self::secretKeysIn($value, $secrets)];
            }
        }

        return $found;
    }
    /**
     * definitionSchema() is load-bearing, in both directions: the example a
     * type ships satisfies the schema it publishes, and a definition the
     * schema refuses is refused by the validator.
     *
     * The design says validateDefinition delegates to the schema its own
     * class ships. It did not, in either build: every type published
     * additionalProperties false and every one accepted an unknown top-level
     * key, and nothing asserted the example satisfied the schema either, so
     * the published contract an importer or a third-party authoring tool
     * validates against described an engine that did something else.
     */
    #[DataProvider('registeredTypes')]
    public function testTheExampleSatisfiesTheSchemaTheTypePublishes(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        self::assertSame(
            [],
            DefinitionSchemaCheck::errors($type->definitionSchema(), $type->exampleDefinition(), '', true),
            sprintf('%s ships an example its own schema rejects', $type->key()),
        );
    }

    #[DataProvider('registeredTypes')]
    public function testADefinitionTheSchemaRefusesIsRefusedByTheValidator(QuestionTypeInterface $type): void
    {
        self::assertInstanceOf(DefinitionContractInterface::class, $type);

        self::assertFalse(
            $type->definitionSchema()['additionalProperties'] ?? true,
            sprintf('%s publishes an open schema, which validates nothing', $type->key()),
        );

        $definition = $type->exampleDefinition();
        $definition['x_not_a_field_of_this_type'] = 'anything at all';

        $this->expectException(ValidationException::class);
        $type->validateDefinition($definition);
    }
}
