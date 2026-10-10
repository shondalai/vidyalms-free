<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\AttemptPolicy;

use Shondalai\VidyaLMS\Core\Domain\Assessment\ItemSessionControl;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\AccessibilityProfile;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;

/**
 * The frozen quiz policy of one attempt, written to attempts.policy_snapshot
 * at StartAttempt and never read from the quiz again.
 *
 * Three consequences follow, and all three are the point. No question type
 * has any parameter, method or field through which a quiz policy could reach
 * it, so a type cannot accidentally depend on one. Changing a quiz setting
 * cannot silently regrade attempts already in flight, because the attempt
 * carries the policy it started under. And a new policy is a registration,
 * so negative marking, interactive multi-try and certainty-based marking are
 * additions with no core edit.
 *
 * The snapshot carries the whole quiz-level set, not the presentational
 * corner of it. A deadline that is derived from a close time, an expiry
 * policy that decides what a late save does, a try penalty that a policy
 * applies in fraction space and an aggregation rule that decides which
 * attempt is reported are all things a reader of a two-year-old attempt has
 * to be able to answer without the quiz row, which may have been edited
 * fifty times since.
 */
final class AttemptPolicySet
{
    public const KEY_BEHAVIOUR = 'behaviour';
    public const KEY_SHUFFLE_QUESTIONS = 'shuffle_questions';
    public const KEY_SHUFFLE_OPTIONS = 'shuffle_options';
    public const KEY_ONE_PER_PAGE = 'one_per_page';
    public const KEY_NEGATIVE_MARKING = 'negative_marking';
    public const KEY_SHOW_FEEDBACK = 'show_feedback';
    public const KEY_SUBSTITUTED_FROM = 'substituted_from';

    public const KEY_NAVIGATION_MODE = 'navigation_mode';
    public const KEY_SUBMISSION_MODE = 'submission_mode';
    public const KEY_TIME_LIMIT_SECONDS = 'time_limit_seconds';
    public const KEY_CLOSES_AT = 'closes_at';
    public const KEY_EXPIRY_POLICY = 'expiry_policy';
    public const KEY_GRACE_SECONDS = 'grace_seconds';
    public const KEY_AUTO_SUBMIT = 'auto_submit';
    public const KEY_ATTEMPTS_COUNTED = 'attempts_counted';
    public const KEY_ATTEMPT_AGGREGATION = 'attempt_aggregation';
    public const KEY_ATTEMPT_AGGREGATION_PARAMS = 'attempt_aggregation_params';
    public const KEY_NEGATIVE_MARKING_POLICY = 'negative_marking_policy';
    public const KEY_NEGATIVE_MARKING_FLOOR = 'negative_marking_floor';
    public const KEY_NEGATIVE_MARKING_SUBSTITUTED_FROM = 'negative_marking_substituted_from';
    public const KEY_ALLOW_QUIZ_NEGATIVE = 'allow_quiz_negative';
    public const KEY_TRY_PENALTY = 'try_penalty';
    public const KEY_ITEM_SESSION_CONTROL = 'item_session_control';
    public const KEY_REVIEW_VISIBILITY = 'review_visibility';

    /**
     * Which composition produced the served set, and which one the site
     * could not resolve when it fell back. Without it, a site that installs
     * slot-based composition halfway through a cohort has two populations of
     * attempts and nothing to tell them apart with.
     */
    public const KEY_COMPOSITION = 'composition';
    public const KEY_COMPOSITION_SUBSTITUTED_FROM = 'composition_substituted_from';

    /**
     * The confidence scale the behaviour asks for beside each answer, when
     * it asks for one. Null for every free behaviour.
     */
    public const KEY_CERTAINTY = 'certainty';

    /**
     * The learner's declared needs, frozen with the rest of the policy.
     *
     * It belongs here rather than on a column of its own for the same reason
     * the rest of the snapshot does: a profile changed mid-attempt must not
     * reshuffle the options of a question the learner is already looking at,
     * and a regrade has to see what was actually in force.
     */
    public const KEY_ACCESSIBILITY_PROFILE = 'accessibility_profile';

    /** @var array<string, mixed> */
    public readonly array $settings;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(public readonly string $behaviour, array $settings = [])
    {
        $this->settings = $settings + [
            self::KEY_SHUFFLE_QUESTIONS => false,
            self::KEY_SHUFFLE_OPTIONS => false,
            self::KEY_ONE_PER_PAGE => true,
            self::KEY_NEGATIVE_MARKING => false,
            self::KEY_SHOW_FEEDBACK => false,
        ];
    }

    /**
     * The whole quiz-level set, as the free behaviours freeze it. A
     * behaviour that owns one of these members overrides it and leaves the
     * rest alone, which is why this is a named constructor rather than
     * twenty lines copied into every provider.
     *
     * @param array<string, mixed> $overrides
     */
    public static function fromQuizPolicy(string $behaviour, QuizPolicy $policy, array $overrides = []): self
    {
        return new self($behaviour, $overrides + [
            self::KEY_SHUFFLE_QUESTIONS => $policy->shuffleQuestions,
            self::KEY_SHUFFLE_OPTIONS => $policy->shuffleOptions,
            self::KEY_ONE_PER_PAGE => $policy->onePerPage,
            self::KEY_NAVIGATION_MODE => $policy->navigationMode,
            self::KEY_SUBMISSION_MODE => $policy->submissionMode,
            self::KEY_CLOSES_AT => $policy->closesAt?->format('Y-m-d H:i:s'),
            self::KEY_EXPIRY_POLICY => $policy->expiryPolicy,
            self::KEY_GRACE_SECONDS => $policy->graceSeconds,
            self::KEY_AUTO_SUBMIT => $policy->autoSubmit,
            self::KEY_ATTEMPTS_COUNTED => $policy->attemptsCounted,
            self::KEY_ATTEMPT_AGGREGATION => $policy->attemptAggregation,
            self::KEY_ATTEMPT_AGGREGATION_PARAMS => $policy->attemptAggregationParams,
            // Negative marking is enabled by naming a rule, so the boolean
            // every reader already asks about is derived from the key rather
            // than stored beside it where the two could disagree.
            self::KEY_NEGATIVE_MARKING => $policy->negativeMarkingPolicy !== null,
            self::KEY_NEGATIVE_MARKING_POLICY => $policy->negativeMarkingPolicy,
            self::KEY_ALLOW_QUIZ_NEGATIVE => $policy->allowQuizNegative,
            self::KEY_TRY_PENALTY => $policy->tryPenalty,
            self::KEY_ITEM_SESSION_CONTROL => $policy->itemSessionControl->toArray(),
            self::KEY_SHOW_FEEDBACK => $policy->itemSessionControl->showFeedback,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The shape version of the frozen snapshot.
     *
     * **Permanent evidence needs a version.** attempts.policy_snapshot is
     * read by review, appeal, regrade and item analysis for the life of the
     * row, and it was a flat untyped bag with no version term at all: a
     * later release that changed what try_penalty or grace_seconds meant, or
     * narrowed attempts_counted, would have reinterpreted every historical
     * snapshot under the new semantics on the next regrade, with no way to
     * detect it and no way to write an upgrader. question_definitions
     * carries definition_version and attempt_grades carries version for
     * exactly this reason.
     */
    public const SCHEMA_VERSION = 1;

    public const KEY_SCHEMA_VERSION = 'schema_version';

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::KEY_BEHAVIOUR => $this->behaviour,
            self::KEY_SCHEMA_VERSION => self::SCHEMA_VERSION,
        ] + $this->settings;
    }

    public function schemaVersion(): int
    {
        $version = $this->settings[self::KEY_SCHEMA_VERSION] ?? null;

        return \is_int($version) ? $version : 1;
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    public static function fromArray(?array $stored): self
    {
        if ($stored === null) {
            return self::deferredFeedback();
        }

        $behaviour = $stored[self::KEY_BEHAVIOUR] ?? null;
        unset($stored[self::KEY_BEHAVIOUR]);

        // A snapshot with no version is version one, which is every row
        // written before the key existed and is the truthful reading of
        // them. A version this build does not read is an explicit refusal
        // rather than a silent default: reinterpreting a newer shape under
        // older semantics is exactly what the version exists to prevent, and
        // it is the same gate DefinitionVersionAwareInterface keeps for a
        // definition.
        $version = $stored[self::KEY_SCHEMA_VERSION] ?? self::SCHEMA_VERSION;

        if (\is_int($version) && $version > self::SCHEMA_VERSION) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new UnreadablePolicySnapshotException($version, self::SCHEMA_VERSION);
        }

        return new self(\is_string($behaviour) ? $behaviour : 'deferred_feedback', $stored);
    }

    /**
     * The snapshot of an attempt that predates the column: the free build's
     * default behaviour, with every switch off.
     */
    public static function deferredFeedback(): self
    {
        return new self('deferred_feedback');
    }

    public function shufflesOptions(): bool
    {
        return ($this->settings[self::KEY_SHUFFLE_OPTIONS] ?? false) === true;
    }

    public function shufflesQuestions(): bool
    {
        return ($this->settings[self::KEY_SHUFFLE_QUESTIONS] ?? false) === true;
    }

    public function allowsNegativeMarking(): bool
    {
        return ($this->settings[self::KEY_NEGATIVE_MARKING] ?? false) === true;
    }

    public function negativeMarkingPolicy(): ?string
    {
        $key = $this->settings[self::KEY_NEGATIVE_MARKING_POLICY] ?? null;

        return \is_string($key) && $key !== '' ? $key : null;
    }

    public function allowsQuizNegativeTotal(): bool
    {
        return ($this->settings[self::KEY_ALLOW_QUIZ_NEGATIVE] ?? false) === true;
    }

    /**
     * The floor the resolved negative marking rule imposes on every item, or
     * null to leave each scoring policy's own floor in force.
     */
    public function negativeMarkingFloor(): ?float
    {
        $floor = $this->settings[self::KEY_NEGATIVE_MARKING_FLOOR] ?? null;

        return \is_int($floor) || \is_float($floor) ? (float) $floor : null;
    }

    /**
     * The rule this quiz named resolved, and what it imposes.
     */
    public function withNegativeMarkingFloor(?float $floor): self
    {
        if ($floor === null) {
            return $this;
        }

        return new self($this->behaviour, [self::KEY_NEGATIVE_MARKING_FLOOR => $floor] + $this->settings);
    }

    /**
     * The rule this quiz named is not registered on this site, so no
     * penalty applies and the substitution is recorded.
     *
     * This is the safe direction of the miss, and it is the only defensible
     * one: failing the other way would charge a learner under a rule nobody
     * can name, on a quiz whose instructions promised a penalty the site can
     * no longer describe.
     */
    public function withoutNegativeMarking(string $requested): self
    {
        return new self($this->behaviour, [
            self::KEY_NEGATIVE_MARKING => false,
            self::KEY_NEGATIVE_MARKING_POLICY => null,
            self::KEY_NEGATIVE_MARKING_SUBSTITUTED_FROM => $requested,
        ] + $this->settings);
    }

    /**
     * The per-part try penalty, in fraction space, where both operands are
     * dimensionless.
     */
    public function tryPenalty(): float
    {
        $penalty = $this->settings[self::KEY_TRY_PENALTY] ?? 0;

        return \is_int($penalty) || \is_float($penalty) ? max(0.0, (float) $penalty) : 0.0;
    }

    public function navigationMode(): string
    {
        return $this->stringOr(self::KEY_NAVIGATION_MODE, QuizPolicy::NAVIGATION_NONLINEAR);
    }

    public function isLinear(): bool
    {
        return $this->navigationMode() === QuizPolicy::NAVIGATION_LINEAR;
    }

    public function submissionMode(): string
    {
        return $this->stringOr(self::KEY_SUBMISSION_MODE, QuizPolicy::SUBMISSION_INDIVIDUAL);
    }

    public function gradesAtSubmitOnly(): bool
    {
        return $this->submissionMode() === QuizPolicy::SUBMISSION_SIMULTANEOUS;
    }

    public function expiryPolicy(): string
    {
        return $this->stringOr(self::KEY_EXPIRY_POLICY, QuizPolicy::EXPIRY_AUTO_SUBMIT);
    }

    public function graceSeconds(): int
    {
        $grace = $this->settings[self::KEY_GRACE_SECONDS] ?? null;

        return \is_int($grace) ? max(QuizPolicy::MIN_GRACE_SECONDS, $grace) : QuizPolicy::MIN_GRACE_SECONDS;
    }

    public function autoSubmits(): bool
    {
        return ($this->settings[self::KEY_AUTO_SUBMIT] ?? true) === true;
    }

    public function attemptsCounted(): string
    {
        return $this->stringOr(self::KEY_ATTEMPTS_COUNTED, QuizPolicy::COUNTED_ALL);
    }

    public function attemptAggregation(): string
    {
        return $this->stringOr(self::KEY_ATTEMPT_AGGREGATION, 'highest');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function attemptAggregationParams(): ?array
    {
        $params = $this->settings[self::KEY_ATTEMPT_AGGREGATION_PARAMS] ?? null;

        if (!\is_array($params)) {
            return null;
        }

        $object = [];

        /** @var mixed $item */
        foreach ($params as $name => $item) {
            $object[(string) $name] = $item;
        }

        return $object;
    }

    public function itemSessionControl(): ItemSessionControl
    {
        $stored = $this->settings[self::KEY_ITEM_SESSION_CONTROL] ?? null;

        if (!\is_array($stored)) {
            return new ItemSessionControl();
        }

        $control = [];

        /** @var mixed $item */
        foreach ($stored as $name => $item) {
            $control[(string) $name] = $item;
        }

        return ItemSessionControl::fromArray($control);
    }

    /**
     * The confidence scale to ask for beside each answer, or null when the
     * behaviour in force asks for none, which is every free behaviour.
     *
     * @return array<string, mixed>|null
     */
    public function certaintyScale(): ?array
    {
        $scale = $this->settings[self::KEY_CERTAINTY] ?? null;

        if (!\is_array($scale)) {
            return null;
        }

        $object = [];

        /** @var mixed $item */
        foreach ($scale as $name => $item) {
            $object[(string) $name] = $item;
        }

        return $object;
    }

    public function compositionKey(): ?string
    {
        $key = $this->settings[self::KEY_COMPOSITION] ?? null;

        return \is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Records which composition produced the served set, and which one the
     * site could not resolve if it fell back.
     */
    /**
     * The key that says this attempt's deadline was resolved at start, so a
     * null expires_at means "no deadline" rather than "written before the
     * column existed".
     */
    public const KEY_DEADLINE_RESOLVED = 'deadline_resolved';

    public function withResolvedDeadline(): self
    {
        return new self($this->behaviour, [self::KEY_DEADLINE_RESOLVED => true] + $this->settings);
    }

    public function deadlineResolved(): bool
    {
        return ($this->settings[self::KEY_DEADLINE_RESOLVED] ?? false) === true;
    }

    public function withComposition(string $key, ?string $substitutedFrom = null): self
    {
        $settings = [self::KEY_COMPOSITION => $key] + $this->settings;

        if ($substitutedFrom !== null) {
            $settings = [self::KEY_COMPOSITION_SUBSTITUTED_FROM => $substitutedFrom] + $settings;
        }

        return new self($this->behaviour, $settings);
    }

    public function substitutedFromBehaviour(): ?string
    {
        $key = $this->settings[self::KEY_SUBSTITUTED_FROM] ?? null;

        return \is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Records that the behaviour the attempt asked for was not available and
     * which one stood in, so a later reading of the row is not a mystery.
     */
    public function substitutedFrom(string $requested): self
    {
        return new self($this->behaviour, $this->settings + [self::KEY_SUBSTITUTED_FROM => $requested]);
    }

    /**
     * Freezes the learner's declared profile onto the snapshot. A learner
     * with none leaves no key at all, so the majority of rows are unchanged.
     */
    public function withAccessibilityProfile(AccessibilityProfile $profile): self
    {
        $stored = $profile->toArrayOrNull();

        if ($stored === null) {
            return $this;
        }

        return new self($this->behaviour, [self::KEY_ACCESSIBILITY_PROFILE => $stored] + $this->settings);
    }

    public function accessibilityProfile(): AccessibilityProfile
    {
        $stored = $this->settings[self::KEY_ACCESSIBILITY_PROFILE] ?? null;

        /** @var array<string, mixed>|null $stored */
        return AccessibilityProfile::fromArray(\is_array($stored) ? $stored : null);
    }

    /**
     * True when the learner's frozen profile declares a need whose tooling
     * blurs the browser window. Integrity signals are then not recorded at
     * all, because a magnifier, an on-screen keyboard and a read-aloud
     * application all look exactly like a second window to a blur listener.
     */
    public function suppressesIntegrityAutomation(): bool
    {
        return $this->accessibilityProfile()->suppressesIntegrityAutomation();
    }

    private function stringOr(string $key, string $default): string
    {
        $value = $this->settings[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
