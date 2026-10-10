<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * The draft lifecycle, as a domain state machine rather than a form flag.
 *
 * Six states, with rejected expressed as a transition back to Generated rather
 * than as a seventh: a rejected draft that could not be regenerated would be a
 * discard wearing a different word, and the reject reason CODE is what later
 * tunes the critic, which is why the reason is a code plus free text rather
 * than free text alone. A free-text column cannot be counted.
 *
 * **Nothing transitions to a published domain row except through Accepted, and
 * only a human Principal may write Accepted.** That rule lives in the accept
 * use case, and this enum is what makes the rule expressible: a boolean
 * "published" flag would put the same rule in every reader.
 *
 * A draft is invisible to learners by construction and not by a flag check. A
 * generated definition contains correct answers by construction, so a draft is
 * not written into the questions table at all: it lives in ai_drafts, which is
 * reachable only under TEACH on the owning course or MANAGE at site scope.
 */
enum AiDraftState: string
{
    /** A unit a job has claimed and not yet produced. */
    case Requested = 'requested';

    case Generated = 'generated';

    /** The deterministic checks have run over it. */
    case Checked = 'checked';

    case InReview = 'in_review';
    case Accepted = 'accepted';
    case Discarded = 'discarded';

    /**
     * Whether this state may become that one.
     *
     * Accepted and Discarded are terminal: an accepted draft is undone by the
     * normal path for its entity and never by moving the draft backwards, which
     * is the rule RecallAiRun is built on.
     */
    public function canBecome(self $next): bool
    {
        return \in_array($next, $this->successors(), true);
    }

    /**
     * @return list<self>
     */
    public function successors(): array
    {
        return match ($this) {
            self::Requested => [self::Generated, self::Discarded],
            // Accepted is reachable from Generated and Checked as well as from
            // InReview, and that is a decision rather than an omission. The state
            // machine's job is that nothing becomes a published domain row except
            // through Accepted; how much friction the accept step carries is the
            // risk tier's job, and a low-tier draft an author accepts straight
            // from the queue is a real workflow. Forcing every draft through
            // InReview first would have made the review state a formality every
            // surface ticks on the way past, which is worse than not having it:
            // the state is worth recording precisely when a reviewer genuinely
            // opened the draft, because that is the human step the oversight
            // metric measures.
            self::Generated => [self::Checked, self::InReview, self::Accepted, self::Discarded],
            self::Checked => [self::InReview, self::Accepted, self::Generated, self::Discarded],
            // A reject returns the draft to Generated with its reason code, so
            // the same run can produce a better payload under the same review.
            self::InReview => [self::Accepted, self::Generated, self::Discarded],
            self::Accepted, self::Discarded => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->successors() === [];
    }

    /**
     * Whether a draft in this state may still be purged with its payload.
     *
     * Accepted drafts are purged too, at the same ninety days: the artefact now
     * lives in its own table with its own history, so the draft payload has no
     * remaining purpose while the editorial-responsibility record does. The
     * provenance row is what survives, and it is excluded from the purge.
     */
    public function isPurgeable(): bool
    {
        return true;
    }
}
