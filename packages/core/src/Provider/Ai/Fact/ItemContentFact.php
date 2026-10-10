<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Fact;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * What an item actually says, in three shapes with very different blast
 * radii.
 *
 * `item.definition` is the whole thing, key included, and it is declared by a
 * task only when the task legitimately needs the answer. `item.stem_redacted`
 * is the same item with every secret key removed, and it exists because of a
 * specific mistake worth naming: the learner-facing explanation task once
 * declared the full definition, which contains the key by construction since
 * the type enumerates its secret keys at any depth, into a call whose
 * untrusted span is the learner's own free text, with the result rendered
 * straight back to that learner. Verification is computed by the engine and
 * elaboration is the only part a model adds, and elaboration does not need
 * the key.
 *
 * `item.author_rationale` is the author's own explanation of why the answer
 * is the answer. It is the one piece of an item that helps an explanation
 * without giving anything away, which is why it is a fact of its own rather
 * than a field of the definition.
 */
final class ItemContentFact implements AiFactProviderInterface
{
    public const DEFINITION = 'item.definition';
    public const AUTHOR_RATIONALE = 'item.author_rationale';
    public const STEM_REDACTED = 'item.stem_redacted';

    /** The definition key an author's rationale is stored under. */
    public const RATIONALE_KEY = 'rationale';

    private function __construct(
        private readonly string $key,
        private readonly QuestionTypeRegistry $types,
    ) {
    }

    public static function definition(QuestionTypeRegistry $types): self
    {
        return new self(self::DEFINITION, $types);
    }

    public static function authorRationale(QuestionTypeRegistry $types): self
    {
        return new self(self::AUTHOR_RATIONALE, $types);
    }

    public static function stemRedacted(QuestionTypeRegistry $types): self
    {
        return new self(self::STEM_REDACTED, $types);
    }

    /**
     * @return list<self>
     */
    public static function all(QuestionTypeRegistry $types): array
    {
        return [self::definition($types), self::authorRationale($types), self::stemRedacted($types)];
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'ai.fact.' . str_replace('.', '_', $this->key) . '.label';
    }

    /** An item is not a person. */
    public function resolvesToUsers(): FactCardinality
    {
        return FactCardinality::None;
    }

    public function resolve(AiFactContext $context): mixed
    {
        if ($context->subjectType !== ItemStatisticFact::SUBJECT_QUESTION || $context->subjectId === null) {
            return null;
        }

        $row = $context->database->selectOne(
            Query::from('questions')
                ->select(['type', 'definition'])
                ->where('id', '=', $context->subjectId)
                ->limit(1),
        );

        if ($row === null) {
            return null;
        }

        $type = \is_string($row['type'] ?? null) ? $row['type'] : '';
        $definition = $this->decode($row['definition'] ?? null);

        return match ($this->key) {
            self::DEFINITION => $definition,
            self::AUTHOR_RATIONALE => \is_string($definition[self::RATIONALE_KEY] ?? null)
                ? $definition[self::RATIONALE_KEY]
                : null,
            default => $this->withoutSecrets($definition, $type),
        };
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $definition, string $type): array
    {
        if ($type === '' || !$this->types->has($type)) {
            // A type nothing registers cannot say what its secrets are, and
            // the safe reading of an unknown item is that all of it is one.
            return [];
        }

        $provider = $this->types->get($type);

        if (!$provider instanceof DefinitionContractInterface) {
            return [];
        }

        // The contract says "every definition key that carries correctness, AT
        // ANY DEPTH, named by its own leaf name", and this used to unset at the
        // top level only. The shipped six all keep their key at the top, so
        // nothing leaked today; a registered type nesting one, a per-option
        // `correct` or a per-blank `accepted`, leaked it straight through the
        // redacted stem, which exists precisely because the learner-facing
        // explanation task once carried the key.
        $definition = self::withoutKeys(
            $definition,
            [...$provider->secretKeys(), self::RATIONALE_KEY],
        );

        /** @var array<string, mixed> $definition */
        return $definition;
    }

    /**
     * Every leaf whose key is one of the named ones removed, at any depth.
     *
     * The rationale is the author's own words about the answer, so it is in
     * the same list: it travels with the redacted stem only when the task
     * asked for it by name through its own fact declaration.
     *
     * @param array<array-key, mixed> $value
     * @param list<string>            $keys
     *
     * @return array<array-key, mixed>
     */
    private static function withoutKeys(array $value, array $keys): array
    {
        $kept = [];

        foreach ($value as $name => $entry) {
            if (\is_string($name) && \in_array($name, $keys, true)) {
                continue;
            }

            $kept[$name] = \is_array($entry) ? self::withoutKeys($entry, $keys) : $entry;
        }

        return $kept;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $stored): array
    {
        if (\is_array($stored)) {
            /** @var array<string, mixed> $stored */
            return StoredShape::bodyOf($stored);
        }

        if (!\is_string($stored) || $stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);

        if (!\is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return StoredShape::bodyOf($decoded);
    }
}
