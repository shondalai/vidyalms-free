<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Domain\Automation\SuppressionReason;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresSentence;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresReversal;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Automation\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;

/**
 * Put the subject in a cohort, or take them out of it, by writing the one
 * cohort column the free build owns.
 *
 * Two keys and one class, because add and remove are the same write with a
 * different value and a second class would be a second copy of the guard.
 *
 * **The store is enrolments.cohort_id, and that is a deviation worth naming.**
 * The cohorts table itself is Pro, so on a free site there is no cohort to
 * point at; the pointer is a free column with a free index, and it is what
 * every free progress read already joins on. The alternative was registering
 * two of the nine free actions with nothing at all to write, which is the
 * free-provider rule failing one step further in. The action refuses a
 * subject with no enrolment on the named course rather than inventing one:
 * cohort membership is a fact about an enrolment, and an automation that
 * enrolled somebody as a side effect of tagging them would be a second
 * enrolment path.
 */
final class CohortMembershipAction implements
    AutomationActionInterface,
    DeclaresEffectClass,
    DeclaresSentence,
    SupportsDryRun,
    SupportsPayloadVersions,
    ValidatesPayload,
    DeclaresReversal
{
    public const KEY_ADD = 'add_to_cohort';
    public const KEY_REMOVE = 'remove_from_cohort';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly bool $joins = true,
    ) {
    }

    public static function add(DatabasePort $database, ClockPort $clock): self
    {
        return new self($database, $clock, true);
    }

    public static function remove(DatabasePort $database, ClockPort $clock): self
    {
        return new self($database, $clock, false);
    }

    public function key(): string
    {
        return $this->joins ? self::KEY_ADD : self::KEY_REMOVE;
    }

    public function label(): string
    {
        return $this->joins
            ? 'vidyalms.automation.action.add_to_cohort'
            : 'vidyalms.automation.action.remove_from_cohort';
    }

    /** What this action reverses, or null for the forward half of the pair. */
    public function reverses(): ?string
    {
        return $this->joins ? null : self::KEY_ADD;
    }

    public function paramsSchema(): array
    {
        $properties = ['course_id' => ['type' => 'integer', 'minimum' => 1]];

        if ($this->joins) {
            $properties['cohort_id'] = ['type' => 'integer', 'minimum' => 1];
        }

        return [
            'type' => 'object',
            'required' => $this->joins ? ['course_id', 'cohort_id'] : ['course_id'],
            'additionalProperties' => false,
            'properties' => $properties,
        ];
    }

    /**
     * The two keys the seam declares, and not the two it was answering.
     *
     * Every shipped action answered `surfaces` and `subjects`, neither of
     * which the interface documents and neither of which anything read, so
     * the save-time refusal the seam promises had nothing to refuse
     * against.
     */
    public function applicability(): array
    {
        return ['subject_types' => ['user'], 'trigger_keys' => []];
    }

    public function effectClass(): AutomationEffectClass
    {
        return AutomationEffectClass::Membership;
    }

    public function sentence(): string
    {
        return 'vidyalms.automation.sentence.cohort';
    }

    public function supportedParamsVersions(): array
    {
        return [1];
    }

    public function validateParams(array $params, PayloadValidationContext $context): array
    {
        $problems = [];
        $courseId = $params['course_id'] ?? null;

        if (!\is_int($courseId) || $courseId < 1) {
            $problems[] = 'automation.cohort.course_id_required';
        }

        if ($this->joins) {
            $cohortId = $params['cohort_id'] ?? null;

            if (!\is_int($cohortId) || $cohortId < 1) {
                $problems[] = 'automation.cohort.cohort_id_required';
            }
        }

        return $problems;
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return [
            'subject_id' => $context->subjectId,
            'course_id' => $this->intParam($context, 'course_id'),
            'cohort_id' => $this->joins ? $this->intParam($context, 'cohort_id') : null,
            'joins' => $this->joins,
        ];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        $courseId = $this->intParam($context, 'course_id');
        $userId = $context->actsForUserId() ?? 0;

        if ($courseId < 1 || $userId < 1) {
            return ActionOutcome::failed('cohort.params_missing', 'The step named no course or no learner.', false);
        }

        $row = $this->database->selectOne(
            Query::from('enrolments')
                ->select(['id', 'cohort_id'])
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId),
        );

        if ($row === null) {
            return ActionOutcome::failed('cohort.not_enrolled', 'The learner is not on that course.', false);
        }

        $current = $row['cohort_id'] === null ? null : (int) $row['cohort_id'];
        $target = $this->joins ? $this->intParam($context, 'cohort_id') : null;

        if ($current === $target) {
            return ActionOutcome::suppressed(SuppressionReason::ALREADY_IN_STATE, ['cohort_id' => $current]);
        }

        if ($context->dryRun) {
            return ActionOutcome::executed(['cohort_id' => $current], $this->previewFor($context));
        }

        $this->database->update('enrolments', [
            'cohort_id' => $target,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => (int) $row['id']]);

        return ActionOutcome::executed(['cohort_id' => $current], ['cohort_id' => $target]);
    }

    private function intParam(AutomationActionContext $context, string $key): int
    {
        $value = $context->param($key, 0);

        return \is_int($value) ? $value : 0;
    }
}
