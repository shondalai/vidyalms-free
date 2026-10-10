<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\ItemSessionControl;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodInterface;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyContext;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\NegativeMarking\NegativeMarkingRegistry;
use Shondalai\VidyaLMS\Core\Registry\NegativeMarking\NegativeMarkingRuleInterface;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;
use Shondalai\VidyaLMS\Core\Registry\QuizSetting\QuizSettingContext;
use Shondalai\VidyaLMS\Core\Registry\QuizSetting\QuizSettingGroupRegistry;

/**
 * The quiz policy settings this site can actually offer.
 *
 * The settings form is registry driven and never a hardcoded field list.
 * Every field whose value is a provider key reports its options from the
 * registry that owns them, with the parameters that option takes beside it,
 * so a new behaviour, a new aggregation method or a new negative marking
 * rule appears in the workbench with no client change and no field added
 * anywhere.
 *
 * That is what keeps the free build byte-identical with and without the paid
 * packages. Nothing is gated, hidden or flagged: a free site offers the free
 * registries' contents and a site with more offers more, from the same route.
 *
 * A stored value naming a provider that is no longer registered is a normal
 * path, not an error. The field reports it in "current" with "available"
 * false and a reason naming the missing package where the provider ledger
 * knows it, so the author sees what the quiz was set to and what it is
 * running under instead of watching the setting silently revert.
 */
final class GetQuizSettingsSchema
{
    private const TYPE_BOOLEAN = 'boolean';
    private const TYPE_INTEGER = 'integer';
    private const TYPE_NUMBER = 'number';
    private const TYPE_DATETIME = 'datetime';
    private const TYPE_DURATION = 'duration';
    private const TYPE_ENUM = 'enum';
    private const TYPE_OBJECT = 'object';

    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly CourseAccess $access,
        private readonly AttemptPolicyRegistry $behaviours,
        private readonly AggregationMethodRegistry $aggregations,
        private readonly NegativeMarkingRegistry $negativeMarking,
        private readonly TranslatorPort $translator,
        private readonly ?ProviderPackages $packages = null,
        private readonly ?QuizSettingGroupRegistry $settingGroups = null,
    ) {
    }

    /**
     * The group keys the core itself owns.
     *
     * A contributed group naming one of these is dropped rather than merged
     * or appended twice: the form keys its tabs on the group key, and two
     * groups sharing one key make an author's edit land on whichever tab
     * rendered last.
     */
    private const CORE_GROUPS = ['behaviour', 'presentation', 'availability', 'grading', 'item_session'];

    /**
     * @return array{quiz_id: int, groups: list<array<string, mixed>>}
     */
    public function execute(Principal $principal, int $quizId): array
    {
        $this->access->requireSignedIn($principal);

        $quiz = $this->quizzes->byId($quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $quizId);

        $this->access->require($principal, $quiz->courseId, Capability::TEACH);

        $policy = $quiz->policy;

        return [
            'quiz_id' => $quizId,
            'groups' => [
                $this->group('behaviour', [$this->behaviourField($quiz, $policy)]),
                $this->group('presentation', $this->presentationFields($policy)),
                $this->group('availability', $this->availabilityFields($policy)),
                $this->group('grading', $this->gradingFields($policy)),
                $this->group('item_session', [$this->itemSessionField($policy->itemSessionControl)]),
                ...$this->contributedGroups($quizId, $quiz->courseId, $policy),
            ],
        ];
    }

    /**
     * The groups packages other than the core contribute, after the core's
     * own and in registration order.
     *
     * After rather than interleaved, because a site's own ordering is a
     * presentation decision the form owns and a package that could insert
     * itself between two core groups could also hide one.
     *
     * @return list<array<string, mixed>>
     */
    private function contributedGroups(int $quizId, int $courseId, QuizPolicy $policy): array
    {
        if ($this->settingGroups === null) {
            return [];
        }

        $context = QuizSettingContext::create($quizId, $courseId, $policy);
        $groups = [];

        foreach ($this->settingGroups->all() as $provider) {
            $group = $provider->describe($context);

            if ($group === null || $group['fields'] === []) {
                continue;
            }

            if (\in_array($group['key'], self::CORE_GROUPS, true)) {
                continue;
            }

            $groups[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'description' => $group['description'] ?? null,
                'fields' => $group['fields'],
            ];
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return array<string, mixed>
     */
    private function group(string $key, array $fields): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->translate('vidyalms.quiz_settings.group.' . $key),
            'description' => $this->optional('vidyalms.quiz_settings.group.' . $key . '.description'),
            'fields' => $fields,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function behaviourField(Quiz $quiz, QuizPolicy $policy): array
    {
        $options = [];

        foreach ($this->behaviours->all() as $behaviour) {
            $options[] = $this->option($behaviour->key(), $behaviour->label(), null, $this->behaviourGroup($behaviour));
        }

        $stored = $policy->behaviour;
        $available = $stored === null || $this->behaviours->has($stored);

        return $this->field(
            'behaviour',
            self::TYPE_ENUM,
            AttemptPolicyRegistry::DEFERRED_FEEDBACK,
            $stored ?? $quiz->behaviourKey(),
            options: $options,
            available: $available,
            unavailableReason: $available ? null : $this->missing('attempt_policy', $stored ?? ''),
        );
    }

    private function behaviourGroup(AttemptPolicyInterface $behaviour): string
    {
        return $behaviour->revealsOutcomeDuringAttempt() ? 'during_attempt' : 'at_the_end';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentationFields(QuizPolicy $policy): array
    {
        return [
            $this->field('shuffle_questions', self::TYPE_BOOLEAN, false, $policy->shuffleQuestions),
            $this->field('shuffle_options', self::TYPE_BOOLEAN, false, $policy->shuffleOptions),
            $this->field('one_per_page', self::TYPE_BOOLEAN, true, $policy->onePerPage),
            $this->field(
                'navigation_mode',
                self::TYPE_ENUM,
                QuizPolicy::NAVIGATION_NONLINEAR,
                $policy->navigationMode,
                options: $this->literalOptions(QuizPolicy::navigationModes()),
            ),
            $this->field(
                'submission_mode',
                self::TYPE_ENUM,
                QuizPolicy::SUBMISSION_INDIVIDUAL,
                $policy->submissionMode,
                options: $this->literalOptions(QuizPolicy::submissionModes()),
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availabilityFields(QuizPolicy $policy): array
    {
        return [
            $this->field('opens_at', self::TYPE_DATETIME, null, $policy->opensAt?->format('Y-m-d H:i:s')),
            $this->field('closes_at', self::TYPE_DATETIME, null, $policy->closesAt?->format('Y-m-d H:i:s')),
            $this->field(
                'expiry_policy',
                self::TYPE_ENUM,
                QuizPolicy::EXPIRY_AUTO_SUBMIT,
                $policy->expiryPolicy,
                options: $this->literalOptions(QuizPolicy::expiryPolicies()),
            ),
            $this->field(
                'grace_seconds',
                self::TYPE_DURATION,
                QuizPolicy::MIN_GRACE_SECONDS,
                $policy->graceSeconds,
                minimum: QuizPolicy::MIN_GRACE_SECONDS,
            ),
            $this->field('auto_submit', self::TYPE_BOOLEAN, true, $policy->autoSubmit),
            $this->field(
                'cooldown_after_first_seconds',
                self::TYPE_DURATION,
                null,
                $policy->cooldownAfterFirstSeconds,
                minimum: 0,
            ),
            $this->field(
                'cooldown_after_later_seconds',
                self::TYPE_DURATION,
                null,
                $policy->cooldownAfterLaterSeconds,
                minimum: 0,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gradingFields(QuizPolicy $policy): array
    {
        $aggregations = [];

        foreach ($this->aggregations->all() as $method) {
            $aggregations[] = $this->option(
                $method->key(),
                $method->label(),
                $this->paramsSchemaOf($method),
            );
        }

        $rules = [$this->option('', $this->translator->translate('vidyalms.quiz_settings.option.none'))];

        foreach ($this->negativeMarking->all() as $rule) {
            $rules[] = $this->option($rule->key(), $rule->label(), $this->negativeParamsSchemaOf($rule));
        }

        $storedRule = $policy->negativeMarkingPolicy;
        $ruleAvailable = $storedRule === null || $this->negativeMarking->has($storedRule);
        $storedAggregation = $policy->attemptAggregation;
        $aggregationAvailable = $this->aggregations->has($storedAggregation);

        return [
            $this->field(
                'attempts_counted',
                self::TYPE_ENUM,
                QuizPolicy::COUNTED_ALL,
                $policy->attemptsCounted,
                options: $this->literalOptions(QuizPolicy::attemptsCountedValues()),
            ),
            $this->field(
                'attempt_aggregation',
                self::TYPE_ENUM,
                AggregationMethodRegistry::HIGHEST,
                $storedAggregation,
                options: $aggregations,
                paramsKey: 'attempt_aggregation_params',
                available: $aggregationAvailable,
                unavailableReason: $aggregationAvailable
                    ? null
                    : $this->missing('aggregation_method', $storedAggregation),
            ),
            $this->field(
                'negative_marking_policy',
                self::TYPE_ENUM,
                null,
                $storedRule,
                options: $rules,
                available: $ruleAvailable,
                unavailableReason: $ruleAvailable
                    ? null
                    : $this->missing('negative_marking', (string) $storedRule),
            ),
            $this->field('allow_quiz_negative', self::TYPE_BOOLEAN, false, $policy->allowQuizNegative),
            $this->tryPenaltyField($policy),
        ];
    }

    /**
     * The try penalty, reported unavailable when the behaviour in force
     * ignores it.
     *
     * The field, its contract member and its control all existed on every
     * free site and did nothing: deferred_feedback is the free build's only
     * behaviour and it pins the penalty to zero, while the write path
     * happily accepted any fraction between 0 and 1. An author was left
     * setting a number with no effect and nothing telling them why. The
     * schema already has the vocabulary for "stored, but this site cannot
     * honour it"; this is the same answer for a setting the resolved
     * behaviour overrides rather than one no package provides.
     *
     * **An unresolvable behaviour is a registry miss and reports as one.**
     * It used to read the same as no behaviour at all, so a quiz stored on
     * interactive_multi_try with the paid package removed reported its
     * penalty as available with no reason: the author saw a live control for
     * a number nothing on the site could apply, and no word of the missing
     * package. The behaviour field beside it already named the miss, so the
     * form contradicted itself in two adjacent rows. The stored value stays
     * visible and read-only with the reason the ledger knows, exactly as
     * every other provider-keyed field does.
     *
     * @return array<string, mixed>
     */
    private function tryPenaltyField(QuizPolicy $policy): array
    {
        $stored = $policy->behaviour;

        if ($stored !== null && $stored !== '' && !$this->behaviours->has($stored)) {
            return $this->penaltyField($policy, $this->missing('attempt_policy', $stored));
        }

        // No stored behaviour runs the free default, and the default is the
        // one that pins the penalty to zero, so the effective key is what
        // the answer has to be computed from rather than the stored one.
        $key = $stored === null || $stored === '' ? AttemptPolicyRegistry::DEFERRED_FEEDBACK : $stored;
        $behaviour = $this->behaviours->has($key) ? $this->behaviours->get($key) : null;
        $honoured = $policy->tryPenalty <= 0.0 || $behaviour === null || $behaviour->resolve(
            [],
            AttemptPolicyContext::create(0, 1, Seed::of('schema'), new \DateTimeImmutable('@0'), null, $policy),
        )->tryPenalty() > 0.0;

        return $this->penaltyField(
            $policy,
            $honoured
                ? null
                : $this->translator->translate('vidyalms.quiz_settings.try_penalty_ignored', [
                    'behaviour' => $key,
                ]),
        );
    }

    /**
     * The stored penalty, read-only when a reason is given.
     *
     * @return array<string, mixed>
     */
    private function penaltyField(QuizPolicy $policy, ?string $unavailableReason): array
    {
        return $this->field(
            'try_penalty',
            self::TYPE_NUMBER,
            0,
            $policy->tryPenalty,
            minimum: 0,
            maximum: 1,
            available: $unavailableReason === null,
            unavailableReason: $unavailableReason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function itemSessionField(ItemSessionControl $control): array
    {
        $defaults = new ItemSessionControl();

        return $this->field(
            'item_session_control',
            self::TYPE_OBJECT,
            $defaults->toArray(),
            $control->toArray(),
            fields: [
                $this->field('item_max_attempts', self::TYPE_INTEGER, $defaults->maxAttempts, $control->maxAttempts, minimum: 0),
                $this->field('item_show_feedback', self::TYPE_BOOLEAN, $defaults->showFeedback, $control->showFeedback),
                $this->field('item_allow_review', self::TYPE_BOOLEAN, $defaults->allowReview, $control->allowReview),
                $this->field('item_show_solution', self::TYPE_BOOLEAN, $defaults->showSolution, $control->showSolution),
                $this->field('item_allow_comment', self::TYPE_BOOLEAN, $defaults->allowComment, $control->allowComment),
                $this->field('item_allow_skipping', self::TYPE_BOOLEAN, $defaults->allowSkipping, $control->allowSkipping),
                $this->field(
                    'item_validate_responses',
                    self::TYPE_BOOLEAN,
                    $defaults->validateResponses,
                    $control->validateResponses,
                ),
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paramsSchemaOf(AggregationMethodInterface $method): ?array
    {
        return $method->paramsSchema();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function negativeParamsSchemaOf(NegativeMarkingRuleInterface $rule): ?array
    {
        return $rule->paramsSchema();
    }

    /**
     * @param list<string> $values
     *
     * @return list<array<string, mixed>>
     */
    private function literalOptions(array $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[] = $this->option(
                $value,
                $this->translator->translate('vidyalms.quiz_settings.option.' . $value),
            );
        }

        return $options;
    }

    /**
     * @param array<string, mixed>|null $paramsSchema
     *
     * @return array<string, mixed>
     */
    private function option(string $key, string $label, ?array $paramsSchema = null, ?string $group = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'description' => null,
            'params_schema' => $paramsSchema,
        ];
    }

    /**
     * @param list<array<string, mixed>> $options
     * @param list<array<string, mixed>> $fields
     *
     * @return array<string, mixed>
     */
    private function field(
        string $key,
        string $type,
        mixed $default,
        mixed $current,
        array $options = [],
        array $fields = [],
        ?float $minimum = null,
        ?float $maximum = null,
        ?string $paramsKey = null,
        bool $available = true,
        ?string $unavailableReason = null,
    ): array {
        return [
            'key' => $key,
            'label' => $this->translator->translate('vidyalms.quiz_settings.field.' . $key),
            'description' => $this->optional('vidyalms.quiz_settings.field.' . $key . '.description'),
            'type' => $type,
            'default' => $default,
            'current' => $current,
            'minimum' => $minimum,
            'maximum' => $maximum,
            'options' => $options,
            'fields' => $fields,
            'params_key' => $paramsKey,
            'available' => $available,
            'unavailable_reason' => $unavailableReason,
        ];
    }

    /**
     * A description the catalogue may not carry. A translator that answers
     * with the key itself means there is nothing to say, and printing the
     * key at an author would be worse than printing nothing.
     */
    private function optional(string $key): ?string
    {
        $translated = $this->translator->translate($key);

        return $translated === '' || $translated === $key ? null : $translated;
    }

    /**
     * Named per extension point rather than per form, because the ledger
     * answers "which package registered this key" and the answer depends on
     * which registry the key belongs to.
     */
    private function missing(string $extensionPoint, string $key): string
    {
        $package = $this->packages?->packageOf($extensionPoint, $key);

        if ($package !== null) {
            return $this->translator->translate('vidyalms.quiz_settings.unavailable_package', [
                'key' => $key,
                'package' => $package,
            ]);
        }

        return $this->translator->translate('vidyalms.quiz_settings.unavailable', ['key' => $key]);
    }
}
