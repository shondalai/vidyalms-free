<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Interaction;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerError;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\ErrorKind;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ChoiceSequence;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ShufflePolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseCodec;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveInterface;

/**
 * What every primitive shares: decoding through the response codec, the
 * shuffle mechanics that honour a per-choice fixed flag, and the two
 * cardinality constraints.
 *
 * Designed for extension rather than final, because the eight primitives
 * genuinely are one behaviour with eight sets of field names, and a third
 * party writing a ninth should inherit the envelope handling rather than
 * copying it.
 *
 * **The entry vocabulary is published rather than assumed.** The promise this
 * class makes is that a type inherits seeded shuffle honouring `fixed`, and
 * min and max enforcement, without writing a line of it. That promise is only
 * kept for a definition whose list entries are shaped the way the primitive
 * expects, and reading only the literal `key` broke it silently for every
 * definition shaped the way the catalogue specifies: a list of `{"id": ...}`
 * produced an empty ChoiceSequence, so the item never shuffled, the per-choice
 * `fixed` flag was never honoured, and no error was raised anywhere. The
 * accepted identifier names and the constraint aliases are therefore constants
 * on this class, documented in the catalogue, and covered by a registry-walking
 * test rather than by each vendor's diligence.
 */
abstract class AbstractInteractionPrimitive implements InteractionPrimitiveInterface
{
    public const CONSTRAINT_MIN = 'min_selections';
    public const CONSTRAINT_MAX = 'max_selections';
    public const CONSTRAINT_MIN_MESSAGE = 'min_message';
    public const CONSTRAINT_MAX_MESSAGE = 'max_message';
    public const CONSTRAINT_CONTROL_ID = 'control_id';

    /** Every identifier the item declares, for the membership check below. */
    public const CONSTRAINT_KNOWN_IDS = 'known_identifiers';

    /**
     * The field names a list entry may carry its identifier under, in the
     * order they are read. `key` is what the free six write; `id` is what
     * every schema in the catalogue writes, and a type using either gets the
     * same shuffle and the same constraint enforcement.
     *
     * @var list<string>
     */
    public const ENTRY_ID_KEYS = ['key', 'id'];

    /** The field name a per-choice pin lives under. */
    public const ENTRY_FIXED_KEY = 'fixed';

    /**
     * A type states its cardinality constraints in its own field names and
     * the primitive lifts them into the two it enforces. The pairs are
     * published so a type author can read what will be picked up rather than
     * discovering by experiment that it was not.
     *
     * @var array<string, string> the type's field name => the constraint it means
     */
    public const CONSTRAINT_ALIASES = [
        'min_selections' => self::CONSTRAINT_MIN,
        'max_selections' => self::CONSTRAINT_MAX,
        'min_choices' => self::CONSTRAINT_MIN,
        'max_choices' => self::CONSTRAINT_MAX,
        'min_associations' => self::CONSTRAINT_MIN,
        'max_associations' => self::CONSTRAINT_MAX,
    ];

    public function __construct(private readonly ?TranslatorPort $translator = null)
    {
    }

    public function decodeResponse(mixed $wire, ResponseShape $shape, DecodeContext $context): ResponseValue
    {
        return ResponseCodec::decode($wire, $shape);
    }

    /**
     * Whether this primitive could read the wire as a response to an item of
     * this shape at all.
     *
     * The shape's own structural check is the answer for almost everything,
     * and it is what stops one interaction reading another's envelope as a
     * valid answer. What it cannot know is the spellings a particular
     * primitive documents as equivalent: an acknowledgement arrives as a
     * boolean where the shape says integer, a numeric answer arrives as the
     * bare string the learner typed where the shape says a record, and both
     * are the design's own wire for those types rather than a client bug. A
     * primitive that reads such a spelling says so here, once, beside the
     * decoder that reads it.
     */
    public function acceptsWire(mixed $wire, ResponseShape $shape): bool
    {
        return $shape->accepts($wire);
    }

    public function validateAnswer(array $constraints, ResponseValue $value, DecodeContext $context): AnswerValidity
    {
        if ($value->isNullOrEmpty()) {
            return AnswerValidity::empty();
        }

        $unknown = $this->unknownIdentifiers($constraints, $value->distinctMembers());

        if ($unknown !== []) {
            return AnswerValidity::invalid($unknown);
        }

        return $this->checkCardinality($constraints, \count($value->distinctMembers()));
    }

    public function projectConstraints(array $definition): array
    {
        $constraints = [];

        foreach (self::CONSTRAINT_ALIASES as $field => $constraint) {
            $value = $definition[$field] ?? null;

            if (\is_int($value)) {
                $constraints[$constraint] = $value;
            }
        }

        foreach ([self::CONSTRAINT_MIN_MESSAGE, self::CONSTRAINT_MAX_MESSAGE, self::CONSTRAINT_CONTROL_ID] as $field) {
            $value = $definition[$field] ?? null;

            if (\is_string($value)) {
                $constraints[$field] = $value;
            }
        }

        $known = $this->declaredIdentifiers($definition);

        if ($known !== []) {
            $constraints[self::CONSTRAINT_KNOWN_IDS] = $known;
        }

        return $constraints;
    }

    /**
     * The definition fields whose entries name the identifiers a response to
     * this item may use.
     *
     * Deliberately not shuffledFields(): that list is conditional on the
     * item's shuffle flags for some families, so an item with shuffling off
     * would declare no identifiers at all and every response would pass.
     *
     * Default empty, which means the check below does nothing, which is the
     * behaviour every primitive had before it existed. A primitive whose
     * response names things the item declares overrides it.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    protected function identifierFields(array $definition = []): array
    {
        return [];
    }

    /**
     * Every identifier this item declares, from the fields above.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    final protected function declaredIdentifiers(array $definition): array
    {
        $known = [];

        foreach ($this->identifierFields($definition) as $field) {
            $entries = $definition[$field] ?? null;

            if (!\is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                $id = self::identifierOf($entry);

                if ($id !== null) {
                    $known[] = $id;
                }
            }
        }

        return array_values(array_unique($known));
    }

    /**
     * Members that name something this item does not have.
     *
     * An identifier that is not one of the item's own is not a wrong answer,
     * it is an answer to a different question: it is what a stale bundle
     * posts after an item is re-authored, and it is what another type's
     * response looks like when the two share a wire spelling. A bare list of
     * strings is a well-formed multiple:identifier response whatever the
     * strings are, so this is the only thing that can tell "the learner chose
     * badly" from "this is not a response to this item", and QTI keeps those
     * two apart on purpose.
     *
     * **A member is foreign when none of its endpoints is one of the item's
     * own, not when one of them is.** A pair joins something the item
     * declares to something it may not: a marker placed freely on an image is
     * a declared marker paired with a coordinate, and requiring both ends to
     * be declared would refuse the response that type exists to produce. One
     * recognised end is enough to say the response is addressed to this item;
     * whether the other end is where it should be is the interaction's own
     * business, and where the value is right is nobody's before submission.
     *
     * @param array<string, mixed> $constraints
     * @param list<string>         $members
     *
     * @return list<AnswerError>
     */
    final protected function unknownIdentifiers(array $constraints, array $members): array
    {
        $known = $constraints[self::CONSTRAINT_KNOWN_IDS] ?? null;

        if (!\is_array($known) || $known === []) {
            return [];
        }

        $controlId = \is_string($constraints[self::CONSTRAINT_CONTROL_ID] ?? null)
            ? $constraints[self::CONSTRAINT_CONTROL_ID]
            : $this->key();

        foreach ($members as $member) {
            $endpoints = self::identifierEndpointsOf($member);

            if ($endpoints === []) {
                continue;
            }

            foreach ($endpoints as $endpoint) {
                if (\in_array($endpoint, $known, true)) {
                    continue 2;
                }
            }

            return [AnswerError::of(
                $controlId,
                ErrorKind::InvalidEnvelope,
                $this->say(NeutralStrings::ERROR_INVALID_ENVELOPE),
            )];
        }

        return [];
    }

    /**
     * One member read as the identifiers it names: itself, or the two ends of
     * a pair however the pair was spelled.
     *
     * @return list<string>
     */
    private static function identifierEndpointsOf(mixed $member): array
    {
        if (\is_array($member)) {
            return array_values(array_filter(
                $member,
                static fn (mixed $end): bool => \is_string($end),
            ));
        }

        if (!\is_string($member)) {
            return [];
        }

        $parts = explode(' ', $member);

        return \count($parts) === 2 ? $parts : [$member];
    }

    public function presentationSequence(array $definition, Seed $seed, ShufflePolicy $shuffle): ChoiceSequence
    {
        if (!$shuffle->enabled) {
            return ChoiceSequence::empty();
        }

        $sequences = [];

        foreach ($this->shuffledFields($definition) as $field) {
            $entries = $definition[$field] ?? null;

            if (!\is_array($entries) || $entries === []) {
                continue;
            }

            $keys = [];
            $fixed = [];

            foreach ($entries as $entry) {
                $id = self::identifierOf($entry);

                if ($id === null) {
                    continue;
                }

                $keys[] = $id;

                // QTI's fixed attribute. Without it every "None of the
                // above" item breaks the moment shuffling ships, and the
                // retrofit is a definition-format migration across every
                // authored question.
                if (\is_array($entry) && ($entry[self::ENTRY_FIXED_KEY] ?? false) === true) {
                    $fixed[] = $id;
                }
            }

            if ($keys === []) {
                continue;
            }

            $sequences[$field] = $seed->shuffle($this->key() . ':' . $field, $keys, $fixed);
        }

        return new ChoiceSequence($sequences);
    }

    /**
     * The identifier of one list entry, under any of the published names.
     */
    final public static function identifierOf(mixed $entry): ?string
    {
        if (!\is_array($entry)) {
            return null;
        }

        foreach (self::ENTRY_ID_KEYS as $field) {
            $value = $entry[$field] ?? null;

            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The definition fields whose entries this primitive shuffles.
     *
     * The definition is passed because several types name a shuffle flag per
     * list rather than one for the item: an association's own worked example
     * sets shuffle_sources false and shuffle_targets true, and a fixed list
     * of fields shuffled both. The quiz-level switch stays the outer gate
     * above, so a primitive answering here is answering "which of my lists
     * does this item want shuffled", never "may anything shuffle".
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    abstract protected function shuffledFields(array $definition = []): array;

    /**
     * The one place min and max cardinality are enforced, so every type that
     * uses this primitive gets the same rule and the same error kinds.
     *
     * The two default messages go through TranslatorPort: the error summary
     * repeats them verbatim to a learner, so they are copy. An authored
     * min_message or max_message replaces them entirely, which is what lets a
     * type say something the core has no words for.
     *
     * @param array<string, mixed> $constraints
     */
    final protected function checkCardinality(array $constraints, int $count): AnswerValidity
    {
        $controlId = \is_string($constraints[self::CONSTRAINT_CONTROL_ID] ?? null)
            ? $constraints[self::CONSTRAINT_CONTROL_ID]
            : $this->key();
        $errors = [];

        $min = $constraints[self::CONSTRAINT_MIN] ?? null;

        if (\is_int($min) && $min > 0 && $count < $min) {
            $errors[] = AnswerError::of(
                $controlId,
                ErrorKind::TooFewSelected,
                \is_string($constraints[self::CONSTRAINT_MIN_MESSAGE] ?? null)
                    ? $constraints[self::CONSTRAINT_MIN_MESSAGE]
                    : $this->say(NeutralStrings::ERROR_TOO_FEW_SELECTED, ['min' => $min]),
            );
        }

        $max = $constraints[self::CONSTRAINT_MAX] ?? null;

        if (\is_int($max) && $max > 0 && $count > $max) {
            $errors[] = AnswerError::of(
                $controlId,
                ErrorKind::TooManySelected,
                \is_string($constraints[self::CONSTRAINT_MAX_MESSAGE] ?? null)
                    ? $constraints[self::CONSTRAINT_MAX_MESSAGE]
                    : $this->say(NeutralStrings::ERROR_TOO_MANY_SELECTED, ['max' => $max]),
            );
        }

        return $errors === [] ? AnswerValidity::valid() : AnswerValidity::invalid($errors);
    }

    /**
     * Translates one of the core catalogue's keys, falling back to its
     * neutral English for a caller with no host translator.
     *
     * Overridable rather than final, because a package whose primitive raises
     * its own error kinds owns its own catalogue: it overrides this, answers
     * its own key prefix, and delegates the core's keys back here.
     *
     * @param array<string, scalar|null> $parameters
     */
    protected function say(string $key, array $parameters = []): string
    {
        return NeutralStrings::say($this->translator, $key, $parameters);
    }
}
