<?php

declare(strict_types=1);

/**
 * VidyaLMS: the WordPress personal data exporter.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Privacy;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Everything VidyaLMS stores about a learner, in the shape WordPress Tools >
 * Export Personal Data expects. Free on purpose and complete on purpose: an
 * export that omits a table is a compliance bug, not a missing feature.
 *
 * Reads go through the core DatabasePort, never through $wpdb, so logical
 * table names, prefixing and value handling stay in one place and the export
 * matches what the Joomla privacy plugin produces from the same schema.
 *
 * Pagination. WordPress drives the exporter one page at a time and stops when
 * the callback answers done. The groups below are a fixed, ordered list, so
 * page N addresses a deterministic (group, offset) pair: each page counts every
 * group, works out which group owns that page, and returns at most PER_PAGE
 * rows of it. Ordering is always by primary key, so a row cannot be skipped or
 * repeated between two pages of the same run.
 *
 * Columns held back, and why:
 *   submission_files.path   the private storage location of the learner's own
 *                           upload. The filename, size and type are exported;
 *                           the server path is infrastructure, not their data.
 *   certificates.artefact_path  the same, for a rendered certificate. The
 *                           verification code, which is the useful thing, is
 *                           exported.
 *   email_log.dedupe_key    an internal SHA-256 with no meaning to a person.
 */
final class Exporter
{
    /**
     * The key WordPress files this exporter under. Stable across releases: a
     * rename would strand in-flight export requests.
     */
    public const EXPORTER_KEY = 'vidyalms';

    /**
     * Rows per page. Large enough that a normal learner exports in one or two
     * pages, small enough that a heavy activity log cannot exhaust memory.
     */
    private const PER_PAGE = 200;

    /** @var list<string> columns held back from every group */
    private const NEVER_EXPORT = ['dedupe_key'];

    private ?\Closure $portsResolver;

    /**
     * @param callable(): Ports|null $portsResolver the plugin bootstrap's way to
     *                                              the one shared ports bundle
     */
    public function __construct(?callable $portsResolver = null)
    {
        $this->portsResolver = $portsResolver === null ? null : \Closure::fromCallable($portsResolver);
    }

    /**
     * @param callable(): Ports|null $portsResolver
     */
    public static function boot(?callable $portsResolver = null): self
    {
        $exporter = new self($portsResolver);

        add_filter('wp_privacy_personal_data_exporters', [$exporter, 'register']);

        return $exporter;
    }

    /**
     * @param mixed $exporters
     *
     * @return array<string, array{exporter_friendly_name: string, callback: callable}>
     */
    public function register($exporters): array
    {
        $registered = \is_array($exporters) ? $exporters : [];

        $registered[self::EXPORTER_KEY] = [
            'exporter_friendly_name' => __('VidyaLMS learning record', 'vidyalms'),
            'callback'               => [$this, 'export'],
        ];
        $registered['vidyalms-commerce'] = [
            'exporter_friendly_name' => __('VidyaLMS shop access', 'vidyalms'),
            'callback' => [\Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::class, 'exportCustomerAccess'],
        ];

        return $registered;
    }

    /**
     * The exporter callback. WordPress checks the erase and export capability
     * and the request nonce before it reaches this point, and calls it once per
     * page until done is true.
     *
     * @param mixed $emailAddress
     * @param mixed $page
     *
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function export($emailAddress, $page = 1): array
    {
        $email = \is_string($emailAddress) ? $emailAddress : '';
        $page  = max(1, (int) $page);

        $database = $this->database();

        if ($database === null) {
            // Without the core there is nothing to read. Answering done leaves
            // the other exporters on the site free to finish their own pages.
            return ['data' => [], 'done' => true];
        }

        $user   = $email === '' ? false : get_user_by('email', $email);
        $userId = $user instanceof \WP_User ? (int) $user->ID : 0;

        $groups = $this->groups($database, $userId, $email);
        $contributors = Contributors::resolve($database);
        foreach ($contributors->groups($userId, $email) as $group) {
            $groups[] = $this->group($group->key, $group->label, $group->label, $group->key, $group->query);
        }
        $notices = $page === 1 && $contributors->missing() !== [] ? [[
            'group_id' => 'vidyalms-privacy-notices', 'group_label' => __('VidyaLMS export completeness', 'vidyalms'),
            'item_id' => 'vidyalms-missing-contributors', 'data' => [[
                'name' => __('Incomplete export', 'vidyalms'),
                'value' => esc_html(implode(', ', $contributors->missing())),
            ]],
        ]] : [];

        $total  = 0;
        $counts = [];

        foreach ($groups as $index => $group) {
            $rows           = $database->count(($group['query'])());
            $pages          = intdiv($rows + self::PER_PAGE - 1, self::PER_PAGE);
            $counts[$index] = $pages;
            $total         += $pages;
        }

        if ($page > $total) {
            return ['data' => $notices, 'done' => true];
        }

        $cursor = 0;

        foreach ($groups as $index => $group) {
            $pages = $counts[$index];

            if ($pages === 0 || $page > $cursor + $pages) {
                $cursor += $pages;
                continue;
            }

            $offset = ($page - $cursor - 1) * self::PER_PAGE;

            $rows = $database->selectAll(
                ($group['query'])()->orderBy('id')->limit(self::PER_PAGE)->offset($offset),
            );

            $data = [];

            foreach ($rows as $row) {
                $data[] = $this->itemFrom($group, $row);
            }

            return ['data' => [...$notices, ...$data], 'done' => $page >= $total];
        }

        return ['data' => [], 'done' => true];
    }

    /**
     * The export plan: an ordered list of groups, each with a closure that
     * builds a fresh query. A closure and not a query object, because Query is
     * mutable and the same group is compiled twice per page, once to count and
     * once to read.
     *
     * **What is not here any more.** Every group keyed on the subject's own
     * account used to be written out here as well, and once the core registered
     * a learning privacy contributor those nine groups were produced twice and
     * every row of them appeared twice in one export. The contributor is the
     * one register of what the core holds about a person, so what stays here is
     * what it cannot express: groups keyed on a parent row's id rather than on
     * a person, and delivery rows addressed to somebody with no account.
     *
     * @return list<array{id: string, label: string, description: string, item: string, skip: list<string>, query: \Closure(): Query}>
     */
    private function groups(DatabasePort $database, int $userId, string $email): array
    {
        $groups = [];

        if ($userId > 0) {
            $enrolmentIds = $this->idsOf($database, 'enrolments', 'user_id', $userId);

            if ($enrolmentIds !== []) {
                $groups[] = $this->group(
                    'vidyalms-course-progress',
                    __('VidyaLMS course progress', 'vidyalms'),
                    __('The computed completion summary of each course this person is taking.', 'vidyalms'),
                    'vidyalms-course-progress',
                    static fn (): Query => Query::from('course_progress')->whereIn('enrolment_id', $enrolmentIds),
                );
            }

            $attemptIds = $this->idsOf($database, 'attempts', 'user_id', $userId);

            if ($attemptIds !== []) {
                $groups[] = $this->group(
                    'vidyalms-attempt-answers',
                    __('VidyaLMS quiz answers', 'vidyalms'),
                    __('The answers this person gave in each quiz attempt.', 'vidyalms'),
                    'vidyalms-attempt-answer',
                    static fn (): Query => Query::from('attempt_answers')->whereIn('attempt_id', $attemptIds),
                );

                $groups[] = $this->group(
                    'vidyalms-attempt-grades',
                    __('VidyaLMS quiz grades', 'vidyalms'),
                    __('Points awarded and feedback left on this person\'s quiz attempts.', 'vidyalms'),
                    'vidyalms-attempt-grade',
                    static fn (): Query => Query::from('attempt_grades')->whereIn('attempt_id', $attemptIds),
                );
            }

            $submissionIds = $this->idsOf($database, 'submissions', 'user_id', $userId);

            if ($submissionIds !== []) {
                $groups[] = $this->group(
                    'vidyalms-submission-files',
                    __('VidyaLMS assignment files', 'vidyalms'),
                    __('The files attached to assignment submissions.', 'vidyalms'),
                    'vidyalms-submission-file',
                    static fn (): Query => Query::from('submission_files')->whereIn('submission_id', $submissionIds),
                    ['path'],
                );
            }
        }

        if ($email !== '') {
            // Mail addressed to the person before or after they had an account.
            // Restricted to rows with no user id so a row the core contributor
            // already carries cannot appear twice in one export.
            $groups[] = $this->group(
                'vidyalms-email-log-address',
                __('VidyaLMS email log (by address)', 'vidyalms'),
                __('Course emails sent to this address without a matching account.', 'vidyalms'),
                'vidyalms-email-address',
                static fn (): Query => Query::from('email_log')
                    ->where('recipient', '=', $email)
                    ->whereNull('user_id'),
            );
        }

        return $groups;
    }

    /**
     * @param \Closure(): Query $query
     * @param list<string>      $skip
     *
     * @return array{id: string, label: string, description: string, item: string, skip: list<string>, query: \Closure(): Query}
     */
    private function group(
        string $id,
        string $label,
        string $description,
        string $item,
        \Closure $query,
        array $skip = []
    ): array {
        return [
            'id'          => $id,
            'label'       => $label,
            'description' => $description,
            'item'        => $item,
            'skip'        => $skip,
            'query'       => $query,
        ];
    }

    /**
     * @param array{id: string, label: string, description: string, item: string, skip: list<string>, query: \Closure(): Query} $group
     * @param array<string, mixed>                                                                                             $row
     *
     * @return array<string, mixed>
     */
    private function itemFrom(array $group, array $row): array
    {
        $fields = [];

        foreach ($row as $column => $value) {
            $name = (string) $column;

            if ($value === null || \in_array($name, self::NEVER_EXPORT, true) || \in_array($name, $group['skip'], true)) {
                continue;
            }

            $fields[] = [
                'name'  => $this->columnLabel($name),
                'value' => esc_html($this->stringify($value)),
            ];
        }

        return [
            'group_id'          => $group['id'],
            'group_label'       => $group['label'],
            'group_description' => $group['description'],
            'item_id'           => $group['item'] . '-' . (int) ($row['id'] ?? 0),
            'data'              => $fields,
        ];
    }

    /**
     * Primary keys of the rows a child group hangs off. Read in one query and
     * reused, because the same set answers several groups.
     *
     * @return list<int>
     */
    private function idsOf(DatabasePort $database, string $table, string $column, int $value): array
    {
        $ids = [];

        foreach ($database->selectAll(Query::from($table)->select(['id'])->where($column, '=', $value)) as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function stringify(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * Column names are shared across tables, so one map covers every group. A
     * column with no entry exports under its own name, which is a technical
     * identifier rather than a sentence and needs no translation.
     */
    private function columnLabel(string $column): string
    {
        switch ($column) {
            case 'id':
                return __('Record ID', 'vidyalms');
            case 'user_id':
                return __('User ID', 'vidyalms');
            case 'course_id':
                return __('Course ID', 'vidyalms');
            case 'lesson_id':
                return __('Lesson ID', 'vidyalms');
            case 'module_id':
                return __('Module ID', 'vidyalms');
            case 'quiz_id':
                return __('Quiz ID', 'vidyalms');
            case 'question_id':
                return __('Question ID', 'vidyalms');
            case 'assignment_id':
                return __('Assignment ID', 'vidyalms');
            case 'submission_id':
                return __('Submission ID', 'vidyalms');
            case 'attempt_id':
                return __('Attempt ID', 'vidyalms');
            case 'enrolment_id':
                return __('Enrolment ID', 'vidyalms');
            case 'entitlement_id':
                return __('Access record ID', 'vidyalms');
            case 'template_id':
                return __('Certificate template ID', 'vidyalms');
            case 'grader_id':
                return __('Graded by (user ID)', 'vidyalms');
            case 'granted_by':
                return __('Granted by (user ID)', 'vidyalms');
            case 'source':
                return __('Access source', 'vidyalms');
            case 'source_ref':
                return __('Access reference', 'vidyalms');
            case 'status':
                return __('Status', 'vidyalms');
            case 'action':
                return __('Action', 'vidyalms');
            case 'context':
                return __('Details', 'vidyalms');
            case 'starts_at':
                return __('Starts', 'vidyalms');
            case 'expires_at':
                return __('Expires', 'vidyalms');
            case 'enrolled_at':
                return __('Enrolled', 'vidyalms');
            case 'completed_at':
                return __('Completed', 'vidyalms');
            case 'last_activity_at':
                return __('Last activity', 'vidyalms');
            case 'first_accessed_at':
                return __('First opened', 'vidyalms');
            case 'last_heartbeat_at':
                return __('Last seen', 'vidyalms');
            case 'occurred_at':
                return __('Occurred', 'vidyalms');
            case 'computed_at':
                return __('Computed', 'vidyalms');
            case 'started_at':
                return __('Started', 'vidyalms');
            case 'submitted_at':
                return __('Submitted', 'vidyalms');
            case 'answered_at':
                return __('Answered', 'vidyalms');
            case 'graded_at':
                return __('Graded', 'vidyalms');
            case 'issued_at':
                return __('Issued', 'vidyalms');
            case 'revoked_at':
                return __('Revoked', 'vidyalms');
            case 'sent_at':
                return __('Sent', 'vidyalms');
            case 'created_at':
                return __('Created', 'vidyalms');
            case 'updated_at':
                return __('Updated', 'vidyalms');
            case 'progress_percent':
            case 'percent':
                return __('Progress (percent)', 'vidyalms');
            case 'percentage':
                return __('Score (percent)', 'vidyalms');
            case 'lessons_total':
                return __('Lessons in course', 'vidyalms');
            case 'lessons_completed':
                return __('Lessons completed', 'vidyalms');
            case 'quiz_average':
                return __('Quiz average', 'vidyalms');
            case 'final_quiz_passed':
                return __('Final quiz passed', 'vidyalms');
            case 'seconds_spent':
                return __('Time spent (seconds)', 'vidyalms');
            case 'time_spent_seconds':
                return __('Time taken (seconds)', 'vidyalms');
            case 'position_seconds':
                return __('Playback position (seconds)', 'vidyalms');
            case 'duration_seconds':
                return __('Duration (seconds)', 'vidyalms');
            case 'attempt_number':
                return __('Attempt number', 'vidyalms');
            case 'score':
                return __('Score', 'vidyalms');
            case 'max_score':
                return __('Maximum score', 'vidyalms');
            case 'points_awarded':
                return __('Points awarded', 'vidyalms');
            case 'max_points':
                return __('Maximum points', 'vidyalms');
            case 'passed':
                return __('Passed', 'vidyalms');
            case 'answer':
                return __('Answer', 'vidyalms');
            case 'feedback':
                return __('Feedback', 'vidyalms');
            case 'text_response':
                return __('Written response', 'vidyalms');
            case 'filename':
                return __('File name', 'vidyalms');
            case 'size_bytes':
                return __('File size (bytes)', 'vidyalms');
            case 'mime':
                return __('File type', 'vidyalms');
            case 'code':
                return __('Verification code', 'vidyalms');
            case 'metadata':
                return __('Certificate details', 'vidyalms');
            case 'template_key':
                return __('Email template', 'vidyalms');
            case 'recipient':
                return __('Sent to', 'vidyalms');
            case 'error':
                return __('Delivery error', 'vidyalms');
            case 'event_ref':
                return __('Event reference', 'vidyalms');
            default:
                return $column;
        }
    }

    private function database(): ?DatabasePort
    {
        $ports = $this->ports();

        return $ports === null ? null : $ports->database;
    }

    private function ports(): ?Ports
    {
        if ($this->portsResolver !== null) {
            $ports = ($this->portsResolver)();

            return $ports instanceof Ports ? $ports : null;
        }

        if (function_exists('vidyalms_ports')) {
            $ports = vidyalms_ports();

            return $ports instanceof Ports ? $ports : null;
        }

        return null;
    }
}
