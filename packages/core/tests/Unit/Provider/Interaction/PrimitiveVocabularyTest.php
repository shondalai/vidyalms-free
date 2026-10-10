<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Interaction;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Boot\AssessmentProviders;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ShufflePolicy;
use Shondalai\VidyaLMS\Core\Provider\Interaction\AbstractInteractionPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\AssociatePrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\SelectPrimitive;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PrimitiveComposingInterface;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

/**
 * The entry vocabulary a primitive reads is published, not assumed.
 *
 * The promise the primitives make is that a type inherits seeded shuffle that
 * honours per-choice `fixed`, and min and max enforcement, without writing a
 * line of it. Reading only the literal `key` and only the literal
 * `min_selections` broke that silently for every definition shaped the way
 * the catalogue specifies (`{"id": ...}` entries, `min_choices`), which is
 * the worst failure mode available: no shuffle, no fixed handling, no
 * constraint enforcement, and no error anywhere.
 */
final class PrimitiveVocabularyTest extends TestCase
{
    /**
     * The design's own select_media shape, which names its regions by `id`
     * and its cardinality by min_choices and max_choices.
     */
    public function testAnIdKeyedDefinitionShufflesAndEnforcesItsConstraints(): void
    {
        $primitive = new SelectPrimitive(new StubTranslator());
        $definition = [
            'regions' => [
                ['id' => 'r1', 'name' => 'One'],
                ['id' => 'r2', 'name' => 'Two'],
                ['id' => 'r3', 'name' => 'Three', 'fixed' => true],
            ],
            'min_choices' => 1,
            'max_choices' => 2,
        ];

        $sequence = $primitive->presentationSequence($definition, Seed::of('seed'), ShufflePolicy::resolve(true, false));

        self::assertNotSame([], $sequence->toArray(), 'an id-keyed list still shuffles');
        self::assertSame(['r1', 'r2', 'r3'], self::sorted($sequence->toArray()['regions'] ?? []));
        self::assertSame(
            'r3',
            ($sequence->toArray()['regions'] ?? [])[2] ?? null,
            'a fixed entry stays where it was authored',
        );

        $constraints = $primitive->projectConstraints($definition);

        self::assertSame(1, $constraints[AbstractInteractionPrimitive::CONSTRAINT_MIN] ?? null);
        self::assertSame(2, $constraints[AbstractInteractionPrimitive::CONSTRAINT_MAX] ?? null);
    }

    /**
     * The design's own associate shape: sources and targets by `id`, with a
     * per-entry match_max.
     */
    public function testAnIdKeyedAssociateProjectsItsPerEntryMatchMax(): void
    {
        $primitive = new AssociatePrimitive(new StubTranslator());

        $constraints = $primitive->projectConstraints([
            'sources' => [['id' => 's1', 'text' => 'France', 'match_max' => 1]],
            'targets' => [['id' => 't1', 'text' => 'Paris', 'match_max' => 2, 'fixed' => false]],
            'min_associations' => 1,
            'max_associations' => 4,
        ]);

        self::assertSame(1, $constraints[AbstractInteractionPrimitive::CONSTRAINT_MIN] ?? null);
        self::assertSame(4, $constraints[AbstractInteractionPrimitive::CONSTRAINT_MAX] ?? null);
        self::assertSame(
            ['s1' => 1, 't1' => 2],
            $constraints[AssociatePrimitive::CONSTRAINT_MATCH_MAX] ?? null,
        );
    }

    public function testTheKeySpellingKeepsWorking(): void
    {
        $primitive = new SelectPrimitive(new StubTranslator());
        $definition = [
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'min_selections' => 1,
        ];

        self::assertNotSame(
            [],
            $primitive->presentationSequence($definition, Seed::of('s'), ShufflePolicy::resolve(true, false))->toArray(),
        );
        self::assertSame(
            1,
            $primitive->projectConstraints($definition)[AbstractInteractionPrimitive::CONSTRAINT_MIN] ?? null,
        );
    }

    /**
     * The registry walk, stated as the invariant that actually matters: the
     * primitive must not care which of the published spellings a definition
     * uses. Rewriting every `key` to `id` and every `min_selections` to
     * `min_choices` must change nothing about the sequence or the projected
     * constraints, for every registered type, including one nobody here
     * wrote.
     */
    public function testEveryRegisteredTypeSequencesIdenticallyUnderEitherSpelling(): void
    {
        $registries = new Registries();
        AssessmentProviders::registerInto($registries, new StubTranslator());

        $sequenced = 0;

        foreach ($registries->questionTypes->all() as $type) {
            if (!$type instanceof PrimitiveComposingInterface || !$type instanceof DefinitionContractInterface) {
                continue;
            }

            $primitive = $registries->interactionPrimitives->resolve($type->interactionPrimitive());
            self::assertNotNull($primitive, sprintf('%s composes an unregistered primitive', $type->key()));

            $authored = $type->exampleDefinition();
            $renamed = self::asCatalogueSpelling($authored);
            $shuffle = ShufflePolicy::resolve(true, false);

            $before = $primitive->presentationSequence($authored, Seed::of('seed'), $shuffle)->toArray();
            $after = $primitive->presentationSequence($renamed, Seed::of('seed'), $shuffle)->toArray();

            self::assertSame($before, $after, sprintf(
                '%s sequences differently when its entries are identified by "id" rather than "key"',
                $type->key(),
            ));
            self::assertSame(
                $primitive->projectConstraints($authored),
                $primitive->projectConstraints($renamed),
                sprintf('%s projects different constraints under the catalogue spelling', $type->key()),
            );

            $sequenced += \count($before);
        }

        self::assertGreaterThan(0, $sequenced, 'the walk must actually sequence something');
    }

    /**
     * The same definition written the way every schema in the catalogue
     * writes it: entries identified by "id", cardinality named by
     * min_choices and max_choices.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private static function asCatalogueSpelling(array $definition): array
    {
        $renamed = [];

        foreach ($definition as $field => $value) {
            $target = match ($field) {
                'min_selections' => 'min_choices',
                'max_selections' => 'max_choices',
                default => $field,
            };

            $renamed[$target] = \is_array($value) ? self::renameEntries($value) : $value;
        }

        return $renamed;
    }

    /**
     * @param array<array-key, mixed> $entries
     *
     * @return array<array-key, mixed>
     */
    private static function renameEntries(array $entries): array
    {
        $out = [];

        foreach ($entries as $index => $entry) {
            if (\is_array($entry) && \is_string($entry['key'] ?? null)) {
                $rebuilt = [];

                foreach ($entry as $field => $value) {
                    $rebuilt[$field === 'key' ? 'id' : $field] = $value;
                }

                $out[$index] = $rebuilt;

                continue;
            }

            $out[$index] = \is_array($entry) ? self::renameEntries($entry) : $entry;
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private static function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}
