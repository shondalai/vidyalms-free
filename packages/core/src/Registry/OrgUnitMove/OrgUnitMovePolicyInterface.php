<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgUnitMove;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgUnitMoveContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What a member carries when they move between units.
 *
 * Carrying everything is the shape the free build means; a paid policy may
 * carry entitlements and drop unit-scoped reporting history.
 *
 * Miss direction: normal path: it falls back to carrying everything and records the substitution on the membership event.
 *
 * The seam had no provider and no reader at all until the last build wave: it
 * was seated, published in the edition table, and consulted by nothing, so a
 * Business registration into it was a silent no-op.
 * {@see carriesUnitScopedRole()} is the decision shape and the member update
 * is the reader.
 */
interface OrgUnitMovePolicyInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * Whether a role granted inside the unit the member is leaving travels
     * with them.
     *
     * It is the one thing a move can silently take away: a unit manager moved
     * from Bristol to Leeds either manages Leeds or manages nothing, and the
     * free build carried the scope across without anybody deciding it should.
     * True keeps the role's own unit anchor pointed at the unit the member is
     * moving to; false clears it, which leaves the role held over the whole
     * organisation the role's own declaration allows and no more.
     *
     * A member holding no unit-scoped role is not asked about: the caller
     * skips the question, because a policy answering about a role that does
     * not exist is a decision with nothing behind it.
     */
    public function carriesUnitScopedRole(OrgUnitMoveContext $context): bool;
}
