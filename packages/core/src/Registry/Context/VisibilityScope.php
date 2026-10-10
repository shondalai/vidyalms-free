<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;

/**
 * How far a caller may see.
 *
 * It exists so a group leader's report and a group leader's rule cannot
 * quietly become site-wide: a resolver that does not declare
 * FiltersByVisibility is refused inside a scoped run rather than trusted to
 * ignore the scope politely.
 *
 * It carries a list rather than a single id, and the single id remains beside
 * it as the first element, so every shipped consumer reading $id keeps
 * working. The widening lands once, here, in one edit: the three new types,
 * the list, the two new factories and the two refusals. Commerce and the
 * organisation reports both consume it, and adding it twice would have meant
 * two shapes of one boundary inside one release.
 */
final class VisibilityScope
{
    public const TYPE_SITE = 'site';
    public const TYPE_COURSE = 'course';
    public const TYPE_COHORT = 'cohort';
    public const TYPE_GROUP = 'group';
    public const TYPE_ORGANISATION = 'organisation';
    public const TYPE_ORG_UNIT = 'org_unit';
    public const TYPE_USER = 'user';

    /**
     * The two types that may only be built from a resolved OrgScope.
     *
     * of() refuses them by name. Without that, of('organisation', 7) would be
     * a second, unguarded constructor for the boundary OrgScope spends its
     * whole existence making unforgeable, and the two objects are one
     * composition rather than two representations of one thing.
     */
    private const SCOPE_ONLY_TYPES = [self::TYPE_ORGANISATION, self::TYPE_ORG_UNIT];

    /** @param list<int> $ids */
    private function __construct(
        public readonly string $type,
        public readonly int $id,
        /**
         * Every id this scope reaches, with the single id first.
         *
         * Defaulted and derived, so a caller written against the single-id
         * shape gets a one-element list and a caller written against the list
         * gets the same answer either way.
         */
        public readonly array $ids = [],
        /**
         * The organisation digest this scope was copied from, when it came
         * from one.
         *
         * It rides along so an artefact keyed on it cannot be served under a
         * differently-resolved scope over the same ids: the digest covers the
         * basis, the roles and the disclosure policy, none of which the type
         * and id pair can express.
         */
        public readonly ?string $digest = null,
    ) {
    }

    public static function site(): self
    {
        return new self(self::TYPE_SITE, 0, []);
    }

    public static function forCourse(int $courseId): self
    {
        return new self(self::TYPE_COURSE, $courseId, [$courseId]);
    }

    /** One person, for a report or an audience bounded to a single learner. */
    public static function forUser(int $userId): self
    {
        return new self(self::TYPE_USER, $userId, [$userId]);
    }

    /**
     * The organisation-typed factory, and the only way to build one.
     *
     * It copies the resolved ids and the digest off a scope an authorisation
     * produced, so an organisation-typed visibility scope cannot exist without
     * one. A scope constrained to a unit becomes the unit type carrying the
     * unit ids, because the narrowing is what the consumer must honour.
     */
    public static function forOrganisation(OrgScope $scope): self
    {
        $units = $scope->unitIds();

        if ($units !== []) {
            return new self(self::TYPE_ORG_UNIT, $units[0], $units, $scope->digest());
        }

        $organisations = $scope->organisationIds();

        return new self(self::TYPE_ORGANISATION, $organisations[0], $organisations, $scope->digest());
    }

    /**
     * The organisation an occurrence stamped, for a run that has a tenant and
     * no actor.
     *
     * It carries no digest, and that is the difference from
     * {@see forOrganisation()} rather than an omission. A digest identifies a
     * resolved reach so an artefact can be cached against it; a stamp is one
     * organisation id with no roles, no unit reach and no basis behind it, so
     * there is no reach to identify and a null digest is the honest answer. A
     * report artefact must therefore never be keyed on one of these, which is
     * why nothing but the notification fan-out builds one.
     *
     * It still cannot be conjured from a request parameter: OrgTenant's own
     * two factories are the guarantee, and of() and ofMany() still refuse
     * this type outright.
     */
    public static function forStampedOrganisation(\Shondalai\VidyaLMS\Core\Application\Organisation\OrgTenant $tenant): self
    {
        return new self(self::TYPE_ORGANISATION, $tenant->organisationId, [$tenant->organisationId]);
    }

    /**
     * The shipped general factory, unchanged for the four original types and
     * refusing the two the organisation scope owns.
     *
     * The message names the factory rather than saying no, because the author
     * hitting it is looking for the way in and there is exactly one.
     */
    public static function of(string $type, int $id): self
    {
        if (\in_array($type, self::SCOPE_ONLY_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'A "%s" visibility scope is built from a resolved OrgScope through '
                . 'VisibilityScope::forOrganisation(), never from a type and an id.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $type,
            ));
        }

        return new self($type, $id, [$id]);
    }

    /**
     * Several ids of one type.
     *
     * The two organisation types are refused here for the same reason they are
     * refused by of(): a list is not a licence to skip the resolver.
     *
     * @param list<int> $ids
     */
    public static function ofMany(string $type, array $ids): self
    {
        if (\in_array($type, self::SCOPE_ONLY_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'A "%s" visibility scope is built from a resolved OrgScope through '
                . 'VisibilityScope::forOrganisation(), never from a type and a list of ids.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $type,
            ));
        }

        $unique = array_values(array_unique($ids));
        sort($unique);

        if ($unique === []) {
            throw new \InvalidArgumentException('A scope over no ids reaches nothing; say site() if you mean everything.');
        }

        return new self($type, $unique[0], $unique);
    }

    public function isSiteWide(): bool
    {
        return $this->type === self::TYPE_SITE;
    }

    /**
     * Whether this scope is one of the two the organisation resolver owns,
     * which is the question a run asks before it demands HonoursOrgScope.
     */
    public function isOrganisational(): bool
    {
        return \in_array($this->type, self::SCOPE_ONLY_TYPES, true);
    }

    /**
     * Every id this scope reaches.
     *
     * A site-wide scope reaches no id in particular, so it answers an empty
     * list, and that is the one place an empty list means everything rather
     * than nothing. isSiteWide() is the question to ask first.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        if ($this->ids !== []) {
            return $this->ids;
        }

        return $this->isSiteWide() ? [] : [$this->id];
    }
}
