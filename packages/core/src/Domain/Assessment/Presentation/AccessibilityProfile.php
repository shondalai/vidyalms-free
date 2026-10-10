<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation;

/**
 * The learner's declared personal needs and preferences, stored verbatim
 * under the AfA PNP 3.0 element names so an import is a mapping rather than a
 * translation.
 *
 * It is a value object rather than a set of booleans because the element list
 * is long, is somebody else's vocabulary, and will grow. The core reads only
 * what it must act on and carries the rest through untouched, which is what
 * lets a package act on an element the core has never heard of.
 *
 * Three call sites need it and every one of them was handed a hardcoded
 * default before this existed: presentation (a renderer that must know), the
 * shuffle resolution (a simplified layout suppresses option shuffle), and the
 * integrity signals, which are suppressed entirely for a learner with a
 * declared profile because magnifiers, on-screen keyboards and read-aloud
 * tools all blur the browser.
 */
final class AccessibilityProfile
{
    /** AfA PNP 3.0: the learner asks for a single-column, simplified layout. */
    public const LAYOUT_SIMPLIFIED = 'displayTransformability.simplifiedLayout';

    /** AfA PNP 3.0: the learner reads the item through synthesised speech. */
    public const TEXT_TO_SPEECH = 'auditoryDescription.textToSpeech';

    /**
     * The five elements of the QTI v3.0 AfA profile whose tooling blurs the
     * browser window: a docked or separate-window magnifier, an on-screen
     * keyboard, a read-aloud application, a braille display's review mode and
     * the extra time that makes a learner switch away and back. Every one of
     * them produces the exact signal a naive integrity listener reports as
     * cheating, which is why recording is suppressed entirely rather than
     * recorded and discounted.
     */
    public const ADDITIONAL_TESTING_TIME = 'additional-testing-time';
    public const MAGNIFICATION = 'magnification';
    public const SPOKEN = 'spoken';
    public const BRAILLE = 'braille';
    public const INPUT_REQUIREMENTS = 'input-requirements';

    /** @var array<string, mixed> */
    public readonly array $elements;

    /**
     * @param array<string, mixed> $elements AfA PNP element names to values
     */
    private function __construct(array $elements)
    {
        $this->elements = $elements;
    }

    /**
     * @param array<string, mixed> $elements
     */
    public static function of(array $elements): self
    {
        return new self($elements);
    }

    /** No profile declared, which is the majority case and changes nothing. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    public static function fromArray(?array $stored): self
    {
        return $stored === null ? self::none() : new self($stored);
    }

    public function isDeclared(): bool
    {
        return $this->elements !== [];
    }

    /**
     * Whether option shuffle is suppressed for this learner.
     *
     * A simplified layout and a read-aloud presentation both depend on a
     * stable order: the first because the point of the request is to reduce
     * variation, the second because a learner navigating by ear is relearning
     * the list every time it moves. Randomisation intrinsic to the construct
     * overrides this, and that is the type's declaration rather than this
     * object's.
     */
    public function suppressesShuffle(): bool
    {
        return $this->isTrue(self::LAYOUT_SIMPLIFIED) || $this->isTrue(self::TEXT_TO_SPEECH);
    }

    /**
     * Whether integrity signals are recorded at all for this learner.
     *
     * Not "recorded and flagged as explicable": not recorded. A row that
     * exists is evidence a human will read, and evidence that a magnifier
     * produced is worse than no evidence, because the reader cannot tell the
     * two apart and the learner cannot rebut a log they never saw.
     */
    public function suppressesIntegrityAutomation(): bool
    {
        $blurring = [
            self::ADDITIONAL_TESTING_TIME,
            self::MAGNIFICATION,
            self::SPOKEN,
            self::BRAILLE,
            self::INPUT_REQUIREMENTS,
            self::TEXT_TO_SPEECH,
        ];

        foreach ($blurring as $element) {
            if ($this->isTrue($element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stored form, or null when nothing was declared, so an attempt row
     * carries no key at all for the majority case.
     *
     * @return array<string, mixed>|null
     */
    public function toArrayOrNull(): ?array
    {
        return $this->elements === [] ? null : $this->elements;
    }

    /**
     * The opaque identifier a provider context carries. Providers that only
     * need to know whether a profile is in force compare against null.
     */
    public function identifier(): ?string
    {
        if ($this->elements === []) {
            return null;
        }

        $names = array_keys($this->elements);
        sort($names);

        return implode(',', $names);
    }

    private function isTrue(string $element): bool
    {
        return ($this->elements[$element] ?? false) === true;
    }
}
