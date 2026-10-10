<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper;

/**
 * What a role mapper answers: a registered org role key, or a refusal that says
 * why.
 *
 * A nullable string would have been shorter and would have thrown away the half
 * that matters. Rule one of the two hard rules is that **an unmapped role is a
 * warned skip and never a default**, and a warned skip needs a warning: the
 * operator reading their import's row report has to be told which foreign word
 * nothing translated, or they are left with a row that did not arrive and no
 * way to find out why.
 *
 * Three outcomes, not two:
 *
 * - `mapped`, carrying the org role key, and possibly a warning of its own. A
 *   deprecated LTI simple name matched by its short form maps and warns, which
 *   is what "simple names match only as a compatibility fallback with a
 *   warning" means in code.
 * - `unmapped`, carrying the foreign token. The caller reports a
 *   RowProblem::CODE_UNMAPPED_ROLE and writes nothing.
 * - `refused`, which is rule two: a mapping that would have produced an
 *   administrative capability is refused rather than downgraded. It is not the
 *   same as unmapped and must not be reported as one, because unmapped means
 *   "nobody translates this" and refused means "somebody translated it into
 *   something this seam may never produce".
 */
final class RoleMapping
{
    public const OUTCOME_MAPPED = 'mapped';
    public const OUTCOME_UNMAPPED = 'unmapped';
    public const OUTCOME_REFUSED = 'refused';

    private function __construct(
        public readonly string $outcome,
        public readonly string $token,
        public readonly ?string $roleKey,
        public readonly ?string $warning,
    ) {
    }

    /** The token translated cleanly. */
    public static function to(string $token, string $roleKey): self
    {
        return new self(self::OUTCOME_MAPPED, $token, $roleKey, null);
    }

    /**
     * The token translated, through a form the standard has deprecated.
     *
     * LTI simple names ("Instructor") are deprecated in favour of the full
     * membership URIs, so a mapper matches the URI first and accepts the short
     * form only as a compatibility fallback. Accepting it silently would leave
     * a customer's integration on a deprecated form with nothing telling them,
     * which is how a working integration becomes a broken one at somebody
     * else's release.
     */
    public static function deprecated(string $token, string $roleKey, string $warning): self
    {
        return new self(self::OUTCOME_MAPPED, $token, $roleKey, $warning);
    }

    /** Nobody translates this token. Rule one: a warned skip, never a default. */
    public static function unmapped(string $token): self
    {
        return new self(self::OUTCOME_UNMAPPED, $token, null, sprintf(
            'No registered role mapper translates the role "%s", so this row was skipped. A foreign role'
                . ' nobody translates is never given a default role: guessing would give somebody rights'
                . ' their own directory never said they had.',
            $token,
        ));
    }

    /**
     * The mapping was refused because of what it would have produced.
     *
     * Rule two, and it is a refusal rather than a downgrade on purpose. A
     * roster asserting `systemAdministrator` is a foreign system claiming
     * somebody administers *it*; silently mapping that to a lesser role would
     * be this product deciding what a foreign assertion meant, and silently
     * mapping it to a greater one is how a spreadsheet becomes a privilege
     * escalation.
     */
    public static function refused(string $token, string $reason): self
    {
        return new self(self::OUTCOME_REFUSED, $token, null, $reason);
    }

    public function isMapped(): bool
    {
        return $this->outcome === self::OUTCOME_MAPPED;
    }

    /** The role key, for a caller that has already asked isMapped(). */
    public function requireRoleKey(): string
    {
        return $this->roleKey ?? throw new \LogicException(
            'A role mapping that is not mapped carries no role key; ask isMapped() first and report the'
                . ' warning when it says no.',
        );
    }
}
