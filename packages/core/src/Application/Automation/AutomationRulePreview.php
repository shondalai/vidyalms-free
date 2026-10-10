<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationReadModel;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresCost;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionRegistry;

/**
 * The wire side of the rehearsal.
 *
 * The rehearsal itself is PreviewAutomationRule, which drives the real step
 * machine with the dry-run flag set. This class only reads the request, refuses
 * what the engine cannot answer, and flattens the engine's per-subject report
 * into the per-step list a reader wants: an operator asks "what would happen",
 * and grouping the answer by subject makes them read four hundred groups to
 * find the one step that would be suppressed.
 *
 * The subjects are named by the caller rather than resolved from an audience,
 * because that is what the shipped rehearsal takes. A rehearsal over a whole
 * cohort is a fan-out with real cost, and the design's own rule is that the
 * preview reports the subjects it was asked about up to a bound.
 */
final class AutomationRulePreview
{
    /** @var list<string> */
    private const FIELDS = ['subject_ids', 'subject_type'];

    private const MAX_SUBJECTS = 100;

    public function __construct(
        private readonly PreviewAutomationRule $preview,
        private readonly SqlAutomationReadModel $reads,
        private readonly AutomationActionRegistry $actions,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function execute(Principal $principal, int $ruleId, array $body): array
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);
        AutomationInput::only($body, self::FIELDS);

        if ($this->reads->rule($ruleId) === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRule', $ruleId);
        }

        $subjectIds = $this->subjectIds($body);
        $subjectType = $body['subject_type'] ?? 'user';

        if (!\is_string($subjectType) || $subjectType === '') {
            throw ValidationException::forField('subject_type', 'Expected a subject type.');
        }

        $report = $this->preview->execute($principal, $ruleId, $subjectIds, $subjectType);

        return $this->shape($report, $subjectType);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<int>
     */
    private function subjectIds(array $body): array
    {
        $raw = $body['subject_ids'] ?? null;

        if (!\is_array($raw) || $raw === [] || !array_is_list($raw)) {
            throw ValidationException::forField(
                'subject_ids',
                'Name at least one subject to rehearse for. A rehearsal over everybody is a fan-out with real cost.',
            );
        }

        if (\count($raw) > self::MAX_SUBJECTS) {
            throw ValidationException::forField(
                'subject_ids',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('At most %d subjects in one rehearsal.', self::MAX_SUBJECTS),
            );
        }

        $ids = [];

        foreach ($raw as $entry) {
            if (!\is_int($entry) || $entry < 1) {
                throw ValidationException::forField('subject_ids', 'Every subject is a positive integer id.');
            }

            $ids[] = $entry;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function shape(array $report, string $subjectType): array
    {
        $subjects = [];
        $steps = [];
        $warnings = [];
        $reported = \is_array($report['subjects'] ?? null) ? $report['subjects'] : [];

        foreach ($reported as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $subjectId = \is_int($entry['subject_id'] ?? null) ? $entry['subject_id'] : null;
            $subjects[] = ['subject_type' => $subjectType, 'subject_id' => $subjectId ?? 0, 'label' => null];

            if (($entry['skipped'] ?? false) === true) {
                $warnings[] = sprintf(
                    'Subject %d is already inside this rule for this occurrence, so no rehearsal was opened.',
                    $subjectId ?? 0,
                );

                continue;
            }

            foreach (\is_array($entry['steps'] ?? null) ? $entry['steps'] : [] as $step) {
                if (!\is_array($step)) {
                    continue;
                }

                $steps[] = [
                    'step_index' => (int) ($step['step_index'] ?? 0),
                    'action_key' => (string) ($step['action_key'] ?? ''),
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'disposition' => (string) ($step['disposition'] ?? 'suppressed'),
                    'suppressed_reason' => null,
                    'payload' => \is_array($step['payload'] ?? null) ? $step['payload'] : null,
                    'payload_version' => 1,
                    'estimated_cost' => $this->costOf((string) ($step['action_key'] ?? ''), $step['cost'] ?? null),
                ];
            }
        }

        $matched = (int) ($report['subject_count'] ?? \count($subjects));
        $named = (int) ($report['named_subjects'] ?? \count($subjects));

        return [
            'rule_id' => (int) ($report['rule_id'] ?? 0),
            'rule_version' => (int) ($report['rule_version'] ?? 1),
            'dry_run' => true,
            'occurrence_key' => (string) ($report['occurrence_key'] ?? ''),
            'matched_count' => $matched,
            'subjects' => $subjects,
            'truncated' => $matched > $named,
            'steps' => $steps,
            'estimated_cost_total' => null,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function costOf(string $actionKey, mixed $cost): ?array
    {
        if ($cost === null || !$this->actions->has($actionKey)) {
            return null;
        }

        $action = $this->actions->get($actionKey);

        if (!$action instanceof DeclaresCost) {
            return null;
        }

        return ['unit' => $action->costUnit(), 'amount' => (int) (is_numeric($cost) ? $cost : 0)];
    }
}
