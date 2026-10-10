<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;

/**
 * The directory attributes of one membership, validated before they are written.
 *
 * They are the SCIM enterprise extension's own fields
 * (urn:ietf:params:scim:schemas:extension:enterprise:2.0:User) plus the three
 * OneRoster roles.csv values, and they live on the membership rather than on
 * the person because every one of them is true of somebody *within* an
 * organisation and changes when the membership does.
 *
 * It is a context object with a private constructor and named factories, so
 * the caller that is creating a membership and the caller that is patching
 * one cannot hand each other's shape to the store: a create writes every
 * column it was given, and a patch writes only the keys the request actually
 * carried, which is why "absent" and "cleared" have to stay distinguishable
 * all the way down.
 *
 * **The manager check is not optional and it is not a foreign key.** The
 * neutral schema carries no foreign keys, so the rule lives here, in the use
 * case, exactly as it will live in the importer and in every provisioning
 * source. Without it an administrator names a user id belonging to another
 * organisation and that person starts receiving this organisation's
 * compliance reminders, carrying its learners' names and their due dates,
 * through the free line_manager audience, which declares both scope markers
 * and passes a scoped run cleanly. The refusal is a validation error on
 * manager_user_id and never a silent drop.
 */
final class MembershipDirectory
{
    /** The seven SCIM attributes, in the order the schema declares them. */
    public const TEXT_FIELDS = [
        'job_title' => 190,
        'department_label' => 190,
        'division' => 190,
        'organization_label' => 190,
        'cost_centre' => 100,
        'employee_number' => 100,
    ];

    /** Every key a membership write accepts beyond the ones it already did. */
    public const FIELDS = [
        'is_primary',
        'begins_at',
        'ends_at',
        'job_title',
        'department_label',
        'division',
        'organization_label',
        'cost_centre',
        'employee_number',
        'manager_user_id',
    ];

    /** @param array<string, mixed> $columns */
    private function __construct(private readonly array $columns)
    {
    }

    /**
     * The attributes a request carried, with an absent key left absent.
     *
     * A null is a value and means "clear this", which is what a directory
     * push sending an emptied field means; a missing key means the caller was
     * not talking about that field at all. Conflating the two is how a patch
     * that renames somebody's job title erases their cost centre.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $columns = [];

        foreach (self::TEXT_FIELDS as $field => $length) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $columns[$field] = self::optionalText($input[$field], $field, $length);
        }

        foreach (['begins_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $columns[$field] = OrganisationInput::datetime($input[$field], $field);
            }
        }

        if (array_key_exists('is_primary', $input)) {
            $value = $input['is_primary'];

            if (!\is_bool($value)) {
                throw ValidationException::forField('is_primary', 'Expected true or false.');
            }

            $columns['is_primary'] = $value ? 1 : 0;
        }

        if (array_key_exists('manager_user_id', $input)) {
            $value = $input['manager_user_id'];

            if ($value !== null && (!\is_int($value) || $value < 1)) {
                throw ValidationException::forField('manager_user_id', 'Expected a positive user id or null.');
            }

            $columns['manager_user_id'] = $value;
        }

        $begins = $columns['begins_at'] ?? null;
        $ends = $columns['ends_at'] ?? null;

        if (\is_string($begins) && \is_string($ends) && $ends < $begins) {
            throw ValidationException::forField('ends_at', 'A membership cannot end before it begins.');
        }

        return new self($columns);
    }

    /**
     * Runs the checks that need the database, and answers the columns to write.
     *
     * The manager is resolved inside the caller's own scope, so a unit-scoped
     * administrator naming somebody outside their unit is refused by the same
     * predicate that refuses them reading that person, and a manager in
     * another organisation is refused because the lookup never leaves this
     * one.
     *
     * @return array<string, mixed>
     */
    public function columnsFor(OrganisationStore $store, OrgScope $scope, int $subjectUserId): array
    {
        $manager = $this->columns['manager_user_id'] ?? null;

        if (\is_int($manager)) {
            if ($manager === $subjectUserId) {
                throw ValidationException::forField('manager_user_id', 'A person cannot be their own manager.');
            }

            if ($store->activeMemberId($scope, $manager) === null) {
                throw ValidationException::forField(
                    'manager_user_id',
                    'The manager must hold an active membership in this organisation.'
                );
            }
        }

        return $this->columns;
    }

    /** Whether the request said anything about the directory at all. */
    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    private static function optionalText(mixed $value, string $field, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value) === 1 || mb_strlen($value) > $length) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'Expected text within the supported length, or null.');
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
