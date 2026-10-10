<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedKeyKind;
use Shondalai\VidyaLMS\Core\Port\Identity\CanonicalAttributes;

/**
 * One row of a connection's ordered rule list: a condition, a target and a
 * stop flag.
 *
 * The shape is the one the design draws:
 *
 *   when: { attribute, op, value, value_kind }
 *   then: { org_ref, org_id, org_unit_ref, org_unit_id, org_role }
 *   stop: true
 *
 * **Both a ref and a resolved id are stored, and that is the anti-rename
 * device.** A ref alone would let a later rename re-point a stored rule at a
 * different organisation; an id alone would leave nothing readable in the
 * saved rule for the administrator who wrote it. The pair is written together
 * at save, from one resolution, and the evaluator re-resolves the id against
 * the connection's own organisation, because a stored row outlives the
 * authorisation that wrote it.
 *
 * **value_kind is mandatory on a group-shaped attribute** and is refused on
 * any other. Group values are not identifiers unless the directory says so,
 * and a rule that does not say which kind it is comparing is a rule that
 * matches the wrong thing the day somebody reconfigures a claim.
 */
final class MappingRule
{
    private function __construct(
        public readonly string $attribute,
        public readonly string $operator,
        public readonly ?string $value,
        public readonly ?string $valueKind,
        public readonly ?string $orgRef,
        public readonly ?int $orgId,
        public readonly ?string $orgUnitRef,
        public readonly ?int $orgUnitId,
        public readonly string $roleKey,
        public readonly bool $stop,
    ) {
    }

    /**
     * A rule from an administrator's stored row, refused rather than repaired.
     *
     * @param array<string, mixed> $row the body of one rule, envelope already stripped
     */
    public static function fromStored(array $row, int $index): self
    {
        $when = \is_array($row['when'] ?? null) ? $row['when'] : [];
        $then = \is_array($row['then'] ?? null) ? $row['then'] : [];
        $field = 'mapping_rules.' . $index;

        $attribute = self::text($when['attribute'] ?? null);
        $operator = self::text($when['op'] ?? null);

        if ($attribute === null || !CanonicalAttributes::isKnown($attribute)) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field . '.when.attribute',
                'A rule names one of the core attribute words.'
            );
        }

        if ($operator === null || !MappingOperator::isKnown($operator)) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field . '.when.op',
                'A rule uses one of the published operators.'
            );
        }

        $value = self::text($when['value'] ?? null);

        if (MappingOperator::needsValue($operator) && $value === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.when.value', 'This operator needs a value.');
        }

        if ($operator === MappingOperator::MATCHES && !MappingOperator::isStorablePattern((string) $value)) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field . '.when.value',
                'A pattern must compile, carry no back reference and stay under '
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    . MappingOperator::MAX_PATTERN . ' characters.'
            );
        }

        $valueKind = self::text($when['value_kind'] ?? null);
        $groupShaped = CanonicalAttributes::isGroupShaped($attribute);

        if ($groupShaped && $valueKind === null) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field . '.when.value_kind',
                'A rule matching a group value must say what kind of key it is comparing.'
            );
        }

        if (!$groupShaped && $valueKind !== null) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field . '.when.value_kind',
                'Only a group-shaped attribute carries a key kind.'
            );
        }

        if ($valueKind !== null && !AssertedKeyKind::isKnown($valueKind)) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field . '.when.value_kind',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'A key kind is one of ' . implode(', ', AssertedKeyKind::KINDS) . '.'
            );
        }

        $roleKey = self::text($then['org_role'] ?? null);

        if ($roleKey === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.then.org_role', 'A rule grants exactly one role.');
        }

        return new self(
            $attribute,
            $operator,
            $value,
            $valueKind,
            self::text($then['org_ref'] ?? null),
            self::positive($then['org_id'] ?? null),
            self::text($then['org_unit_ref'] ?? null),
            self::positive($then['org_unit_id'] ?? null),
            $roleKey,
            ($row['stop'] ?? false) === true,
        );
    }

    /**
     * The rule as it is stored, with the resolved ids beside the refs.
     *
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        $when = ['attribute' => $this->attribute, 'op' => $this->operator];

        if ($this->value !== null) {
            $when['value'] = $this->value;
        }

        if ($this->valueKind !== null) {
            $when['value_kind'] = $this->valueKind;
        }

        $then = ['org_role' => $this->roleKey];

        foreach (
            ['org_ref' => $this->orgRef, 'org_id' => $this->orgId,
                'org_unit_ref' => $this->orgUnitRef, 'org_unit_id' => $this->orgUnitId] as $key => $stored
        ) {
            if ($stored !== null) {
                $then[$key] = $stored;
            }
        }

        return ['when' => $when, 'then' => $then, 'stop' => $this->stop];
    }

    /** The same rule carrying the ids one resolution produced. */
    public function resolvedTo(int $organisationId, ?int $orgUnitId): self
    {
        return new self(
            $this->attribute,
            $this->operator,
            $this->value,
            $this->valueKind,
            $this->orgRef,
            $organisationId,
            $this->orgUnitRef,
            $orgUnitId,
            $this->roleKey,
            $this->stop,
        );
    }

    /** Whether this rule is comparing a group-shaped attribute at all. */
    public function isGroupShaped(): bool
    {
        return CanonicalAttributes::isGroupShaped($this->attribute);
    }

    /** A short line naming the comparison, for the explainer and for nothing else. */
    public function describe(): string
    {
        return $this->attribute . ' ' . $this->operator
            . ($this->value === null ? '' : ' ' . $this->value)
            . ($this->valueKind === null ? '' : ' [' . $this->valueKind . ']');
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function positive(mixed $value): ?int
    {
        return \is_int($value) && $value > 0 ? $value : null;
    }
}
