<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerError;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\ErrorKind;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoringIntent;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Provider\Interaction\AbstractInteractionPrimitive;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyParameters;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * The half of a policy-backed question type that is the same for all of them:
 * decode through the primitive, build the key, resolve the named policy, hand
 * the result back. Published API, under Registry rather than under Provider.
 *
 * It lives here because Provider/{ExtensionPoint} is documented as the free
 * build's default providers and not as contract surface, so a paid or
 * third-party type wanting this behaviour had to couple to a free default's
 * internals, which can change without notice, or copy them. The rule this
 * carries decides whether a definition naming no policy grades under the
 * legacy pin or the current default, and that moves marks, so two copies of it
 * drifting is not a style problem. Pro reached the free trait's own private
 * property to install its registry, which is the sharpest form of the same
 * coupling.
 *
 * A trait rather than a base class, for the reason DefaultDefinitionVersion
 * gives: every provider is final, takes its own constructor dependencies and
 * implements one interface, and a base class would put a second thing in the
 * way of all three.
 *
 * How a policy is resolved, stated once because getting it wrong moves marks.
 * A definition that names one under policy.key uses it. A definition that
 * names none is a definition authored before the field existed, and it uses
 * legacyPolicy() so no historic percentage moves; where a type has no legacy
 * behaviour to pin, that is defaultPolicy(). A brand-new question gets
 * defaultPolicy() written into its definition at creation, which is why an
 * edit that names no policy must leave an absent one absent rather than
 * stamping the current default onto a question authored years ago.
 */
trait PolicyBackedScoring
{
    /*
     * The two methods below satisfy PolicyReferenceDeclaringInterface, so a
     * type using this trait tells the persistence layer where its rule lives
     * without saying anything: questions.scoring_policy and
     * questions.scoring_params are derived mirrors, and reading
     * definition.policy.key directly instead is what mirrored the wrong rule
     * for every container type that keeps its own somewhere else.
     */

    /**
     * Where this type keeps its rule. A container type that keeps its own
     * under another name overrides this, and everything that writes a rule
     * into a definition asks rather than assuming.
     */
    public function policyField(): string
    {
        return PolicyResolution::FIELD;
    }

    /**
     * The policy key in force for this definition.
     *
     * @param array<string, mixed> $definition
     */
    public function policyKeyFor(array $definition): string
    {
        return PolicyResolution::keyFor($definition, $this->legacyPolicy(), $this->defaultPolicy());
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function policyParamsFor(array $definition, ScoringPolicyInterface $policy): array
    {
        return PolicyResolution::paramsFor($definition, $policy);
    }

    /**
     * The first line of a type's validateDefinition: refuse anything this
     * type's own published schema refuses.
     *
     * The design says validateDefinition delegates to the schema its own
     * class ships, and without this it did not: every type published
     * additionalProperties false and every one accepted an unknown top-level
     * key, and a value outside a schema-declared enum was stored and then
     * read through a default branch, so the item behaved differently from
     * what the author wrote with no error anywhere.
     *
     * @param array<string, mixed> $definition
     */
    public function validateAgainstSchema(array $definition): void
    {
        if ($this instanceof DefinitionContractInterface) {
            DefinitionSchemaCheck::enforce($this->definitionSchema(), $definition);
        }
    }

    /**
     * Validates the policy reference a definition carries. Called from every
     * type's validateDefinition, so an author cannot save a question naming a
     * policy this site does not have, one that does not bind to this type's
     * response shape, or parameters that belong somewhere else.
     *
     * @param array<string, mixed> $definition
     */
    public function validatePolicyReference(array $definition): void
    {
        PolicyResolution::validateReference(
            $definition,
            $this->scoringPolicies(),
            $this->responseShape(),
            $this->supportedPolicies(),
        );
    }

    /**
     * Every policy this site may offer on this type, computed rather than
     * listed. See PolicyResolution.
     *
     * @return list<string>
     */
    public function offerablePolicies(?ScoringPolicyRegistry $policies = null): array
    {
        return PolicyResolution::offerable(
            $policies ?? $this->scoringPolicies(),
            $this->responseShape(),
            $this->supportedPolicies(),
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function scoreThrough(
        array $definition,
        mixed $answer,
        ScoringKey $key,
        ?GradingContext $context = null,
    ): GradeResult {
        $shape = $this->responseShape();
        $policies = $context === null
            ? $this->scoringPolicies()
            : ($context->policies ?? $this->scoringPolicies());
        // Slot, then question, then definition. questions.scoring_policy is
        // a derived mirror of definition.policy.key written by the same code
        // path, so the last two are one lookup; the placement's override is
        // the one that has to arrive from outside, and it arrives through
        // the context so no quiz-level decision ever reaches a type as a
        // definition field.
        $override = $context?->policyOverride;
        $policyKey = $override !== null && $override !== ''
            ? $override
            : $this->policyKeyFor($definition);
        $policy = $policies->resolve($policyKey);
        $params = $override !== null && $override !== ''
            ? $context->policyOverrideParams + $policy->defaultParams()
            : $this->policyParamsFor($definition, $policy);
        $locale = $context === null ? 'en-GB' : $context->locale;

        $decoded = $this->primitive()->decodeResponse(
            $answer,
            $shape,
            DecodeContext::create($shape, $locale),
        );

        // An item the author declared human-scored never reaches a formula,
        // whatever the response looks like. That is the split QTI keeps
        // between authored intent, runtime state and score status, and it is
        // what stops an essay nobody opened becoming an automatic zero.
        if ($this->declaresHumanGrading($definition, $context)) {
            return GradeResult::manual($this->policyFeedback($definition))
                ->withAnsweredStatus($this->answeredStatusOf($definition, $decoded, $locale))
                ->withPolicy($policyKey, $params);
        }

        // This also runs after named regions have been resolved to points.
        // Covering the image with marks must never earn credit for one hit.
        if ($this->validityOf($definition, $answer, DecodeContext::create($shape, $locale))->exceedsCardinality()) {
            return GradeResult::incorrect($this->policyFeedback($definition))
                ->withAnsweredStatus(AnsweredStatus::Attempted)
                ->withPolicy($policyKey, $params);
        }

        $raw = $policy->score($decoded, $key, $params, ($context ?? GradingContext::detached())->toScoringContext());

        return new GradeResult(
            $raw->fraction,
            $this->policyFeedback($definition),
            rawScore: $raw->raw,
            minScore: $raw->min,
            maxScore: $raw->max,
            answeredStatus: $this->answeredStatusOf($definition, $decoded, $locale),
            scoreStatus: $raw->status,
            sessionStatus: $raw->sessionStatus,
            floorFraction: PolicyParameters::floor($params),
            feedbackIds: $raw->feedbackIds,
            policyKey: $policyKey,
            scoringParams: $raw->params,
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function validityOf(array $definition, mixed $answer, DecodeContext $context): AnswerValidity
    {
        $primitive = $this->primitive();
        $shape = $this->shapeFor($definition);

        // The one place a response is checked against the shape the question
        // declares, before any interaction is asked what it means.
        //
        // Decoding is deliberately lenient, because it has to read both the
        // Appendix C envelope and the bare shapes the free six keep, and
        // leniency with no gate in front of it meant every type read every
        // other type's answer: a single choice item was recorded as answered
        // on a list of directed pairs, on a file record and on a boolean, and
        // an item nobody had answered came back answered because a neighbour
        // had been. QTI keeps an invalid response apart from a wrong one, and
        // an answer to a different question is the plainest invalid response
        // there is, so it is attempted and never answered.
        $readable = $primitive instanceof AbstractInteractionPrimitive
            ? $primitive->acceptsWire($answer, $shape)
            : $shape->accepts($answer);

        if (!$readable) {
            return AnswerValidity::invalid([AnswerError::of(
                $primitive->key(),
                ErrorKind::InvalidEnvelope,
                // The catalogue's own English, because a detached grading
                // probe has no host translator and an empty message would
                // reach the error summary.
                NeutralStrings::english(NeutralStrings::ERROR_INVALID_ENVELOPE),
            )]);
        }

        $decoded = $primitive->decodeResponse($answer, $shape, $context);

        return $primitive->validateAnswer($primitive->projectConstraints($definition), $decoded, $context);
    }

    /**
     * The shape this question has, which for a type whose shape is authored
     * is a property of the definition rather than of the type.
     *
     * @param array<string, mixed> $definition
     */
    private function shapeFor(array $definition): ResponseShape
    {
        return $this instanceof DefinitionAwareShape
            ? $this->responseShapeFor($definition)
            : $this->responseShape();
    }


    /**
     * The overall summary a grade row carries. Overridable, because a type
     * whose feedback is richer than one string says so itself.
     *
     * @param array<string, mixed> $definition
     */
    protected function policyFeedback(array $definition): ?string
    {
        $feedback = $definition['feedback'] ?? null;

        return \is_string($feedback) && $feedback !== '' ? $feedback : null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function answeredStatusOf(
        array $definition,
        ResponseValue $decoded,
        string $locale,
    ): AnsweredStatus {
        $primitive = $this->primitive();
        $validity = $primitive->validateAnswer(
            $primitive->projectConstraints($definition),
            $decoded,
            DecodeContext::create($this->responseShape(), $locale),
        );

        return $validity->answeredStatus();
    }

    /**
     * Whether this item is marked by a human, asked without looking at the
     * learner's response.
     *
     * A type that declares ManualGradingDecidingInterface owns the answer,
     * because it knows what an empty key list means for its own shape; every
     * other type falls back to the authored scoring intent.
     *
     * @param array<string, mixed> $definition
     */
    private function declaresHumanGrading(array $definition, ?GradingContext $context): bool
    {
        if ($this instanceof ManualGradingDecidingInterface) {
            return $this->requiresHumanGrader($definition, $context ?? GradingContext::detached());
        }

        return ScoringIntent::ofDefinition($definition)->isHuman();
    }

    /**
     * The registry this type resolves its policy through.
     *
     * Abstract rather than a property, because where the registry comes from
     * is the implementor's business: the free defaults fall back to the free
     * ten, and a package that ships its own policies must not, or its own
     * policy would be unresolvable in a detached probe.
     */
    abstract protected function scoringPolicies(): ScoringPolicyRegistry;

    /**
     * @return list<string>
     */
    abstract public function supportedPolicies(): array;

    abstract public function defaultPolicy(): string;

    /**
     * Narrower than the interface, which allows null for a type with no
     * legacy behaviour at all. Every type using this has one, so it asks for
     * a string and reads an empty one as "none".
     */
    abstract public function legacyPolicy(): string;

    abstract public function responseShape(): ResponseShape;

    abstract protected function primitive(): InteractionPrimitiveInterface;
}
