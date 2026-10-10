<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

/**
 * How the people in a report relate to the person reading it.
 *
 * Moodle ships this as a report condition with four values (themselves, people
 * who report to the viewer, direct reports only, and people in the same
 * department), and that is the shape adopted here. One report definition
 * therefore serves a site administrator, an organisation administrator, a unit
 * manager and a group leader, because the relation is a value the route
 * resolves and puts on the context **before** the provider runs.
 *
 * It is deliberately not a job of the access policy, which owns the rule and
 * nothing else, and it is deliberately not four report providers for four
 * roles: four providers for four roles is four places to forget the scope.
 *
 * It carries no identity of its own. The people it selects are resolved from
 * the OrgScope beside it on the context, so a relation cannot widen a scope
 * and a scope cannot be re-derived from a relation.
 */
final class ViewerRelation
{
    /** Only the viewer's own rows. */
    public const SELF = 'self';

    /** Everyone below the viewer in the management line, at any depth. */
    public const REPORTS_TO_VIEWER = 'reports_to_viewer';

    /** Only the people whose membership names the viewer as their manager. */
    public const DIRECT_REPORTS = 'direct_reports';

    /** Everyone in the same unit as the viewer. */
    public const SAME_UNIT = 'same_unit';

    /** Everyone the scope reaches, which is what an organisation-wide role sees. */
    public const WHOLE_SCOPE = 'whole_scope';

    private function __construct(public readonly string $value)
    {
    }

    public static function self(): self
    {
        return new self(self::SELF);
    }

    public static function reportsToViewer(): self
    {
        return new self(self::REPORTS_TO_VIEWER);
    }

    public static function directReports(): self
    {
        return new self(self::DIRECT_REPORTS);
    }

    public static function sameUnit(): self
    {
        return new self(self::SAME_UNIT);
    }

    public static function wholeScope(): self
    {
        return new self(self::WHOLE_SCOPE);
    }

    /**
     * The relation named by a stored or requested value.
     *
     * It throws rather than falling back, because a relation nobody recognises
     * silently becoming the widest one is how a report meant for a line
     * manager returns the organisation.
     */
    public static function of(string $value): self
    {
        if (!\in_array($value, self::values(), true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown viewer relation "%s".', $value));
        }

        return new self($value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::SELF, self::REPORTS_TO_VIEWER, self::DIRECT_REPORTS, self::SAME_UNIT, self::WHOLE_SCOPE];
    }

    /** Whether this relation narrows the scope it sits beside. */
    public function narrows(): bool
    {
        return $this->value !== self::WHOLE_SCOPE;
    }
}
