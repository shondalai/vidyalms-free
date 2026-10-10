<?php
/**
 * @package     VidyaLMS
 * @subpackage  plg_privacy_vidyalms
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Plugin\Privacy\VidyaLms\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Event\Privacy\ExportRequestEvent;
use Joomla\CMS\Event\Privacy\RemoveDataEvent;
use Joomla\CMS\Event\Privacy\CanRemoveDataEvent;
use Joomla\Component\Privacy\Administrator\Removal\Status;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Component\Privacy\Administrator\Plugin\PrivacyPlugin;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;

/**
 * GDPR export and erasure for VidyaLMS, free on purpose and complete on
 * purpose: everything the core stores about a learner is exportable and
 * erasable.
 *
 * Reads and writes go through the core's DatabasePort when com_vidyalms has
 * registered it, so table naming and value handling stay in one place. When the
 * component is absent (a privacy request served on a site mid-uninstall) the
 * plugin falls back to the Joomla driver over the same "#__vidyalms_" tables.
 *
 * Which tables, and who decides
 * -----------------------------
 * Not this file, any more. It used to hold an EXPORT_BY_USER array of eight
 * table, domain key, language key and column tuples, and a second copy of the
 * same list inline in the erasure routine, and the WordPress adapter held a
 * third and fourth copy of both. Adding a table to the core meant editing two
 * hosts and a language file in the same release, and the day one was missed an
 * export and an erasure would have disagreed about what the site holds.
 *
 * The list is the core's privacy contributor registry now, and both hosts
 * iterate it: the export reads subjectGroups() and the erasure calls
 * eraseSubjectRecords(), so a table added to the core reaches both with no
 * adapter edit and no new language key. What stays here is what the registry
 * cannot express: domains keyed on a parent row's id rather than on a person,
 * and delivery rows addressed to somebody who has no account.
 *
 * What erasure deletes, and what it anonymises instead
 * ----------------------------------------------------
 * Deleted outright, because the row exists only to describe this person:
 * entitlements, enrolments, lesson and course progress, video positions, quiz
 * attempts with their answers and grades, assignment submissions with their
 * file records, certificates, activity log entries and email log entries.
 *
 * Anonymised, because the row belongs to somebody else and deleting it would
 * destroy that third party's record or a shared aggregate:
 *
 *   attempt_grades.grader_id   a grade the subject awarded on another learner's
 *                              attempt. The learner keeps their result; only the
 *                              grader's identity is dropped.
 *   submissions.grader_id      the same, for assignment grading.
 *   entitlements.granted_by    the subject enrolled somebody else. That person
 *                              keeps their access; the granting identity goes.
 *   courses.created_by         the subject authored a course other learners are
 *                              enrolled on. Deleting the course would erase
 *                              every one of their enrolments and results.
 *   import_jobs.created_by     an audit row for a bulk import that shaped
 *                              content other people still use.
 *
 * Course instructor assignments are the subject's own link to a course and are
 * deleted, not anonymised: removing them takes the person off the course
 * without touching anything a learner owns.
 *
 * Submission files are recorded as rows here and removed with them. The stored
 * file bytes live behind the Storage port and are removed by the component's
 * own retention, not by this plugin, which never touches the filesystem.
 *
 * @since 1.0.0
 */
final class VidyaLms extends PrivacyPlugin implements SubscriberInterface
{
    /**
     * The contributor group whose rows this plugin's one option can switch off.
     *
     * It is a record class key and not a table list. The option is a site
     * owner's switch over one named thing, which is a different kind of
     * statement from "here are the tables VidyaLMS holds": that list used to
     * live here, in an EXPORT_BY_USER array of eight tuples with a second copy
     * of it in the erasure routine, and it now lives once in the core's own
     * privacy contributor, which both hosts iterate.
     */
    private const EMAIL_LOG_GROUP = 'vidyalms-email-log';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPrivacyCanRemoveData' => 'onPrivacyCanRemoveData',
            'onPrivacyExportRequest' => 'onPrivacyExportRequest',
            'onPrivacyRemoveData'    => 'onPrivacyRemoveData',
        ];
    }

    // Export

    /**
     * Collect every VidyaLMS record tied to the subject. Learning data is keyed
     * on the CMS user id, so an email-only request matches nothing beyond the
     * email log, which stores the address it delivered to.
     */
    public function onPrivacyExportRequest(ExportRequestEvent $event): void
    {
        $user   = $event->getUser();
        $userId = $user && $user->id ? (int) $user->id : 0;
        $email  = (string) ($event->getRequest()->email ?? '');

        $domains = [];
        $exportEmailLog = (bool) $this->params->get('export_email_log', 1);

        if ($userId > 0) {
            // The rows keyed on the subject's own account are the contributor
            // registry's answer below. What stays here is the set it cannot
            // express: rows keyed on a parent row's id rather than on a person,
            // which is a different question and not a table list.
            $domains[] = $this->domainFrom(
                'vidyalms_course_progress',
                'PLG_PRIVACY_VIDYALMS_DOMAIN_COURSE_PROGRESS',
                $this->rowsIn('course_progress', 'enrolment_id', $this->enrolmentIds($userId))
            );

            $attemptIds = $this->attemptIds($userId);

            $domains[] = $this->domainFrom(
                'vidyalms_attempt_answers',
                'PLG_PRIVACY_VIDYALMS_DOMAIN_ATTEMPT_ANSWERS',
                $this->rowsIn('attempt_answers', 'attempt_id', $attemptIds)
            );
            $domains[] = $this->domainFrom(
                'vidyalms_attempt_grades',
                'PLG_PRIVACY_VIDYALMS_DOMAIN_ATTEMPT_GRADES',
                $this->rowsIn('attempt_grades', 'attempt_id', $attemptIds)
            );
            $domains[] = $this->domainFrom(
                'vidyalms_submission_files',
                'PLG_PRIVACY_VIDYALMS_DOMAIN_SUBMISSION_FILES',
                $this->rowsIn('submission_files', 'submission_id', $this->submissionIds($userId))
            );
        }

        if ($exportEmailLog && $email !== '') {
            // Mail addressed to the person before or after they had an account.
            // Restricted to rows with no user id so a row the contributor's own
            // email log group already carries cannot appear twice in one
            // export, which is exactly what happened while both lists existed.
            $domains[] = $this->domainFrom(
                'vidyalms_email_log_address',
                'PLG_PRIVACY_VIDYALMS_DOMAIN_EMAIL_LOG_ADDRESS',
                $this->addressOnlyEmailLogRows($email)
            );
        }

        $contributors = $this->contributors();
        $ports = $this->ports();
        if ($contributors !== null && $ports !== null) {
            foreach ($contributors->groups($userId, $email) as $group) {
                if (!$exportEmailLog && $group->key === self::EMAIL_LOG_GROUP) {
                    continue;
                }

                $domain = $this->createDomain($group->key, $group->label);
                $offset = 0;
                do {
                    $rows = $ports->database->selectAll(($group->query)()->orderBy('id')->limit(200)->offset($offset));
                    foreach ($rows as $row) {
                        $domain->addItem($this->createItemFromArray($row));
                    }
                    $offset += 200;
                } while (\count($rows) === 200);
                $domains[] = $domain;
            }
        }
        $missing = $contributors?->missing() ?? ['core-organisations'];
        if ($missing !== []) {
            $domains[] = $this->domainFrom('vidyalms_privacy_incomplete', 'PLG_PRIVACY_VIDYALMS_EXPORT_INCOMPLETE',
                [['missing_contributors' => implode(', ', $missing)]]);
        }

        $event->addResult($domains);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function domainFrom(string $key, string $labelKey, array $rows): object
    {
        $domain = $this->createDomain($key, Text::_($labelKey));

        foreach ($rows as $row) {
            $domain->addItem($this->createItemFromArray($row));
        }

        return $domain;
    }

    /**
     * Delivery rows addressed to this person with no account behind them.
     *
     * The account-keyed half is the contributor's, so this is only the half no
     * user id can reach: mail sent before the person registered, or after their
     * account was removed. Restricting on a null user id is what keeps a row
     * from appearing in two domains of one export.
     *
     * @return list<array<string, mixed>>
     */
    private function addressOnlyEmailLogRows(string $email): array
    {
        $ports = $this->ports();

        try {
            if ($ports !== null) {
                return $ports->database->selectAll(
                    Query::from('email_log')->where('recipient', '=', $email)->whereNull('user_id')
                );
            }

            $db    = $this->getDatabase();
            $query = $db->createQuery()
                ->select('*')
                ->from($db->quoteName($this->physical('email_log')))
                ->where($db->quoteName('recipient') . ' = :value')
                ->where($db->quoteName('user_id') . ' IS NULL')
                ->bind(':value', $email, ParameterType::STRING);

            return array_values((array) $db->setQuery($query)->loadAssocList());
        } catch (\Throwable $e) {
            $this->logFailure('read', 'email_log', $e);

            return [];
        }
    }

    // Erasure

    /**
     * Remove the subject's learning record, then strip their identity from the
     * rows that belong to other people. See the class docblock for exactly what
     * falls on each side of that line and why.
     */
    public function onPrivacyCanRemoveData(CanRemoveDataEvent $event): void
    {
        $user = $event->getUser();
        $userId = $user && $user->id ? (int) $user->id : 0;
        $email = (string) ($event->getRequest()->email ?? '');
        $status = new Status();
        $contributors = $this->contributors();
        if ($contributors === null || $contributors->requiresReview($userId, $email)) {
            $status->canRemove = false;
            $status->reason = Text::_('PLG_PRIVACY_VIDYALMS_ERASURE_REVIEW');
        }
        $event->addResult($status);
    }

    public function onPrivacyRemoveData(RemoveDataEvent $event): void
    {
        $user   = $event->getUser();
        $userId = $user && $user->id ? (int) $user->id : 0;
        $email  = (string) ($event->getRequest()->email ?? '');

        $contributors = $this->contributors();
        if ($contributors === null || $contributors->requiresReview($userId, $email)) {
            throw new \RuntimeException(Text::_('PLG_PRIVACY_VIDYALMS_ERASURE_REVIEW'));
        }

        // Every table, from the registry, in one call. This routine used to
        // hold a second copy of the export list plus five anonymisation calls
        // and four child deletions, so a table added to the core meant editing
        // this host and the WordPress one, in the same release, or an export
        // and an erasure quietly stopped agreeing about what the site holds.
        $outcome = $contributors->eraseSubjectRecords($userId, $email);

        foreach ($outcome['warnings'] as $warning) {
            Log::add('plg_privacy_vidyalms erase: ' . $warning, Log::WARNING, 'com_privacy');
        }

        if ($outcome['warnings'] !== []) {
            // Joomla marks the request complete if every listener returns normally.
            // Keep raw contributor/database details in the log, not the admin HTML.
            throw new \RuntimeException(Text::_('PLG_PRIVACY_VIDYALMS_ERASURE_INCOMPLETE'));
        }
    }

    // Row access: the port when it is there, the driver when it is not

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsBy(string $table, string $column, int|string $value): array
    {
        $ports = $this->ports();

        try {
            if ($ports !== null) {
                return $ports->database->selectAll(Query::from($table)->where($column, '=', $value));
            }

            $db    = $this->getDatabase();
            $query = $db->createQuery()
                ->select('*')
                ->from($db->quoteName($this->physical($table)))
                ->where($db->quoteName($column) . ' = :value')
                ->bind(':value', $value, \is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);

            return array_values((array) $db->setQuery($query)->loadAssocList());
        } catch (\Throwable $e) {
            $this->logFailure('read', $table, $e);

            return [];
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function rowsIn(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ports = $this->ports();

        try {
            if ($ports !== null) {
                return $ports->database->selectAll(Query::from($table)->whereIn($column, $ids));
            }

            $db    = $this->getDatabase();
            $query = $db->createQuery()
                ->select('*')
                ->from($db->quoteName($this->physical($table)))
                ->whereIn($db->quoteName($column), $ids);

            return array_values((array) $db->setQuery($query)->loadAssocList());
        } catch (\Throwable $e) {
            $this->logFailure('read', $table, $e);

            return [];
        }
    }

    // Id sets

    /**
     * @return list<int>
     */
    private function attemptIds(int $userId): array
    {
        return $this->idsOf($this->rowsBy('attempts', 'user_id', $userId));
    }

    /**
     * @return list<int>
     */
    private function submissionIds(int $userId): array
    {
        return $this->idsOf($this->rowsBy('submissions', 'user_id', $userId));
    }

    /**
     * @return list<int>
     */
    private function enrolmentIds(int $userId): array
    {
        return $this->idsOf($this->rowsBy('enrolments', 'user_id', $userId));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private function idsOf(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // Plumbing

    private function contributors(): ?\Shondalai\VidyaLMS\Core\Application\Organisation\SubjectOrganisationPrivacy
    {
        try {
            $kernel = Factory::getContainer()->get(\Shondalai\VidyaLMS\Core\Boot\CoreKernel::class);
            $ports = $this->ports();
            if ($kernel instanceof \Shondalai\VidyaLMS\Core\Boot\CoreKernel && $ports !== null) {
                return new \Shondalai\VidyaLMS\Core\Application\Organisation\SubjectOrganisationPrivacy(
                    $kernel->registries->privacyContributors, $ports->database
                );
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    private function ports(): ?Ports
    {
        try {
            $ports = Factory::getContainer()->get(Ports::class);
        } catch (\Throwable) {
            return null;
        }

        return $ports instanceof Ports ? $ports : null;
    }

    private function physical(string $logicalTable): string
    {
        return '#__vidyalms_' . $logicalTable;
    }

    /** Surface failures in the standard privacy log channel, never silently. */
    private function logFailure(string $operation, string $table, \Throwable $e): void
    {
        Log::add(
            sprintf('plg_privacy_vidyalms %s(%s) failed: %s', $operation, $table, $e->getMessage()),
            Log::WARNING,
            'com_privacy'
        );
    }
}
