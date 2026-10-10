<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Compliance;

use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * What a compliance rule answers: two statuses, up to eight dates, and the
 * rule's own private memory.
 *
 * The eight dates are eight fields and not one, because every one of them is
 * filtered or bucketed by a report and because `baseline_expires_at` must stay
 * separate from `expires_at`: under a fixed scheme with a minimum active period
 * the effective expiry moves, and the next cycle computes from the baseline or
 * the series drifts by the length of every late completion.
 *
 * `ruleState` is the resolving provider's own per-assignment memory, stored
 * under a {@see StoredShape} envelope with its version mirrored into a column.
 * It is **never read by a report and never joined on**, which is the schema's
 * JSON-versus-column doctrine applied correctly: without it, the first rule that
 * needs a ninth date is a core schema migration, which is the fork the
 * extensibility rule forbids.
 */
final class ComplianceState
{
    /**
     * @param array<string, mixed> $ruleState the resolving rule's private memory, unversioned here
     * @param list<string>         $notes
     */
    private function __construct(
        public readonly string $status,
        public readonly string $renewalStatus,
        public readonly ?\DateTimeImmutable $startsAt,
        public readonly ?\DateTimeImmutable $dueAt,
        public readonly ?\DateTimeImmutable $windowOpensAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $baselineExpiresAt,
        public readonly ?\DateTimeImmutable $graceEndsAt,
        public readonly array $ruleState,
        public readonly int $ruleStateVersion,
        public readonly array $notes,
    ) {
    }

    /**
     * The answer a rule gives when it has resolved the person's position.
     *
     * @param array<string, mixed> $ruleState
     * @param list<string>         $notes
     */
    public static function of(
        string $status,
        string $renewalStatus = RenewalStatus::NOT_DUE,
        ?\DateTimeImmutable $startsAt = null,
        ?\DateTimeImmutable $dueAt = null,
        ?\DateTimeImmutable $windowOpensAt = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $baselineExpiresAt = null,
        ?\DateTimeImmutable $graceEndsAt = null,
        array $ruleState = [],
        int $ruleStateVersion = 1,
        array $notes = [],
    ): self {
        return new self(
            ComplianceStatus::of($status),
            RenewalStatus::of($renewalStatus),
            $startsAt,
            $dueAt,
            $windowOpensAt,
            $expiresAt,
            $baselineExpiresAt,
            $graceEndsAt,
            StoredShape::bodyOf($ruleState),
            max(1, $ruleStateVersion),
            array_values(array_unique($notes)),
        );
    }

    /**
     * The answer when no rule resolved, which is every answer the free build
     * gives.
     *
     * **It is assigned and untracked and it is never compliant.** That is the
     * published miss direction of the `ComplianceRule` registry, and it is the
     * one registry miss in the product whose reassuring reading is the
     * dangerous one: reporting a person compliant because nothing was installed
     * to say otherwise is how an organisation discovers at an audit that its
     * evidence was an empty registry.
     */
    public static function untracked(string $reason = 'vidyalms.compliance.no_rule'): self
    {
        return new self(
            ComplianceStatus::ASSIGNED,
            RenewalStatus::NOT_DUE,
            null,
            null,
            null,
            null,
            null,
            null,
            [],
            1,
            [$reason],
        );
    }

    public function isCompliant(): bool
    {
        return ComplianceStatus::isCompliant($this->status);
    }

    /**
     * Where the miss direction is kept, beside the provider's body and never
     * inside it.
     *
     * The four note keys this seam publishes, `no_rule`, `rule_missing:{key}`,
     * `unresolved:{key}` and `completed_late`, were written by the registry and
     * by every rule and then dropped on the floor: nothing persisted them,
     * nothing returned them and none of them was in a catalogue, so a site
     * whose rule package was uninstalled saw a status word with no reason. The
     * twin case does it correctly, `org.catalogue.source_not_installed` is
     * returned by the read and is in the catalogue, and this is that.
     *
     * It is a reserved key in the envelope the core owns rather than a column,
     * because a note is a fact about the last evaluation and belongs with the
     * evaluation. Underscored for the same reason `_v` is: a provider shipping
     * a top-level `_notes` of its own would be shadowed, so no provider may.
     */
    public const NOTES_KEY = '_notes';

    /**
     * The stored `rule_state` column value, enveloped.
     *
     * @return array<string, mixed>
     */
    public function storedRuleState(): array
    {
        $body = $this->ruleState;
        unset($body[self::NOTES_KEY]);
        $stored = StoredShape::wrap($body, $this->ruleStateVersion);

        return $this->notes === [] ? $stored : [self::NOTES_KEY => $this->notes] + $stored;
    }

    /**
     * The notes a stored envelope carries, for a read that wants to say why.
     *
     * @param array<string, mixed> $stored
     *
     * @return list<string>
     */
    public static function notesOf(array $stored): array
    {
        $notes = $stored[self::NOTES_KEY] ?? null;

        if (!\is_array($notes)) {
            return [];
        }

        $clean = [];

        foreach ($notes as $note) {
            if (\is_string($note) && $note !== '') {
                $clean[] = $note;
            }
        }

        return $clean;
    }

    /**
     * The provider's own memory, with both reserved keys stripped.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public static function ruleBodyOf(array $stored): array
    {
        unset($stored[self::NOTES_KEY]);

        return StoredShape::bodyOf($stored);
    }

    /**
     * The eight-date half of a `compliance_assignments` row, as columns.
     *
     * `assigned_at` and `completed_at` are absent on purpose: the first is
     * written once by the assignment and the second by the completion listener,
     * so a rule that could answer either would be a rule that can rewrite when
     * somebody was assigned or claim they finished.
     *
     * @return array<string, string|null>
     */
    public function dateColumns(): array
    {
        return [
            'starts_at' => self::instant($this->startsAt),
            'due_at' => self::instant($this->dueAt),
            'window_opens_at' => self::instant($this->windowOpensAt),
            'expires_at' => self::instant($this->expiresAt),
            'baseline_expires_at' => self::instant($this->baselineExpiresAt),
            'grace_ends_at' => self::instant($this->graceEndsAt),
        ];
    }

    private static function instant(?\DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
