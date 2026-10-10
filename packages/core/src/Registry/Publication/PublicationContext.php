<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Publication;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * Everything the draft to ready transition hands a check.
 *
 * Two facts about what is in here are load bearing. The **resolved policy
 * snapshot** is present because half the design's checks are conditional on
 * it: a negative mapped value is a blocking error on a quiz whose policy
 * does not enable negative marking and entirely legitimate on one that does,
 * and a check that could not see the snapshot would have to pick one answer
 * and be wrong for half the sites. And anything a check needs that costs a
 * query is resolved **before** the checks run, because "pool size below
 * three times the draw count" is a query and a check that ran one per pool
 * would run one query per pool on every save.
 *
 * **The facts a package adds travel in the bag, not in a new member here.**
 * Placement lives in a paid package: its slot list and its resolved pool
 * sizes are shapes the core has no type for, and adding a member for each
 * would make every extension point a core edit, which is the thing this
 * whole structure exists to prevent. A package attaches one object under its
 * own key before the checks run, and its own checks narrow it back with
 * instanceof.
 *
 * Checks never run inside validateDefinition, which covers structural
 * storability only. That is what lets an author save a half-built hotspot
 * and come back to it, and what lets a site replace a check without
 * replacing the type that used to contain it.
 */
final class PublicationContext
{
    /** @var list<Question> */
    public readonly array $questions;

    /** @var array<string, mixed> */
    public readonly array $quizSettings;

    /** @var array<string, object> */
    private array $facts = [];

    /**
     * @param list<Question>       $questions
     * @param array<string, mixed> $quizSettings
     */
    private function __construct(
        public readonly int $quizId,
        array $questions,
        public readonly AttemptPolicySet $policy,
        array $quizSettings,
    ) {
        $this->questions = array_values($questions);
        $this->quizSettings = $quizSettings;
    }

    /**
     * @param list<Question>       $questions
     * @param array<string, mixed> $quizSettings
     */
    public static function create(
        int $quizId,
        array $questions = [],
        ?AttemptPolicySet $policy = null,
        array $quizSettings = [],
    ): self {
        return new self(
            $quizId,
            $questions,
            $policy ?? AttemptPolicySet::deferredFeedback(),
            $quizSettings,
        );
    }

    /**
     * Attaches a package's own facts under its own key, and answers the same
     * object so the caller can chain.
     *
     * Mutating rather than copying, because the context is assembled once
     * immediately before runAll() and a copy-on-write here would mean the
     * assembler had to thread the result of every attach through the next.
     */
    public function attach(string $key, object $facts): self
    {
        $this->facts[$key] = $facts;

        return $this;
    }

    public function facts(string $key): ?object
    {
        return $this->facts[$key] ?? null;
    }

    public function setting(string $name, mixed $default = null): mixed
    {
        return $this->quizSettings[$name] ?? $default;
    }

    public function intSetting(string $name, int $default = 0): int
    {
        $value = $this->quizSettings[$name] ?? null;

        return \is_int($value) || (\is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }

    public function allowsNegativeMarking(): bool
    {
        return $this->policy->allowsNegativeMarking();
    }
}
