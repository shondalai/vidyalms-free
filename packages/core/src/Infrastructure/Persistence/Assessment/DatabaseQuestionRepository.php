<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoringIntent;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionAwareShape;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ManualGradingDecidingInterface;
use Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader\DefinitionUpgraderRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionUpgrade;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyReferenceDeclaringInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The one place a question definition is encoded and decoded, and therefore
 * the one place its stored version is written and its upgrade is run. The
 * registry is here for that alone: nothing above this class ever sees a
 * payload in anything but the current shape.
 *
 * It is also the one place the derived mirrors are written. definition
 * version, response shape, scoring policy, scoring parameters and external
 * scored are all facts the provider owns and other things query, so they are
 * computed here on the way to the row and never accepted as independent
 * input. A mirror an application service could set separately is a mirror
 * that will eventually disagree with what it mirrors.
 */
final class DatabaseQuestionRepository implements QuestionRepository
{
    /**
     * The policy registry is an optional last parameter, which is how every
     * dependency added to a shipped class arrives here: a required one
     * white-screens an installed Pro build the moment the two packages are
     * a release apart. Without it the params mirror falls back to whatever
     * the definition literally carries, which is what it did before.
     */
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly QuestionTypeRegistry $questionTypes,
        private readonly ?ScoringPolicyRegistry $policies = null,
        private readonly ?DefinitionUpgraderRegistry $definitionUpgraders = null,
    ) {
    }

    public function add(Question $question): Question
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $derived = $this->derive($question);

        $id = $this->database->insert('questions', [
            ...$this->toRow($derived),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $derived->withId($id);
    }

    public function byId(int $id): ?Question
    {
        $row = $this->database->selectOne(Query::from('questions')->where('id', '=', $id));

        return $row === null ? null : $this->fromRow($row);
    }

    public function save(Question $question): void
    {
        if ($question->id === null) {
            throw new \LogicException('Cannot save a question that has not been added.');
        }

        $this->database->update('questions', [
            ...$this->toRow($this->derive($question)),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $question->id]);
    }

    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('questions')->whereIn('id', $ids)->orderBy('position')->orderBy('id'),
        );

        $questions = [];

        foreach ($rows as $row) {
            $question = $this->fromRow($row);

            if ($question->id !== null) {
                $questions[$question->id] = $question;
            }
        }

        return $questions;
    }

    public function forQuiz(int $quizId): array
    {
        $rows = $this->database->selectAll(
            Query::from('questions')
                ->where('quiz_id', '=', $quizId)
                ->orderBy('position')
                ->orderBy('id'),
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function publishedForQuiz(int $quizId): array
    {
        $rows = $this->database->selectAll(
            Query::from('questions')
                ->where('quiz_id', '=', $quizId)
                ->where('state', '=', Question::STATE_PUBLISHED)
                ->orderBy('position')
                ->orderBy('id'),
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function nextPosition(int $quizId): int
    {
        $row = $this->database->selectOne(
            Query::from('questions')
                ->select(['position'])
                ->where('quiz_id', '=', $quizId)
                ->orderBy('position', Query::SORT_DESC),
        );

        return $row === null ? 1 : (int) $row['position'] + 1;
    }

    public function publishedCounts(array $quizIds): array
    {
        if ($quizIds === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('questions')
                ->select(['quiz_id'])
                ->whereIn('quiz_id', $quizIds)
                ->where('state', '=', Question::STATE_PUBLISHED),
        );

        $counts = [];

        foreach ($rows as $row) {
            $quizId = (int) $row['quiz_id'];
            $counts[$quizId] = ($counts[$quizId] ?? 0) + 1;
        }

        return $counts;
    }

    public function updatePositions(array $positions): void
    {
        if ($positions === []) {
            return;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->transactional(function () use ($positions, $now): void {
            foreach ($positions as $questionId => $position) {
                $this->database->update(
                    'questions',
                    ['position' => $position, 'updated_at' => $now],
                    ['id' => $questionId],
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Question $question): array
    {
        return [
            'quiz_id' => $question->quizId,
            'type' => $question->type,
            'position' => $question->position,
            'points' => $question->points,
            'definition' => json_encode(
                StoredShape::wrap($question->definition, $question->definitionVersion),
                JSON_THROW_ON_ERROR,
            ),
            'state' => $question->state,
            'definition_version' => $question->definitionVersion,
            'response_shape' => $question->responseShape,
            'scored' => $question->scored ? 1 : 0,
            'scoring_policy' => $question->scoringPolicy,
            'scoring_params' => $question->scoringParams === []
                ? null
                : json_encode($question->scoringParams, JSON_THROW_ON_ERROR),
            'external_scored' => $question->externalScored,
            'stimulus_id' => $question->stimulusId,
            'rubric_version_id' => isset($question->definition['rubric_version_id'])
                && is_int($question->definition['rubric_version_id']) && $question->definition['rubric_version_id'] > 0
                    ? $question->definition['rubric_version_id'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): Question
    {
        $decoded = json_decode((string) $row['definition'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $stored */
        $stored = \is_array($decoded) ? $decoded : [];
        $type = (string) $row['type'];
        $storedVersion = StoredShape::versionOf($stored);
        $columnVersion = $row['definition_version'] ?? null;

        // The version reported is the version of the BODY in hand, never the
        // column. The column is the batch-upgrade index: it is re-stamped
        // only when a row is written, so a row that is read but never
        // re-saved reports version N while carrying a version N+1 body, and
        // the version gate is then asked about the wrong thing. It let a
        // removed decoration grade a newer definition under an older
        // provider (a scored zero where the design requires notscored), and
        // it routed every row of a provider that legitimately raised its own
        // version to the placeholder until something re-saved it.
        //
        // The envelope travels with the body, so it is what the gate reads.
        // A row written before the envelope existed has neither, and is
        // version 1 by definition.
        $bodyVersion = $storedVersion > 0
            ? $storedVersion
            : max(1, $columnVersion === null ? 1 : (int) $columnVersion);

        [$definition, $version] = $this->upgraded(
            $type,
            StoredShape::bodyOf($stored),
            $storedVersion,
            $bodyVersion,
        );

        return new Question(
            id: (int) $row['id'],
            quizId: (int) $row['quiz_id'],
            type: $type,
            position: (int) $row['position'],
            points: (float) $row['points'],
            definition: $definition,
            state: (string) $row['state'],
            definitionVersion: $version,
            // A row written before the column existed is scored, which is
            // what every question was until an unscored one became
            // authorable.
            scored: ($row['scored'] ?? null) === null || (int) $row['scored'] === 1,
            responseShape: ($row['response_shape'] ?? null) === null ? null : (string) $row['response_shape'],
            scoringPolicy: ($row['scoring_policy'] ?? null) === null ? null : (string) $row['scoring_policy'],
            scoringParams: $this->decodeObject($row['scoring_params'] ?? null),
            externalScored: ($row['external_scored'] ?? null) === null ? null : (string) $row['external_scored'],
            stimulusId: ($row['stimulus_id'] ?? null) === null ? null : (int) $row['stimulus_id'],
        );
    }

    /**
     * Recomputes every derived mirror from the provider that owns the
     * definition. A type with no provider keeps whatever the row already
     * carried, which is what stops removing a package rewriting the columns
     * that describe its questions.
     */
    private function derive(Question $question): Question
    {
        if (!$this->questionTypes->has($question->type)) {
            return $question;
        }

        $provider = $this->questionTypes->get($question->type);

        return $question->withDerived(
            // The minimum across the container and every part it holds, which
            // the design states is the only value that keeps the index safe:
            // a batch upgrade filtering on this column has to find a current
            // composite whose oldest part is stale.
            DefinitionUpgrade::versionOf($this->questionTypes, $question->type, $question->definition),
            $this->responseShapeOf($provider, $question) ?? $question->responseShape,
            $this->policyKeyOf($provider, $question) ?? $question->scoringPolicy,
            $this->policyParamsOf($provider, $question),
            $this->externalScoredOf($provider, $question),
        );
    }

    /**
     * The shape this question actually has, which for one type in the
     * catalogue is authored rather than declared: an association with
     * targets produces directed pairs and one without them produces
     * undirected ones, and the column is what the fallback renderer, the
     * answer validator and the QTI exporter all key on. Recording the type's
     * default for both meant every undirected association claimed a
     * direction it does not have.
     */
    private function responseShapeOf(QuestionTypeInterface $provider, Question $question): ?string
    {
        if ($provider instanceof DefinitionAwareShape) {
            return $provider->responseShapeFor($question->definition)->columnValue();
        }

        return $provider instanceof DefinitionContractInterface
            ? $provider->responseShape()->columnValue()
            : null;
    }

    /**
     * The rule this question actually grades under, asked of the type rather
     * than read out of a field name this layer guessed.
     *
     * Reading definition.policy.key directly mirrored the wrong rule for
     * every type that keeps its own somewhere else, and the composite does
     * exactly that on purpose: its rule is under "aggregation", because each
     * part carries a policy of its own and one word for two rules is how
     * the wrong one gets read. So a Kprime grid graded under
     * subset_threshold while this column recorded composite_weighted, on an
     * indexed reporting column the design requires to agree with what
     * actually grades.
     */
    private function policyKeyOf(QuestionTypeInterface $provider, Question $question): ?string
    {
        if ($provider instanceof PolicyReferenceDeclaringInterface) {
            $key = $provider->policyKeyFor($question->definition);

            return $key === '' ? null : $key;
        }

        $declared = $question->definition['policy']['key'] ?? null;

        if (\is_string($declared) && $declared !== '') {
            return $declared;
        }

        // A definition that names no policy is a legacy row, and the mirror
        // records the pin it actually grades under rather than the current
        // default, which is what keeps the column and the marking agreed.
        if ($provider instanceof ScoringPolicyAwareInterface) {
            return $provider->legacyPolicy() ?? $provider->defaultPolicy();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function policyParamsOf(QuestionTypeInterface $provider, Question $question): array
    {
        if ($provider instanceof PolicyReferenceDeclaringInterface) {
            $policy = $this->policies?->resolve($this->policyKeyOf($provider, $question));

            if ($policy !== null) {
                return $provider->policyParamsFor($question->definition, $policy);
            }
        }

        $declared = $question->definition['policy']['params'] ?? null;

        /** @var array<string, mixed> */
        return \is_array($declared) ? $declared : $question->scoringParams;
    }

    private function externalScoredOf(QuestionTypeInterface $provider, Question $question): string
    {
        if ($provider instanceof ManualGradingDecidingInterface) {
            return $provider->requiresHumanGrader($question->definition, GradingContext::detached())
                ? ScoringIntent::Human->value
                : ScoringIntent::Machine->value;
        }

        return ScoringIntent::ofDefinition($question->definition)->value;
    }

    /**
     * The definition brought up to the shape the resolved provider writes,
     * together with the version of the body that comes back.
     *
     * An unregistered key keeps its rows readable rather than breaking a
     * course, which is the same promise the registry makes about removed
     * extensions: no upgrade, and the stored version stands.
     *
     * @param array<string, mixed> $definition
     *
     * @return array{array<string, mixed>, int}
     */
    private function upgraded(string $type, array $definition, int $storedVersion, int $bodyVersion): array
    {
        if (!$this->questionTypes->has($type)) {
            return [$definition, $bodyVersion];
        }

        // The registered chain, then the same walk recursively through every
        // nested definition a container holds. It used to call the provider's
        // own single-step upgradeDefinition() and never consult the registry
        // at all, so an upgrader a family registered was accepted, recorded
        // in the ledger and never executed: every stored row kept its old
        // version and the version gate then routed all of them to the
        // placeholder at grade time.
        $upgraders = $this->definitionUpgraders ?? new DefinitionUpgraderRegistry();
        [$upgraded, $version] = DefinitionUpgrade::apply(
            $this->questionTypes,
            $upgraders,
            $type,
            $definition,
            $storedVersion,
        );

        return $upgraded === $definition ? [$definition, $bodyVersion] : [$upgraded, $version];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(mixed $value): array
    {
        if (!\is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return \is_array($decoded) ? $decoded : [];
    }
}
