import type { components } from '@vidyalms/api-sdk';
import { instructorStrings as strings } from '../strings';

/*
 * Archiving a module or a lesson, on the authoring side.
 *
 * Archiving is a state, not a delete: the row moves to "archived", leaves
 * the learner curriculum, and keeps every bit of progress recorded against
 * it. That is why the action is reversible and why the confirmation says
 * what it does rather than asking whether the author is sure.
 *
 * The consequence the author cannot see is the one that matters: removing
 * the last thing a cohort had not done finishes the course for all of them,
 * and their certificates arrive seconds later with nobody having touched
 * anything. The server says how many enrolments that covers and whether the
 * recompute is running, so this file turns those facts into the sentence the
 * banner shows. It used to read a "note" string the contract has never had,
 * which meant the banner said nothing and the three fields the wave was
 * built to deliver were dropped on the floor.
 */

export const ARCHIVED_STATE = 'archived';

/**
 * Where a restore puts the row back. The stored state is one column, so the
 * state it held before archiving is genuinely gone; restoring as a draft is
 * the only choice that invents nothing, and it cannot put a lesson back in
 * front of learners without the author saying so.
 */
export const RESTORED_STATE = 'draft';

/** The contract's own shape, never a copy of it. */
export type ArchiveOutcome = components['schemas']['ArchiveOutcome'];

export const RECOMPUTE_QUEUED = 'queued';

/**
 * The parameter is widened to string on purpose: the generated schema types
 * a lesson's state as the states that existed when it was generated, and a
 * comparison against a literal it does not know is a type error rather than
 * the false it should be.
 */
export function isArchived(entity: { state: string }): boolean {
    return entity.state === ARCHIVED_STATE;
}

export function isNotArchived(entity: { state: string }): boolean {
    return !isArchived(entity);
}

/**
 * What to tell the author, built from the outcome rather than from a
 * sentence the server never sends.
 *
 * Three cases, and the difference between them is what the author should
 * expect to happen next: nobody is enrolled and nothing else will happen; a
 * recompute is running over a known number of enrolments and certificates
 * may follow; or the entity was already archived and this changed nothing.
 */
export function archiveMessage(title: string, kind: 'module' | 'lesson', outcome: ArchiveOutcome): string {
    const copy = strings.curriculum.archive;
    const archived = kind === 'module' ? copy.archivedModule(title) : copy.archivedLesson(title);

    if (outcome.progress_recompute !== RECOMPUTE_QUEUED || outcome.enrolments_affected < 1) {
        return archived;
    }

    return `${archived} ${copy.recomputing(outcome.enrolments_affected)}`;
}
