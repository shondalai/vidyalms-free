<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Scheduler;

/**
 * The one thing a host drain and the operations surface have to agree on when
 * a queued job names a task type nothing is registered for.
 *
 * The direction the standards page publishes is that such a job is left
 * pending and reported as unresolvable, never marked failed. A stored job key
 * that resolves to nothing means the package is missing, not that the work
 * failed, and marking the row failed destroys work that reinstalling the
 * package would have completed. It is the same direction the Importer registry
 * already follows, where RunQueuedImport catches the registry miss and
 * ImportRunner::reportUnresolvedImporter appends a note and leaves the status
 * alone.
 *
 * Two halves live on two sides of the port, so the sentence they share lives
 * here rather than being written twice and drifting:
 *
 *   - a drain (plg_task_vidyalms on Joomla, QueueRunner on WordPress) stores
 *     note() in its own queue row's error column and leaves the row pending
 *   - DatabaseScheduledTaskInspector, which both hosts read their queue
 *     through, recognises a row stamped that way and answers diagnostic() on
 *     it, so InspectOperations reports why the job has been sitting there
 *
 * The marker is what makes the second half possible. A requeued job after a
 * transient failure is also pending and also carries an error, so a pending
 * row with an error in it is not on its own an unresolvable job; the marker
 * says which of the two this is, and it is a prefix rather than a separate
 * column because the queue table belongs to the adapter on each host and a
 * column added to one of them would have to be added to the other and
 * migrated on both, for a fact that is already in the row.
 *
 * What reaches a caller is diagnostic(), never the stored note and never the
 * raw error of anything else. InspectsScheduledTasks promises to expose no
 * payload and no raw error, and diagnostic() names only the task type, which
 * the same row already publishes.
 */
final class UnresolvedTaskType
{
    /**
     * Deliberately namespaced and deliberately not a word an error message
     * would produce by accident, so a genuine failure whose text happens to
     * mention a task type cannot be read as this.
     */
    public const MARKER = 'vidyalms:unresolved-task-type';

    /** A value object of static helpers; there is nothing to construct. */
    private function __construct()
    {
    }

    /**
     * What a drain stores in its queue row when the type resolves to nothing.
     *
     * The marker leads and the sentence follows, so a site owner reading the
     * row in their own database sees the explanation without needing this
     * class to interpret it for them.
     */
    public static function note(string $type): string
    {
        return self::MARKER . ' ' . self::diagnostic($type);
    }

    /**
     * True when a stored queue error was written by note().
     *
     * Null and the empty string are both "no error stored", which is the
     * ordinary case for a job that has never run.
     */
    public static function isUnresolved(?string $storedError): bool
    {
        return \is_string($storedError) && str_starts_with($storedError, self::MARKER);
    }

    /**
     * What the operations surface says about such a job.
     *
     * One sentence for the state and one for the remedy, because the whole
     * point of leaving the row pending is that an operator can fix it: the
     * work has not been lost and installing the missing package and letting
     * the drain come round again does it.
     */
    public static function diagnostic(string $type): string
    {
        return sprintf(
            'Nothing registered answers to the task type "%s", so this job has not run and is still'
                . ' waiting. It is deliberately not marked failed: the usual cause is an extension that'
                . ' is not installed or not enabled, and it will run on its own once it is.',
            $type,
        );
    }
}
