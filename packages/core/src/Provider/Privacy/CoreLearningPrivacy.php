<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Privacy;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Registry\Privacy\DescribesForArticle30;
use Shondalai\VidyaLMS\Core\Registry\Privacy\EnumeratesOrganisationUsers;
use Shondalai\VidyaLMS\Core\Registry\Privacy\EraseOutcome;
use Shondalai\VidyaLMS\Core\Registry\Privacy\ErasesSubjectRecords;
use Shondalai\VidyaLMS\Core\Registry\Privacy\ErasesWithOutcome;
use Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorInterface;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis;
use Shondalai\VidyaLMS\Core\Registry\Privacy\SubjectPrivacyGroup;

/** Personal learning data shared by both hosts' subject export and erasure hooks. */
final class CoreLearningPrivacy implements
    PrivacyContributorInterface,
    EnumeratesOrganisationUsers,
    DescribesForArticle30,
    ErasesWithOutcome,
    ErasesSubjectRecords
{
    public const KEY = 'core-learning';

    /**
     * Every table the two host const arrays named, as one list.
     *
     * The record class key is what both hooks show a person; the column is where
     * the subject id lives, and the field list is what a subject may see. It is
     * an allowlist and never `SELECT *`: an export that widened itself when a
     * column was added is an export that discloses a column nobody reviewed.
     *
     * @var array<string, array{label: string, table: string, column: string, fields: list<string>}>
     */
    public const RECORD_CLASSES = [
        'shop_access_links' => [
            'label' => 'Shop course access',
            'table' => 'shop_access_links',
            'column' => 'user_id',
            'fields' => ['id', 'shop', 'object_type', 'object_id', 'user_id', 'revision', 'entitlements', 'last_reason', 'created_at', 'updated_at'],
        ],
        'entitlements' => [
            'label' => 'Course access',
            'table' => 'entitlements',
            'column' => 'user_id',
            'fields' => ['id', 'user_id', 'course_id', 'source', 'source_ref', 'status', 'granted_by', 'starts_at', 'expires_at', 'organisation_id', 'org_membership_id', 'source_ref_digest', 'revoked_reason', 'revoked_ref', 'purchase_ip_hash', 'purchase_country', 'purchased_at', 'created_at', 'updated_at'],
        ],
        'enrolments' => [
            'label' => 'Enrolments',
            'table' => 'enrolments',
            'column' => 'user_id',
            'fields' => ['id', 'user_id', 'course_id', 'entitlement_id', 'status', 'enrolled_at', 'completed_at', 'last_activity_at', 'progress_percent', 'cohort_id', 'created_at', 'updated_at'],
        ],
        'lesson_progress' => [
            'label' => 'Lesson progress',
            'table' => 'lesson_progress',
            'column' => 'user_id',
            'fields' => ['id', 'enrolment_id', 'lesson_id', 'user_id', 'status', 'progress_percent', 'seconds_spent', 'position_seconds', 'first_accessed_at', 'completed_at', 'last_heartbeat_at', 'created_at', 'updated_at'],
        ],
        'video_positions' => [
            'label' => 'Video positions',
            'table' => 'video_positions',
            'column' => 'user_id',
            'fields' => ['id', 'user_id', 'lesson_id', 'position_seconds', 'duration_seconds', 'created_at', 'updated_at'],
        ],
        'attempts' => [
            'label' => 'Assessment attempts',
            'table' => 'attempts',
            'column' => 'user_id',
            'fields' => ['id', 'quiz_id', 'enrolment_id', 'user_id', 'attempt_number', 'status', 'started_at', 'submitted_at', 'score', 'max_score', 'percentage', 'passed', 'time_spent_seconds', 'policy_snapshot', 'behaviour', 'expires_at', 'closure_reason', 'closed_by_seat_event_id', 'created_at', 'updated_at'],
        ],
        'submissions' => [
            'label' => 'Assignment submissions',
            'table' => 'submissions',
            'column' => 'user_id',
            'fields' => ['id', 'assignment_id', 'enrolment_id', 'user_id', 'attempt_number', 'status', 'text_response', 'submitted_at', 'graded_at', 'grader_id', 'points_awarded', 'feedback', 'assessment_json', 'created_at', 'updated_at'],
        ],
        'certificates' => [
            'label' => 'Certificates',
            'table' => 'certificates',
            'column' => 'user_id',
            'fields' => ['id', 'user_id', 'course_id', 'enrolment_id', 'template_id', 'template_revision', 'template_snapshot', 'context_snapshot', 'renderer_key', 'engine_version', 'code', 'uuid', 'issued_at', 'completed_at', 'valid_from', 'expires_at', 'status', 'active_enrolment_key', 'revoked_at', 'revocation_reason', 'superseded_by_id', 'supersedes_id', 'published', 'artefact_checksum', 'rendered_at', 'credential_json', 'credential_format', 'proof_type', 'recipient_identity_hash', 'event_ref', 'issuer_earner_id', 'metadata', 'created_at', 'updated_at'],
        ],
        'activity_log' => [
            'label' => 'Activity log',
            'table' => 'activity_log',
            'column' => 'user_id',
            'fields' => ['id', 'user_id', 'course_id', 'lesson_id', 'enrolment_id', 'action', 'context', 'occurred_at', 'event_ref', 'created_at', 'updated_at'],
        ],
        'email_log' => [
            'label' => 'Email log',
            'table' => 'email_log',
            'column' => 'user_id',
            'fields' => ['id', 'user_id', 'template_key', 'recipient', 'status', 'error', 'sent_at', 'event_ref', 'attempt', 'created_at', 'updated_at'],
        ],
        'course_instructors' => [
            'label' => 'Course teaching assignments',
            'table' => 'course_instructors',
            'column' => 'user_id',
            'fields' => ['id', 'course_id', 'user_id', 'role', 'created_at', 'updated_at'],
        ],
        'availability_grants' => [
            'label' => 'Availability overrides', 'table' => 'availability_grants', 'column' => 'user_id',
            'fields' => ['id', 'scope_type', 'scope_id', 'subject_type', 'subject_id', 'user_id', 'mode', 'reason', 'granted_by', 'expires_at', 'created_at', 'updated_at'],
        ],
        'credit_scheme_identities' => [
            'label' => 'Credit identities', 'table' => 'credit_scheme_identities', 'column' => 'user_id',
            'fields' => ['id', 'user_id', 'scheme', 'field', 'value', 'created_at', 'updated_at'],
        ],
        'grade_changes' => [
            'label' => 'Grade history', 'table' => 'grade_changes', 'column' => 'user_id',
            'fields' => ['id', 'grade_cell_id', 'grade_item_id', 'course_id', 'user_id', 'grade_before', 'grade_after', 'max_before', 'max_after', 'status_before', 'status_after', 'actor_id', 'reason', 'request_ref', 'payload', 'payload_version', 'occurred_at'],
        ],
        'grade_cells' => [
            'label' => 'Gradebook', 'table' => 'grade_cells', 'column' => 'user_id',
            'fields' => ['id', 'grade_item_id', 'user_id', 'course_id', 'raw_grade', 'raw_grade_max', 'raw_grade_min', 'final_grade', 'activity_progress', 'grading_progress', 'score_status', 'aggregation_status', 'aggregation_weight_bp', 'excluded', 'hidden', 'locked', 'overridden_at', 'feedback', 'graded_by', 'graded_at', 'scored_at', 'created_at', 'updated_at'],
        ],
        'notification_queue' => [
            'label' => 'Notification deliveries', 'table' => 'notification_queue', 'column' => 'recipient_user_id',
            'fields' => ['id', 'rule_id', 'trigger_key', 'category_key', 'class', 'subject_type', 'subject_id', 'recipient_user_id', 'channel_key', 'template_key', 'parameters', 'event_ref', 'occurrence_key', 'run_at', 'recipient_timezone', 'recipient_locale', 'state', 'claimed_at', 'lease_expires_at', 'attempts', 'next_attempt_at', 'provider_message_id', 'last_error', 'suppressed_reason', 'sent_at', 'created_at', 'updated_at'],
        ],
        'notification_digest_items' => [
            'label' => 'Notification digests', 'table' => 'notification_digest_items', 'column' => 'recipient_user_id',
            'fields' => ['id', 'recipient_user_id', 'channel_key', 'category_key', 'frequency', 'trigger_key', 'subject_type', 'subject_id', 'parameters', 'occurred_at', 'collapse_batch', 'collapsed_into', 'dropped_at', 'dropped_reason', 'created_at', 'updated_at'],
        ],
        'notification_preferences' => [
            'label' => 'Notification preferences', 'table' => 'notification_preferences', 'column' => 'user_id',
            'fields' => ['id', 'user_id', 'category_key', 'channel_key', 'frequency', 'created_at', 'updated_at'],
        ],
        'notification_consents' => [
            'label' => 'Notification consent', 'table' => 'notification_consents', 'column' => 'user_id',
            'fields' => ['id', 'user_id', 'category_key', 'state', 'basis', 'source', 'evidence', 'evidence_version', 'actor_user_id', 'ip', 'occurred_at', 'created_at', 'updated_at'],
        ],
        'notification_channel_addresses' => [
            'label' => 'Notification addresses', 'table' => 'notification_channel_addresses', 'column' => 'user_id',
            'fields' => ['id', 'user_id', 'channel_key', 'address_digest', 'confirmation_sent_at', 'confirmed_at', 'created_at', 'updated_at'],
        ],
        'learner_tags' => [
            'label' => 'Learner tags', 'table' => 'learner_tags', 'column' => 'user_id',
            'fields' => ['id', 'user_id', 'tag', 'source', 'source_ref', 'set_by', 'created_at', 'updated_at'],
        ],
        'org_learning_locks' => [
            'label' => 'Learning locks', 'table' => 'org_learning_locks', 'column' => 'user_id',
            'fields' => ['id', 'user_id', 'course_id', 'revision', 'created_at', 'updated_at'],
        ],
        'automation_action_log' => [
            'label' => 'Automated actions about the learner', 'table' => 'automation_action_log', 'column' => 'subject_id',
            'fields' => ['id', 'queue_id', 'run_id', 'rule_id', 'rule_version', 'step_index', 'action_key', 'subject_type', 'subject_id', 'actor_user_id', 'success', 'before_state', 'after_state', 'state_version', 'error_code', 'error_message', 'ai_run_id', 'executed_at', 'created_at', 'updated_at'],
        ],
        'automation_queue' => [
            'label' => 'Queued learner automations', 'table' => 'automation_queue', 'column' => 'recipient_user_id',
            'fields' => ['id', 'run_id', 'rule_id', 'rule_version', 'step_index', 'action_key', 'subject_type', 'subject_id', 'recipient_user_id', 'params', 'params_version', 'occurrence_key', 'run_at', 'state', 'claimed_at', 'lease_expires_at', 'attempts', 'next_attempt_at', 'cascade_depth', 'dry_run', 'suppressed_reason', 'last_error', 'ai_run_id', 'executed_at', 'created_at', 'updated_at'],
        ],
        'automation_runs' => [
            'label' => 'Learner automation runs', 'table' => 'automation_runs', 'column' => 'recipient_user_id',
            'fields' => ['id', 'rule_id', 'rule_version', 'trigger_key', 'event_ref', 'occurrence_key', 'subject_type', 'subject_id', 'recipient_user_id', 'actor_user_id', 'dry_run', 'state', 'cascade_depth', 'rule_chain', 'matched_count', 'executed_count', 'skipped_count', 'failed_count', 'log', 'started_at', 'finished_at', 'created_at', 'updated_at'],
        ],
        'ai_jobs' => [
            'label' => 'AI jobs requested by the person', 'table' => 'ai_jobs', 'column' => 'created_by',
            'fields' => ['id', 'task_key', 'recipe_key', 'step_index', 'placement_key', 'status', 'scope_type', 'scope_id', 'organisation_id', 'options', 'options_version', 'dry_run', 'cost_unit', 'cost_reserved', 'cost_charged', 'log', 'totals', 'totals_version', 'resume_at', 'started_at', 'finished_at', 'created_by', 'created_at', 'updated_at'],
        ],
        'report_schedules' => [
            'label' => 'Report schedules created by the person', 'table' => 'report_schedules', 'column' => 'created_by',
            'fields' => ['id', 'title', 'report_key', 'format_key', 'delivery_key', 'definition', 'definition_version', 'audience_key', 'audience_config', 'audience_config_version', 'recurrence', 'recurrence_day', 'run_at_minute', 'timezone', 'starts_at', 'next_run_at', 'last_run_at', 'render_as', 'empty_policy', 'subject', 'body', 'organisation_id', 'state', 'created_by', 'run_count', 'last_error', 'created_at', 'updated_at'],
        ],
        'compliance_assignments' => [
            'label' => 'Required learning', 'table' => 'compliance_assignments', 'column' => 'user_id',
            'fields' => ['id', 'requirement_id', 'organisation_id', 'user_id', 'cycle', 'assigned_at', 'starts_at', 'due_at', 'completed_at', 'expires_at', 'compliance_status', 'renewal_status', 'rule_state'],
        ],
        'assignment_exceptions' => [
            'label' => 'Required learning exceptions', 'table' => 'assignment_exceptions', 'column' => 'user_id',
            'fields' => ['id', 'organisation_id', 'requirement_id', 'user_id', 'kind', 'resolved_input', 'raised_at', 'resolved_at', 'resolution'],
        ],
    ];

    public function __construct(private readonly DatabasePort $database, private readonly ?\Shondalai\VidyaLMS\Core\Port\Storage\StoragePort $storage = null)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function describes(): array
    {
        $out = [];

        foreach (self::RECORD_CLASSES as $key => $definition) {
            $out[$key] = $definition['label'];
        }

        return $out;
    }

    /**
     * The per-user direction, which is the only direction this contributor
     * serves.
     *
     * Both host hooks read exactly this and nothing else, so the list they used
     * to hold is now derived from {@see RECORD_CLASSES}: adding a table there
     * changes both hosts' exports with no adapter edit.
     */
    public function subjectGroups(int $userId, string $email): array
    {
        if ($userId < 1) {
            return [];
        }

        $groups = [];

        foreach (self::RECORD_CLASSES as $key => $definition) {
            $column = $definition['column'];
            $fields = $definition['fields'];
            $groups[] = new SubjectPrivacyGroup(
                'vidyalms-' . str_replace('_', '-', $key),
                $definition['label'],
                $fields,
                fn (): Query => $this->subjectQuery($key, $userId)->select($fields),
            );
        }

        $groups[] = new SubjectPrivacyGroup(
            'vidyalms-attempt-questions', 'Attempt Questions', ['id', 'attempt_id', 'position', 'screen', 'question_id', 'question_version_id', 'points', 'scored', 'presented', 'choice_sequence', 'variant', 'definition_hash', 'answered_status', 'policy_key', 'policy_params', 'created_at', 'updated_at'],
            function () use ($userId): Query {
                $parents = $this->database->selectAll(Query::from('attempts')->select(['id'])->where('user_id', '=', $userId));
                return Query::from('attempt_questions')->select(['id', 'attempt_id', 'position', 'screen', 'question_id', 'question_version_id', 'points', 'scored', 'presented', 'choice_sequence', 'variant', 'definition_hash', 'answered_status', 'policy_key', 'policy_params', 'created_at', 'updated_at'])->whereIn('attempt_id', array_column($parents, 'id'));
            },
        );
        $groups[] = new SubjectPrivacyGroup(
            'vidyalms-attempt-answer-events', 'Attempt Answer Events', ['id', 'attempt_id', 'question_id', 'part_id', 'try', 'response', 'raw_input', 'presentation_form', 'certainty', 'processed', 'occurred_at', 'created_at', 'updated_at'],
            function () use ($userId): Query {
                $parents = $this->database->selectAll(Query::from('attempts')->select(['id'])->where('user_id', '=', $userId));
                return Query::from('attempt_answer_events')->select(['id', 'attempt_id', 'question_id', 'part_id', 'try', 'response', 'raw_input', 'presentation_form', 'certainty', 'processed', 'occurred_at', 'created_at', 'updated_at'])->whereIn('attempt_id', array_column($parents, 'id'));
            },
        );
        $groups[] = new SubjectPrivacyGroup(
            'vidyalms-integrity-events', 'Integrity Events', ['id', 'attempt_id', 'kind', 'actor', 'occurred_at', 'context', 'created_at', 'updated_at'],
            function () use ($userId): Query {
                $parents = $this->database->selectAll(Query::from('attempts')->select(['id'])->where('user_id', '=', $userId));
                return Query::from('integrity_events')->select(['id', 'attempt_id', 'kind', 'actor', 'occurred_at', 'context', 'created_at', 'updated_at'])->whereIn('attempt_id', array_column($parents, 'id'));
            },
        );
        $groups[] = new SubjectPrivacyGroup(
            'vidyalms-certificate-credits', 'Certificate Credits', ['id', 'certificate_id', 'scheme', 'amount', 'unit', 'field_of_study', 'awarding_body_id', 'contact_minutes', 'params', 'params_version', 'reported_at', 'created_at', 'updated_at'],
            function () use ($userId): Query {
                $parents = $this->database->selectAll(Query::from('certificates')->select(['id'])->where('user_id', '=', $userId));
                return Query::from('certificate_credits')->select(['id', 'certificate_id', 'scheme', 'amount', 'unit', 'field_of_study', 'awarding_body_id', 'contact_minutes', 'params', 'params_version', 'reported_at', 'created_at', 'updated_at'])->whereIn('certificate_id', array_column($parents, 'id'));
            },
        );
        $cycleFields = ['id', 'assignment_id', 'cycle', 'completed_at', 'certificate_id', 'expires_at', 'baseline_expires_at', 'reset_at', 'reset_ref'];
        $groups[] = new SubjectPrivacyGroup(
            'vidyalms-compliance-cycles', 'Required learning history', $cycleFields,
            function () use ($userId, $cycleFields): Query {
                $parents = $this->database->selectAll(Query::from('compliance_assignments')->select(['id'])->where('user_id', '=', $userId));
                return Query::from('compliance_cycles')->select($cycleFields)->whereIn('assignment_id', array_column($parents, 'id'));
            },
        );
        return $groups;
    }

    /**
     * Nothing, inside an organisation.
     *
     * A learner's own course history is not an employer-owned row, so an
     * organisation-scoped export of it would hand one controller another's data
     * about the same person. The record class is still named, so the console
     * shows what exists and where it is answered from rather than leaving a
     * silent gap.
     */
    public function exportFor(OrgPrivacyContext $context, string $recordClass, int $page, int $perPage): array
    {
        if (!isset(self::RECORD_CLASSES[$recordClass])) {
            throw ValidationException::forField('record_class', 'Choose an available privacy record class.');
        }

        return ['items' => [], 'total' => 0];
    }

    /** Nobody. This contributor holds no organisation-owned relationship. */
    public function usersInOrganisation(OrgPrivacyContext $context): iterable
    {
        return [];
    }

    public function eraseFor(OrgPrivacyContext $context): array
    {
        return $this->eraseWithOutcome($context)->toArray();
    }

    /**
     * An organisation erases none of this, and the receipt counts what it left
     * alone.
     *
     * That count is the evidence the bound held. A learner erased by one
     * organisation keeps every attempt, submission, certificate and enrolment,
     * because those belong to the learner and to whichever other organisations
     * paid for them, and this is the one operation in the product with no undo.
     */
    public function eraseWithOutcome(OrgPrivacyContext $context): EraseOutcome
    {
        $userId = $context->userId ?? throw new \LogicException('Erasure requires a subject.');
        $outcome = EraseOutcome::begin();

        foreach (self::RECORD_CLASSES as $key => $definition) {
            $outcome = $outcome->untouchedElsewhere($key, $this->database->count(
                $this->queryFor($key)->where($definition['column'], '=', $userId),
            ));
        }

        return $outcome
            ->retained(
                'certificates',
                $this->database->count(Query::from('certificates')->where('user_id', '=', $userId)),
                RetentionBasis::LEGAL_CLAIMS,
                'A credential is evidence a third party may rely on after the relationship ends.',
            )
            ->warning('A learner\'s own learning is never erased by an organisation. '
                . 'It is reached through the site\'s own subject request.');
    }

    /**
     * A query over one record class's table, named by a literal.
     *
     * Every table appears here as a string the tenancy scan can read, which is
     * the rule the whole repository follows: a table named by a variable is a
     * read the gate cannot check, and a check that quietly drops what it cannot
     * read reports clean about exactly the code that evaded it. The array above
     * stays the single register of what this contributor holds; this is how the
     * register reaches a query.
     */
    private function subjectQuery(string $recordClass, int $userId): Query
    {
        $query = $this->queryFor($recordClass);
        $subject = Predicate::allOf(Predicate::compare('subject_type', '=', 'user'),
            Predicate::compare('subject_id', '=', $userId));
        if (in_array($recordClass, ['automation_runs', 'notification_queue', 'notification_digest_items'], true)) {
            return $query->whereAnyOf(Predicate::compare('recipient_user_id', '=', $userId), $subject);
        }
        if (in_array($recordClass, ['automation_action_log', 'automation_queue'], true)) {
            $runs = $this->database->selectAll($this->subjectQuery('automation_runs', $userId)->select(['id']));
            $predicates = [$subject, Predicate::in('run_id', array_column($runs, 'id'))];
            if ($recordClass === 'automation_queue') {
                $predicates[] = Predicate::compare('recipient_user_id', '=', $userId);
            }
            return $query->whereAnyOf(...$predicates);
        }
        return $query->where(self::RECORD_CLASSES[$recordClass]['column'], '=', $userId);
    }

    private function queryFor(string $recordClass): Query
    {
        return match ($recordClass) {
            'shop_access_links' => Query::from('shop_access_links'),
            'entitlements' => Query::from('entitlements'),
            'enrolments' => Query::from('enrolments'),
            'lesson_progress' => Query::from('lesson_progress'),
            'video_positions' => Query::from('video_positions'),
            'attempts' => Query::from('attempts'),
            'submissions' => Query::from('submissions'),
            'certificates' => Query::from('certificates'),
            'activity_log' => Query::from('activity_log'),
            'email_log' => Query::from('email_log'),
            'course_instructors' => Query::from('course_instructors'),
            'availability_grants' => Query::from('availability_grants'),
            'credit_scheme_identities' => Query::from('credit_scheme_identities'),
            'grade_changes' => Query::from('grade_changes'),
            'grade_cells' => Query::from('grade_cells'),
            'notification_queue' => Query::from('notification_queue'),
            'notification_digest_items' => Query::from('notification_digest_items'),
            'notification_preferences' => Query::from('notification_preferences'),
            'notification_consents' => Query::from('notification_consents'),
            'notification_channel_addresses' => Query::from('notification_channel_addresses'),
            'learner_tags' => Query::from('learner_tags'),
            'org_learning_locks' => Query::from('org_learning_locks'),
            'automation_action_log' => Query::from('automation_action_log'),
            'automation_queue' => Query::from('automation_queue'),
            'automation_runs' => Query::from('automation_runs'),
            'ai_jobs' => Query::from('ai_jobs'),
            'report_schedules' => Query::from('report_schedules'),
            'compliance_assignments' => Query::from('compliance_assignments'),
            'assignment_exceptions' => Query::from('assignment_exceptions'),
            default => throw ValidationException::forField(
                'record_class',
                'Choose an available privacy record class.',
            ),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function article30(): array
    {
        $learner = [
            self::SUBJECT_CATEGORIES => ['learners'],
            self::RECIPIENTS => ['the learner', 'course instructors', 'site administrators'],
            self::LAWFUL_BASIS => 'contract; legitimate interests',
        ];

        $out = [];

        foreach (self::RECORD_CLASSES as $key => $definition) {
            $out[$key] = $learner + [
                self::PURPOSE => 'Delivering the course the learner is taking and evidencing what they completed.',
                self::DATA_CATEGORIES => ['identifiers', 'learning records'],
                self::TABLES => [$definition['table']],
            ];
        }

        $out['certificates'][self::RECIPIENTS] = ['the learner', 'anybody the learner shows the credential to'];
        $out['email_log'][self::DATA_CATEGORIES] = ['identifiers', 'delivery outcomes'];
        $out['course_instructors'][self::SUBJECT_CATEGORIES] = ['instructors'];

        return $out;
    }

    // ── The host's own subject request ───────────────────────────────────

    /**
     * The rows that hang off one of this person's own rows by its parent key.
     *
     * They are not record classes: a person is shown their attempts, and the
     * answers inside an attempt are part of that attempt rather than a
     * separate thing they hold. They are erased with the parent and before it,
     * because no foreign key exists to cascade and a child left behind is a row
     * whose owner can no longer be found.
     *
     * child table => [the record class it hangs off, the child's own column]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CHILD_RECORDS = [
        'attempt_answers' => ['attempts', 'attempt_id'],
        'attempt_questions' => ['attempts', 'attempt_id'],
        'attempt_answer_events' => ['attempts', 'attempt_id'],
        'integrity_events' => ['attempts', 'attempt_id'],
        'certificate_credits' => ['certificates', 'certificate_id'],
        'attempt_grades' => ['attempts', 'attempt_id'],
        'submission_files' => ['submissions', 'submission_id'],
        'course_progress' => ['enrolments', 'enrolment_id'],
        'ai_job_map' => ['ai_jobs', 'job_id'],
        'compliance_cycles' => ['compliance_assignments', 'assignment_id'],
        'org_notification_secrets' => ['notification_queue', 'queue_id'],
    ];

    /**
     * Third-party rows that keep their content and lose the subject's identity.
     *
     * table, column. Every one of them belongs to somebody else: a grade this
     * person awarded on another learner's attempt, an enrolment they granted
     * to somebody else, a course other learners are taking. Deleting any of
     * them would erase a third party's own record to satisfy this person's
     * request, which is not what the right asks for.
     *
     * The list lived twice, in a WordPress const and in five inline calls in
     * the Joomla plugin, and it lives here once for the reason the whole class
     * exists.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const ANONYMISED_REFERENCES = [
        ['attempt_grades', 'grader_id'],
        ['submissions', 'grader_id'],
        ['entitlements', 'granted_by'],
        ['courses', 'created_by'],
        ['import_jobs', 'created_by'],
        ['grade_cells', 'graded_by'],
        ['grade_changes', 'actor_id'],
        ['notification_consents', 'actor_user_id'],
        ['availability_grants', 'granted_by'],
        ['learner_tags', 'set_by'],
        ['automation_runs', 'actor_user_id'],
        ['automation_action_log', 'actor_user_id'],
        ['automation_rules', 'created_by'],
        ['automation_rules', 'armed_by'],
        ['automation_rule_versions', 'created_by'],
        ['automation_rule_tests', 'created_by'],
        ['automation_rule_actions', 'owner_user_id'],
        ['notification_rules', 'created_by'],
        ['notification_templates', 'draft_updated_by'],
        ['notification_templates', 'published_by'],
        ['course_credits', 'created_by'],
        ['course_certificate_policies', 'updated_by'],
        ['certificate_templates', 'published_by'],
        ['availability_rules', 'updated_by'],
        ['provider_settings', 'updated_by'],
        ['ai_routes', 'updated_by'],
        ['ai_policies', 'created_by'],
        ['ai_jobs', 'created_by'],
        ['report_schedules', 'created_by'],
        ['assignment_exceptions', 'resolved_by'],
    ];

    /**
     * Erase this person's own learning record, for the site's own subject
     * request.
     *
     * This is the direction {@see eraseWithOutcome()} refuses, and the
     * difference is the asker: an organisation may not erase a learner's own
     * course history, and the site that holds it may, because it is the
     * controller of the whole record.
     *
     * Children first, then the person's own rows, then the third-party rows
     * that lose only the identity. A failure on one table is recorded and the
     * rest still runs: an erasure that stopped half way through would leave a
     * person's record in a state nobody asked for and no operator can see.
     *
     * @return array{erased: array<string, int>, anonymised: array<string, int>, warnings: list<string>}
     */
    public function eraseSubjectRecords(int $userId, string $email): array
    {
        $files = new \Shondalai\VidyaLMS\Core\Application\Platform\PrivacyFileDeletion($this->database, $this->storage);
        try {
            $outcome = $this->database->transactional(function () use ($userId, $email, $files): array {
                $erased = [];
                $anonymised = [];
                if ($userId > 0) {
                    if ($this->storage instanceof \Shondalai\VidyaLMS\Core\Port\Storage\ListsLearnerUploads) {
                        foreach ($this->storage->learnerUploads($userId) as $path) {
                            if ($this->safeFile($path)) {
                                $files->enqueue($path, $userId);
                            }
                        }
                    }
                    // Claim each correlation before deleting access. Incrementing the
                    // revision makes an already-reading commerce worker fail its CAS.
                    foreach ($this->database->selectAll(Query::from('shop_access_links')->where('user_id', '=', $userId)) as $link) {
                        $changed = $this->database->update('shop_access_links', [
                            'user_id' => 0, 'entitlements' => '{}', 'last_reason' => 'privacy_erased',
                            'revision' => (int) $link['revision'] + 1,
                        ], ['id' => (int) $link['id'], 'revision' => (int) $link['revision']]);
                        if ($changed !== 1) {
                            throw new \RuntimeException('Shop access changed during erasure. Retry the request.');
                        }
                        $anonymised['shop_access_links'] = ($anonymised['shop_access_links'] ?? 0) + $changed;
                    }
                    foreach (self::CHILD_RECORDS as $child => [$parentClass, $column]) {
                        if ($parentClass === 'ai_jobs') {
                            continue; // Shared scheduled work survives its creator's erasure.
                        }
                        $parents = $this->database->selectAll($this->subjectQuery($parentClass, $userId)->select(['id']));
                        foreach ($parents as $parent) {
                            if ($child === 'submission_files') {
                                foreach ($this->database->selectAll(Query::from('submission_files')->where($column, '=', (int) $parent['id'])) as $file) {
                                    $path = (string) $file['path'];
                                    if (str_starts_with($path, 'submissions/' . $userId . '/') && $this->safeFile($path)) {
                                        $files->enqueue($path, $userId);
                                    }
                                }
                            }
                            $erased[$child] = ($erased[$child] ?? 0) + $this->database->delete($child, [$column => (int) $parent['id']]);
                        }
                    }
                    foreach ($this->database->selectAll(Query::from('certificates')->where('user_id', '=', $userId)) as $certificate) {
                        $id = (int) $certificate['id'];
                        \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCredentialStatusRepository::retireSubject(
                            $this->database, 'certificate', $id, new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                        );
                        $path = (string) ($certificate['artefact_path'] ?? '');
                        if (preg_match('#\Acertificates/' . $id . '(?:[./-])#', $path) && $this->safeFile($path)) {
                            $files->enqueue($path, $userId);
                        }
                    }
                    // Replies may contain a subject's work under another actor's
                    // key. Redact completed payloads without permitting the same
                    // mutation to run again after a privacy operation.
                    foreach ($this->database->selectAll(Query::from('idempotency_keys')->where('status', '=', 'completed')) as $cached) {
                        $previous = json_decode((string) $cached['outcome'], true);
                        $redacted = ['status' => 409, 'data' => ['detail' => 'The cached reply was cleared by a privacy request. Reload the resource.'],
                            'request_fingerprint' => is_array($previous) ? ($previous['request_fingerprint'] ?? null) : null];
                        $this->database->update('idempotency_keys', ['outcome' => json_encode($redacted, JSON_THROW_ON_ERROR)], ['id' => (int) $cached['id']]);
                    }
                    foreach (self::RECORD_CLASSES as $key => $definition) {
                        if (in_array($key, ['shop_access_links', 'ai_jobs', 'report_schedules'], true)) {
                            continue; // The anonymous suppression marker must survive.
                        }
                        if (in_array($key, ['automation_action_log', 'automation_queue', 'automation_runs', 'notification_queue', 'notification_digest_items'], true)) {
                            $erased[$key] = 0;
                            foreach ($this->database->selectAll($this->subjectQuery($key, $userId)->select(['id'])) as $record) {
                                $erased[$key] += $this->database->delete($definition['table'], ['id' => (int) $record['id']]);
                            }
                        } else {
                            $erased[$key] = $this->database->delete($definition['table'], [$definition['column'] => $userId]);
                        }
                    }
                    foreach (self::ANONYMISED_REFERENCES as [$table, $column]) {
                        $anonymised[$table . '.' . $column] = $this->database->update($table, [$column => 0], [$column => $userId]);
                    }
                }
                if ($email !== '') {
                    $erased['email_log'] = ($erased['email_log'] ?? 0) + $this->database->delete('email_log', ['recipient' => $email]);
                }
                return ['erased' => $erased, 'anonymised' => $anonymised, 'warnings' => []];
            });
            $outcome['warnings'] = $files->drain($userId);
            return $outcome;
        } catch (\Throwable $failure) {
            // A failed child deletion must never orphan its parent. Retain the
            // entire database graph so another request can retry the erasure.
            return ['erased' => [], 'anonymised' => [], 'warnings' => ['core-learning: ' . $failure->getMessage()]];
        }
    }

    private function safeFile(string $path): bool
    {
        try {
            \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety::downloadable($path);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
