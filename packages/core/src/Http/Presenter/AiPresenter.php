<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Application\Ai\AiDataUsePolicyContent;
use Shondalai\VidyaLMS\Core\Application\Ai\AiPolicyAdmin;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\DataHandling;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The wire shapes of the AI surface.
 *
 * Three rules are carried here rather than in each handler, because a shape
 * that carries them in one place is a shape a new route inherits.
 *
 * Every stored document travels beside its own mirrored version, and the
 * reserved envelope key never reaches the wire: a client that reads a payload
 * with no version cannot tell an old shape from a current one and will write
 * back whichever it happened to render.
 *
 * Money is nullable rather than zero wherever the bridge did not report it.
 * A usage report saying a site spent nothing is worse than one saying it does
 * not know, and the same is true of a single run.
 *
 * There is no score, no confidence and no risk value on any shape here. The
 * absence is the statement: adding one is a migration somebody has to justify
 * rather than a field that was quietly waiting.
 */
final class AiPresenter
{
    /**
     * @param array<string, mixed>       $row      the ai_runs row
     * @param list<array<string, mixed>> $attempts its ai_run_attempts rows, in attempt order
     * @param list<array<string, mixed>> $drafts   its ai_drafts rows
     *
     * @return array<string, mixed>
     */
    public static function run(array $row, array $attempts = [], array $drafts = [], int $subjectCount = 0): array
    {
        $refusalKind = self::nullableString($row['refusal_kind'] ?? null);

        return [
            'id' => self::int($row['id'] ?? 0),
            'run_key' => self::string($row['run_key'] ?? ''),
            'job_id' => self::nullableInt($row['job_id'] ?? null),
            'task_key' => self::string($row['task_key'] ?? ''),
            'task_version' => self::int($row['task_version'] ?? 1),
            'placement_key' => self::nullableString($row['placement_key'] ?? null),
            'route_id' => self::nullableInt($row['route_id'] ?? null),
            'bridge_key' => self::string($row['bridge_key'] ?? 'null'),
            'model' => self::nullableString($row['model'] ?? null),
            'prompt_ref' => self::nullableString($row['prompt_ref'] ?? null),
            'policy_version' => self::int($row['policy_version'] ?? 0),
            'principal_user_id' => self::nullableInt($row['principal_user_id'] ?? null),
            'actor_kind' => self::string($row['actor_kind'] ?? 'person'),
            'rule_id' => self::nullableInt($row['rule_id'] ?? null),
            'on_behalf_of_user_id' => self::nullableInt($row['on_behalf_of_user_id'] ?? null),
            'subject_type' => self::nullableString($row['subject_type'] ?? null),
            'subject_id' => self::nullableInt($row['subject_id'] ?? null),
            'course_id' => self::nullableInt($row['course_id'] ?? null),
            'organisation_id' => self::nullableInt($row['organisation_id'] ?? null),
            'locale' => self::string($row['locale'] ?? 'en-GB'),
            'inputs_digest' => self::string($row['inputs_digest'] ?? ''),
            'redaction_manifest' => self::manifest($row['redaction_manifest'] ?? null),
            'redaction_manifest_version' => self::int($row['redaction_manifest_version'] ?? 1),
            'dry_run' => self::bool($row['dry_run'] ?? false),
            'state' => self::string($row['state'] ?? 'reserved'),
            'refusal' => $refusalKind === null ? null : [
                'kind' => $refusalKind,
                'message_key' => 'vidyalms.ai.refusal.' . $refusalKind,
                'parameters' => [],
            ],
            'error_code' => self::nullableString($row['error_code'] ?? null),
            'error_message' => self::nullableString($row['error_message'] ?? null),
            'tokens_in' => self::nullableInt($row['tokens_in'] ?? null),
            'tokens_out' => self::nullableInt($row['tokens_out'] ?? null),
            'cost_unit' => self::string($row['cost_unit'] ?? 'none'),
            'cost_reserved' => self::nullableInt($row['cost_reserved'] ?? null),
            'cost_charged' => self::nullableInt($row['cost_charged'] ?? null),
            'unbilled' => self::bool($row['unbilled'] ?? false),
            'schema_valid' => self::nullableBool($row['schema_valid'] ?? null),
            'schema_unenforced' => self::stringList($row['schema_unenforced'] ?? null),
            'provider_request_id' => self::nullableString($row['provider_request_id'] ?? null),
            'finish_reason' => self::nullableString($row['finish_reason'] ?? null),
            'duration_ms' => self::nullableInt($row['duration_ms'] ?? null),
            'started_at' => self::nullableString($row['started_at'] ?? null),
            'completed_at' => self::nullableString($row['completed_at'] ?? null),
            'settled_at' => self::nullableString($row['settled_at'] ?? null),
            'retain_until' => self::string($row['retain_until'] ?? ''),
            'created_at' => self::nullableString($row['created_at'] ?? null),
            'subject_count' => $subjectCount,
            'attempts' => array_map(self::attempt(...), $attempts),
            'drafts' => array_map(self::draftSummary(...), $drafts),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function attempt(array $row): array
    {
        return [
            'attempt_no' => self::int($row['attempt_no'] ?? 1),
            'route_id' => self::nullableInt($row['route_id'] ?? null),
            'bridge_key' => self::string($row['bridge_key'] ?? 'null'),
            'model' => self::nullableString($row['model'] ?? null),
            'success' => self::bool($row['success'] ?? false),
            'refusal_kind' => self::nullableString($row['refusal_kind'] ?? null),
            'error_code' => self::nullableString($row['error_code'] ?? null),
            'error_message' => self::nullableString($row['error_message'] ?? null),
            'tokens_in' => self::nullableInt($row['tokens_in'] ?? null),
            'tokens_out' => self::nullableInt($row['tokens_out'] ?? null),
            'cost_unit' => self::string($row['cost_unit'] ?? 'none'),
            'cost_charged' => self::nullableInt($row['cost_charged'] ?? null),
            'latency_ms' => self::nullableInt($row['latency_ms'] ?? null),
            'started_at' => self::string($row['started_at'] ?? ''),
            'completed_at' => self::nullableString($row['completed_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $findings
     * @param array<string, mixed>|null  $run
     *
     * @return array<string, mixed>
     */
    public static function draft(
        array $row,
        array $findings = [],
        ?array $run = null,
        bool $acceptorAvailable = false,
        ?string $acceptorPackage = null,
    ): array {
        return [
            'id' => self::int($row['id'] ?? 0),
            'run_id' => self::int($row['run_id'] ?? 0),
            'job_id' => self::nullableInt($row['job_id'] ?? null),
            'draft_kind' => self::string($row['draft_kind'] ?? ''),
            'target_extension_point' => self::nullableString($row['target_extension_point'] ?? null),
            'target_provider_key' => self::nullableString($row['target_provider_key'] ?? null),
            'acceptor_available' => $acceptorAvailable,
            'acceptor_package' => $acceptorPackage,
            'subject_type' => self::nullableString($row['subject_type'] ?? null),
            'subject_id' => self::nullableInt($row['subject_id'] ?? null),
            'course_id' => self::nullableInt($row['course_id'] ?? null),
            'parent_draft_id' => self::nullableInt($row['parent_draft_id'] ?? null),
            'ordinal' => self::int($row['ordinal'] ?? 0),
            'payload' => self::document($row['payload'] ?? null) ?? [],
            'payload_version' => self::int($row['payload_version'] ?? 1),
            'edited_payload' => self::document($row['edited_payload'] ?? null),
            'edited_payload_version' => self::int($row['edited_payload_version'] ?? 1),
            'citations' => self::documentList($row['citations'] ?? null),
            'citations_version' => self::int($row['citations_version'] ?? 1),
            'predicted_difficulty' => self::nullableString($row['predicted_difficulty'] ?? null),
            'predicted_difficulty_method' => self::nullableString($row['predicted_difficulty_method'] ?? null),
            'state' => self::string($row['state'] ?? 'generated'),
            'findings_blocking' => self::int($row['findings_blocking'] ?? 0),
            'findings_advisory' => self::int($row['findings_advisory'] ?? 0),
            'citation_coverage' => self::nullableInt($row['citation_coverage'] ?? null),
            'reviewer_user_id' => self::nullableInt($row['reviewer_user_id'] ?? null),
            'review_started_at' => self::nullableString($row['review_started_at'] ?? null),
            'decided_at' => self::nullableString($row['decided_at'] ?? null),
            'reject_reason_code' => self::nullableString($row['reject_reason_code'] ?? null),
            'reject_reason' => self::nullableString($row['reject_reason'] ?? null),
            'accepted_by' => self::nullableInt($row['accepted_by'] ?? null),
            'accepted_at' => self::nullableString($row['accepted_at'] ?? null),
            'edit_distance_ratio' => self::nullableString($row['edit_distance_ratio'] ?? null),
            'disclosure_tier' => self::nullableString($row['disclosure_tier'] ?? null),
            'created_entity_type' => self::nullableString($row['created_entity_type'] ?? null),
            'created_entity_id' => self::nullableInt($row['created_entity_id'] ?? null),
            'retain_until' => self::string($row['retain_until'] ?? ''),
            'findings' => array_map(self::finding(...), $findings),
            'run' => $run,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function draftSummary(array $row): array
    {
        return [
            'id' => self::int($row['id'] ?? 0),
            'draft_kind' => self::string($row['draft_kind'] ?? ''),
            'state' => self::string($row['state'] ?? 'generated'),
            'ordinal' => self::int($row['ordinal'] ?? 0),
            'parent_draft_id' => self::nullableInt($row['parent_draft_id'] ?? null),
            'findings_blocking' => self::int($row['findings_blocking'] ?? 0),
            'findings_advisory' => self::int($row['findings_advisory'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function finding(array $row): array
    {
        return [
            'id' => self::int($row['id'] ?? 0),
            'check_key' => self::string($row['check_key'] ?? ''),
            'severity' => self::string($row['severity'] ?? 'advisory'),
            'code' => self::string($row['code'] ?? ''),
            'path' => self::nullableString($row['path'] ?? null),
            'message' => self::string($row['message'] ?? ''),
            'evidence' => self::document($row['evidence'] ?? null),
            'evidence_version' => self::int($row['evidence_version'] ?? 1),
            'acknowledged' => self::bool($row['acknowledged'] ?? false),
            'acknowledged_by' => self::nullableInt($row['acknowledged_by'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function job(array $row): array
    {
        return [
            'id' => self::int($row['id'] ?? 0),
            'task_key' => self::nullableString($row['task_key'] ?? null),
            'recipe_key' => self::nullableString($row['recipe_key'] ?? null),
            'step_index' => self::int($row['step_index'] ?? 0),
            'placement_key' => self::nullableString($row['placement_key'] ?? null),
            'status' => self::string($row['status'] ?? 'pending'),
            'scope_type' => self::string($row['scope_type'] ?? 'course'),
            'scope_id' => self::int($row['scope_id'] ?? 0),
            'organisation_id' => self::nullableInt($row['organisation_id'] ?? null),
            'options' => self::document($row['options'] ?? null),
            'options_version' => self::int($row['options_version'] ?? 1),
            'dry_run' => self::bool($row['dry_run'] ?? false),
            'cost_unit' => self::string($row['cost_unit'] ?? 'none'),
            'cost_reserved' => self::nullableInt($row['cost_reserved'] ?? null),
            'cost_charged' => self::nullableInt($row['cost_charged'] ?? null),
            'log' => self::nullableString($row['log'] ?? null),
            'totals' => self::document($row['totals'] ?? null),
            'totals_version' => self::int($row['totals_version'] ?? 1),
            'resume_at' => self::nullableString($row['resume_at'] ?? null),
            'started_at' => self::nullableString($row['started_at'] ?? null),
            'finished_at' => self::nullableString($row['finished_at'] ?? null),
            'created_by' => self::int($row['created_by'] ?? 0),
            'created_at' => self::nullableString($row['created_at'] ?? null),
            'updated_at' => self::nullableString($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function route(
        array $row,
        bool $bridgeAvailable,
        ?string $bridgeLabel,
        ?string $taskLabel,
        ?DataHandling $dataHandling = null,
    ): array {
        $handling = $dataHandling ?? DataHandling::undeclared();

        return [
            'id' => self::int($row['id'] ?? 0),
            'task_key' => self::string($row['task_key'] ?? ''),
            'task_label' => $taskLabel,
            'bridge_key' => self::string($row['bridge_key'] ?? ''),
            'bridge_label' => $bridgeLabel,
            'bridge_available' => $bridgeAvailable,
            // What this route does with what it is sent, read from the
            // bridge's own declaration. The capability existed and nothing
            // probed it, so the route table could not show whether a
            // configured route retained input or trained on it, and the
            // derived part of the data-use policy had no source. An absent
            // declaration is the published absence and not a silence.
            'data_handling' => [
                'declared' => $handling->isDeclared(),
                'retains_input' => $handling->retainsInput,
                'retains_output' => $handling->retainsOutput,
                'trains_on_input' => $handling->trainsOnInput,
                'processing_region' => $handling->processingRegion,
                'retention_days' => $handling->retentionDays,
                'terms_ref' => $handling->termsRef,
            ],
            'model' => self::nullableString($row['model'] ?? null),
            'prompt_ref' => self::nullableString($row['prompt_ref'] ?? null),
            'params' => self::document($row['params'] ?? null),
            'params_version' => self::int($row['params_version'] ?? 1),
            'position' => self::int($row['position'] ?? 0),
            'enabled' => self::bool($row['enabled'] ?? false),
            'scope_type' => self::string($row['scope_type'] ?? 'site'),
            'scope_id' => self::int($row['scope_id'] ?? 0),
            'max_cost_per_run' => self::int($row['max_cost_per_run'] ?? 0),
            'updated_by' => self::nullableInt($row['updated_by'] ?? null),
            'created_at' => self::nullableString($row['created_at'] ?? null),
            'updated_at' => self::nullableString($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function policy(array $row, ?int $acceptanceCount = null): array
    {
        $parts = AiPolicyAdmin::partsOf(self::string($row['body'] ?? ''));

        return [
            'version' => self::int($row['version'] ?? 0),
            'title' => self::string($row['title'] ?? ''),
            'body' => self::string($row['body'] ?? ''),
            'locale' => self::string($row['locale'] ?? 'en-GB'),
            'redaction_level' => self::string($row['redaction_level'] ?? 'strict'),
            'retention_days' => self::int($row['retention_days'] ?? 400),
            'training_reuse' => self::string($row['training_reuse'] ?? 'declined'),
            'enabled_tasks' => self::enabledTasks($row['enabled_tasks'] ?? null),
            'enabled_tasks_version' => self::int($row['enabled_tasks_version'] ?? 1),
            'body_hash' => self::string($row['body_hash'] ?? ''),
            'effective_from' => self::string($row['effective_from'] ?? ''),
            'state' => self::string($row['state'] ?? 'draft'),
            'created_by' => self::nullableInt($row['created_by'] ?? null),
            'acceptance_count' => $acceptanceCount,
            // The eight parts as their own map, and which of them are still
            // empty. A screen that could only render the body could not ask
            // the eight questions the design says a version has to answer, and
            // the validator that names them was called by no write path at all.
            'parts' => $parts === [] ? null : $parts,
            'missing_parts' => AiDataUsePolicyContent::missingParts($parts),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function provenance(array $row): array
    {
        return [
            'subject_type' => self::string($row['subject_type'] ?? ''),
            'subject_id' => self::int($row['subject_id'] ?? 0),
            'run_id' => self::int($row['run_id'] ?? 0),
            'draft_id' => self::nullableInt($row['draft_id'] ?? null),
            'task_key' => self::string($row['task_key'] ?? ''),
            'task_version' => self::int($row['task_version'] ?? 1),
            'prompt_ref' => self::nullableString($row['prompt_ref'] ?? null),
            'model' => self::nullableString($row['model'] ?? null),
            'inputs_digest' => self::string($row['inputs_digest'] ?? ''),
            'disclosure_tier' => self::string($row['disclosure_tier'] ?? ''),
            'edit_distance_ratio' => self::nullableString($row['edit_distance_ratio'] ?? null),
            'human_edited' => self::bool($row['human_edited'] ?? false),
            'accepted_by' => self::int($row['accepted_by'] ?? 0),
            'accepted_at' => self::string($row['accepted_at'] ?? ''),
            'superseded_at' => self::nullableString($row['superseded_at'] ?? null),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public static function page(array $items, int $total, int $page, int $perPage): array
    {
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * The redaction manifest as the learner's right-to-know view and the dry
     * run render it: one provenance label per prompt segment the redactor
     * emitted, in the order it resolved them, never a prose category.
     *
     * The stored document is an object with a `fields` member rather than a
     * bare list, because the stored-shape envelope keys its version by name
     * and a list would carry it as a numeric-keyed sibling nobody can read
     * back safely. Null and an empty list are different answers, and only the
     * first is honest about a run whose manifest was never recorded.
     *
     * @return list<string>|null
     */
    public static function manifest(mixed $value): ?array
    {
        $document = self::document($value);

        if ($document === null) {
            return null;
        }

        return self::stringListOrNull($document['fields'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function document(mixed $value): ?array
    {
        $decoded = self::decode($value);

        if ($decoded === null) {
            return null;
        }

        $body = \array_key_exists(StoredShape::VERSION_KEY, $decoded) ? StoredShape::bodyOf($decoded) : $decoded;

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @return list<mixed>|null
     */
    public static function documentList(mixed $value): ?array
    {
        $decoded = self::decode($value);

        if ($decoded === null) {
            return null;
        }

        $body = \array_key_exists(StoredShape::VERSION_KEY, $decoded) ? StoredShape::bodyOf($decoded) : $decoded;

        return array_values($body);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(mixed $value): ?array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * The policy's enabled task list.
     *
     * It is stored as an object with a `tasks` member rather than as a bare
     * array, because the stored-shape envelope keys its version by name and a
     * list would carry it as a numeric-keyed sibling nobody can read back
     * safely. The wire shape stays a list, which is what a client needs.
     *
     * @return list<string>|null
     */
    private static function enabledTasks(mixed $value): ?array
    {
        $document = self::document($value);

        if ($document === null) {
            return null;
        }

        return self::stringListOrNull($document['tasks'] ?? null) ?? [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return self::stringListOrNull($value) ?? [];
    }

    /**
     * @return list<string>|null
     */
    private static function stringListOrNull(mixed $value): ?array
    {
        $decoded = self::documentList($value);

        if ($decoded === null) {
            return null;
        }

        $out = [];

        foreach ($decoded as $entry) {
            if (\is_scalar($entry)) {
                $out[] = (string) $entry;
            }
        }

        return $out;
    }

    private static function int(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : self::int($value);
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : self::string($value);
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function nullableBool(mixed $value): ?bool
    {
        return $value === null || $value === '' ? null : self::bool($value);
    }
}
