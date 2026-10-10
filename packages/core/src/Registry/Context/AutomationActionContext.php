<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Automation\CascadeChain;

/**
 * What an automation action may read while it runs, and the object that
 * carries the one key it may not compute for itself.
 *
 * **The canonical idempotency digest is computed in the core and carried here
 * as a readonly member.** An earlier shape put `idempotencyKeyFor()` on the
 * action contract with no constraint on what a provider might return, and a
 * third-party action answering a constant, or a key omitting the subject,
 * claims one `idempotency_keys` row under the unique `(scope, idem_key)`. The
 * runner then replays the first outcome verbatim, so a fan-out of four
 * hundred enrolments executes one and records 399 replays as executed, each
 * with its own action-log row and success = 1. The failure reads as a clean
 * run, which is exactly the audit gap this engine exists to close. An action
 * that genuinely needs a finer grain declares
 * DeclaresIdempotencyDiscriminator, and withDiscriminator() **appends** its
 * string rather than substituting it, so no provider can ever make two
 * subjects collide.
 *
 * **The composition is here and nowhere else.** One digest serves three keys:
 * the step's claim in idempotency_keys, the queue row's dedupe key and the
 * queue row's idem key. A second composition anywhere would let the ledger
 * and the queue disagree about what one effect is, and the disagreement would
 * show up as a duplicate side effect rather than as a failing test, so a
 * caller needing the digest before it has a context calls the static digest()
 * rather than spelling the nine components again.
 *
 * **dry_run is inside the digest rather than beside it.** One code path
 * serves the preview and the real run, which is what stops the preview
 * drifting from reality; without the flag in the key, previewing a rule for a
 * subject claims the key the real run needs.
 *
 * **The cascade depth and the cascade chain are members from version one.**
 * Loop protection needs three mechanisms and two of them read this object: a
 * depth counter checked before every enqueue against a site ceiling, and the
 * chain of rule ids already on this cascade, so the default event trigger can
 * ignore a change whose actor is *any* rule on the chain rather than only the
 * same rule. Same-rule-only provenance cannot see the two-rule cycle where
 * rule A emits B's trigger and rule B emits A's, and a depth that started at
 * the column default for every event-created run could not see it either.
 *
 * The Principal is the rule's authoriser and never a literal system user,
 * because the failure mode the idempotency literature names is precisely a
 * step gated under one identity and completed under another.
 */
final class AutomationActionContext
{
    /**
     * The idempotency_keys scope every automation step claims under. Named
     * here so the engine, the conformance kit and any package writing a step
     * all spell it the same way.
     */
    public const IDEMPOTENCY_SCOPE = 'automation.action';

    /** @var array<string, mixed> */
    public readonly array $params;

    /**
     * @param array<string, mixed> $params the authored params for this step, already validated
     */
    private function __construct(
        public readonly int $ruleId,
        public readonly int $ruleVersion,
        public readonly int $runId,
        public readonly int $stepIndex,
        public readonly string $actionKey,
        public readonly string $occurrenceKey,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly Principal $principal,
        public readonly ?int $organisationId,
        public readonly bool $dryRun,
        public readonly int $attempt,
        public readonly int $cascadeDepth,
        public readonly CascadeChain $cascadeChain,
        public readonly \DateTimeImmutable $deadline,
        public readonly string $idempotencyDigest,
        public readonly \DateTimeImmutable $now,
        array $params,
        /**
         * The person this step acts for.
         *
         * It is a member and not a derivation from the subject, because a
         * sweep occurrence names a recipient the subject columns cannot hold:
         * the entitlement expiring sweep yields a learner as the recipient and
         * a course as the subject, and reading subject_id as a user id mailed
         * the course id. It is deliberately NOT part of the canonical digest,
         * which stays over its nine published components: the recipient rides
         * with the subject rather than redefining what one effect is.
         */
        public readonly ?int $recipientUserId = null,
    ) {
        $this->params = $params;
    }

    /**
     * Who this step acts for: the occurrence's recipient, and the subject only
     * when the subject is a person.
     *
     * Composed here so every action answers it the same way rather than each
     * spelling its own `subjectId ?? 0` fallback, which is how a learner's
     * notice came to be addressed to a course id.
     */
    public function actsForUserId(): ?int
    {
        if ($this->recipientUserId !== null && $this->recipientUserId > 0) {
            return $this->recipientUserId;
        }

        return $this->subjectType === 'user' && $this->subjectId !== null && $this->subjectId > 0
            ? $this->subjectId
            : null;
    }

    /**
     * The only way to build one, and the only place the digest is asked for.
     *
     * The chain arrives as the rule ids the run carries and is normalised
     * here, so a caller holding a run's stored chain passes what it has and
     * the context still answers through the one chain vocabulary.
     *
     * @param list<int>            $cascadeChain
     * @param array<string, mixed> $params
     */
    public static function forStep(
        int $ruleId,
        int $ruleVersion,
        int $runId,
        int $stepIndex,
        string $actionKey,
        string $occurrenceKey,
        Principal $principal,
        \DateTimeImmutable $now,
        \DateTimeImmutable $deadline,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $organisationId = null,
        bool $dryRun = false,
        int $attempt = 1,
        int $cascadeDepth = 0,
        array $cascadeChain = [],
        array $params = [],
        ?int $recipientUserId = null,
    ): self {
        return new self(
            $ruleId,
            $ruleVersion,
            $runId,
            $stepIndex,
            $actionKey,
            $occurrenceKey,
            $subjectType,
            $subjectId,
            $principal,
            $organisationId,
            $dryRun,
            $attempt,
            $cascadeDepth,
            CascadeChain::of($cascadeChain),
            $deadline,
            self::digest(
                $ruleId,
                $ruleVersion,
                $runId,
                $stepIndex,
                $actionKey,
                $subjectType,
                $subjectId,
                $occurrenceKey,
                $dryRun,
            ),
            $now,
            $params,
            $recipientUserId,
        );
    }

    /**
     * The canonical digest, over the nine components and in this order:
     * rule_id, rule_version, run_id, step_index, action_key, subject_type,
     * subject_id, occurrence_key, dry_run.
     *
     * A unique index sits on the digest alone rather than on the columns, for
     * the reason notification_queue already gives: a direct unique index over
     * those columns exceeds the 767 byte key limit of legacy MySQL and
     * MariaDB row formats under utf8mb4.
     */
    public static function digest(
        int $ruleId,
        int $ruleVersion,
        int $runId,
        int $stepIndex,
        string $actionKey,
        ?string $subjectType,
        ?int $subjectId,
        string $occurrenceKey,
        bool $dryRun,
    ): string {
        return hash('sha256', implode('|', [
            (string) $ruleId,
            (string) $ruleVersion,
            (string) $runId,
            (string) $stepIndex,
            $actionKey,
            $subjectType ?? '',
            (string) ($subjectId ?? 0),
            $occurrenceKey,
            $dryRun ? '1' : '0',
        ]));
    }

    /**
     * The core appending a provider's declared discriminator, never
     * substituting it.
     *
     * Appending is the whole safety property: a provider may make one
     * subject's key finer, and can never make two subjects share one. The
     * appended half is itself hashed rather than concatenated raw, because
     * idem_key is a bounded column and a provider's string is not; a distinct
     * discriminator still produces a distinct key.
     */
    public function withDiscriminator(string $discriminator): self
    {
        if ($discriminator === '') {
            return $this;
        }

        return new self(
            $this->ruleId,
            $this->ruleVersion,
            $this->runId,
            $this->stepIndex,
            $this->actionKey,
            $this->occurrenceKey,
            $this->subjectType,
            $this->subjectId,
            $this->principal,
            $this->organisationId,
            $this->dryRun,
            $this->attempt,
            $this->cascadeDepth,
            $this->cascadeChain,
            $this->deadline,
            $this->idempotencyDigest . ':' . hash('sha256', $discriminator),
            $this->now,
            $this->params,
            $this->recipientUserId,
        );
    }

    /**
     * The digest the queue row already carries, adopted verbatim.
     *
     * The core calling itself and never a provider: the engine writes the
     * queue row and the ledger claim from one composition, and this exists so
     * the step machine can take the claim under the key the row was written
     * with rather than under a second composition of its own. A provider
     * cannot reach it, because a provider holds a context and not the row.
     */
    public function withStoredDigest(string $digest): self
    {
        if ($digest === '' || $digest === $this->idempotencyDigest) {
            return $this;
        }

        return new self(
            $this->ruleId,
            $this->ruleVersion,
            $this->runId,
            $this->stepIndex,
            $this->actionKey,
            $this->occurrenceKey,
            $this->subjectType,
            $this->subjectId,
            $this->principal,
            $this->organisationId,
            $this->dryRun,
            $this->attempt,
            $this->cascadeDepth,
            $this->cascadeChain,
            $this->deadline,
            $digest,
            $this->now,
            $this->params,
            $this->recipientUserId,
        );
    }

    /**
     * Whether this rule has already acted on this cascade.
     *
     * Mechanism one of the three: the default event trigger ignores a change
     * whose actor is any rule already on the chain, not only the same rule,
     * because a two-rule cycle presents as a fresh run of a different rule
     * every time round.
     */
    public function hasRuleOnCascade(int $ruleId): bool
    {
        return $this->cascadeChain->contains($ruleId);
    }

    /** Mechanism two: the depth counter, checked before every enqueue. */
    public function isWithinCascadeCeiling(int $ceiling): bool
    {
        return $this->cascadeDepth < $ceiling;
    }

    /** One authored param, or the default when the step did not carry it. */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function hasExpired(): bool
    {
        return $this->now >= $this->deadline;
    }

    /**
     * The producer-qualified reference the notification digest takes, so
     * automation rule 42 and notification rule 42 on one trigger and
     * occurrence cannot hash to the same value and silently suppress each
     * other on notification_queue's unique index.
     *
     * The occurrence key an action delegates with is this context's own
     * occurrence key and never the literal `once`, so the automation dedupe
     * digest and the notification dedupe digest agree about what a repetition
     * is, rather than the second catching a retry of the first by luck.
     */
    public function producerReference(): string
    {
        return 'automation:' . $this->ruleId;
    }
}
