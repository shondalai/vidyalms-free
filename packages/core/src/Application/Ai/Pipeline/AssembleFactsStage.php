<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunSubject;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Ai\NamesFactSubjects;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;

/**
 * Stage 3. Resolves exactly the facts the task declared, and nothing else.
 *
 * A task that declares no facts is handed no learner data at all, which is what
 * makes minimisation the default rather than an option. A task that asks at run
 * time for a fact it did not declare cannot: the bag it is handed contains only
 * what it declared, and there is no accessor for anything else.
 *
 * **This stage is also where the run learns whose data it is about.** A fact
 * declaring FactCardinality::Many is what drives the ai_run_subjects write, and
 * without it a run over a hundred submissions would have written one arbitrary
 * learner into on_behalf_of_user_id and answered "no runs" to every other data
 * subject asking their Article 15 question.
 *
 * **A Many fact says whose data it resolved, through NamesFactSubjects.** The
 * convention that survived here as a fallback reads a list of ints or of rows
 * carrying a `user_id`, and the one shipped Many fact answers neither shape,
 * because a response set is keyed by attempt: a learner with two attempts wrote
 * two answers. Under the convention alone a grouping run over a hundred learners
 * wrote no subject row at all and told every one of them "no runs". So the
 * provider declares its subjects and the convention is what an undeclared
 * provider falls back to.
 */
final class AssembleFactsStage implements AiRunStageInterface
{
    public function __construct(
        private readonly AiFactProviderRegistry $facts,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function name(): string
    {
        return 'AssembleFacts';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        $task = $run->task;
        $declared = $task instanceof RequiresFacts ? $task->requiredFacts() : [];
        $context = AiFactContext::create(
            $run->request->principal,
            $this->database,
            $this->clock->now(),
            $run->request->task->subjectType,
            $run->request->task->subjectId,
            $run->request->scopeType,
            $run->request->scopeId,
            $run->request->locale,
            // A fact provider reading an organisation-owned table narrows on
            // this rather than on the scope id beside it, which is how tenancy
            // stays a type rather than a discipline.
            $run->request->orgScope,
        );

        $values = [];
        $subjects = [];

        if ($run->request->onBehalfOfUserId !== null) {
            $subjects[] = AiRunSubject::subject($run->request->onBehalfOfUserId);
        }

        foreach ($declared as $key) {
            if (!$this->facts->has($key)) {
                // The registration-time refusal is the primary defence and lives
                // where a task is registered. This is the backstop for a fact
                // provider whose package was removed after the task was
                // registered, and it fails closed rather than handing the task a
                // silent null.
                $run->refuse(RefusalKind::NotConfigured, 'ai.fact.unsupplied', [
                    'fact' => $key,
                    'task' => $run->request->taskKey,
                ]);

                return;
            }

            $provider = $this->facts->get($key);

            if (!$provider instanceof AiFactProviderInterface) {
                $run->refuse(RefusalKind::NotConfigured, 'ai.fact.unsupplied', ['fact' => $key]);

                return;
            }

            /** @var mixed $value */
            $value = $provider->resolve($context);
            $values[$key] = $value;

            if ($provider->resolvesToUsers() === FactCardinality::Many) {
                $named = $provider instanceof NamesFactSubjects
                    ? $provider->subjectsIn($value, $context)
                    : $this->peopleIn($value);

                foreach ($named as $userId) {
                    if ($userId > 0) {
                        $subjects[] = AiRunSubject::subject($userId);
                    }
                }
            }
        }

        $run->facts = $values;
        $run->subjects = AiRunSubject::distinct($subjects);
        $run->payload = AiTaskPayload::create(
            $run->request->task,
            [],
            $values,
            $run->request->task->options,
        );
    }

    /**
     * The fallback for a Many fact that declares no subjects of its own.
     *
     * @return list<int>
     */
    private function peopleIn(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $people = [];

        foreach ($value as $entry) {
            if (\is_array($entry) && isset($entry['user_id']) && is_numeric($entry['user_id'])) {
                $people[] = (int) $entry['user_id'];
                continue;
            }

            if (\is_int($entry) && $entry > 0) {
                $people[] = $entry;
            }
        }

        return array_values(array_unique($people));
    }
}
