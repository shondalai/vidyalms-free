<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

/**
 * One organisation, carried by something that has a tenant but no actor.
 *
 * **It exists for exactly one problem and it is deliberately weaker than
 * OrgScope.** A queued notification intent records no actor at all, so the
 * fan-out cannot mint an OrgScope, and amendment A16 says it must not invent
 * a system actor to get one. What it does have is the organisation the use
 * case that raised the occurrence resolved under its own scope and stamped on
 * the event: the seat threshold alerts carry `organisation_id` in their
 * payload because a message to an organisation's administrators is
 * meaningless without it.
 *
 * So this is that stamp, as a type. It answers one question, "which tenant",
 * and it answers nothing else: no roles, no unit reach, no basis, no
 * disclosure policy and no digest. A caller that needs any of those needs an
 * OrgScope and must go and get one.
 *
 * **The two factories are the whole guarantee.** One copies the organisation
 * out of a scope that was already resolved, and one reads the stamp an
 * occurrence carries. Neither takes a bare id from a caller, so a tenant
 * still cannot be conjured out of a request parameter, which is the property
 * the OrgScope constructor's refusal exists to give.
 *
 * It is enough to narrow a read and never enough to authorise one. Every
 * consumer of a tenant is a resolver deciding who to tell about something
 * that already happened, and the notification queue's own preference and
 * policy layers still decide whether any of them are written to.
 */
final class OrgTenant
{
    /** The payload key a use case stamps its resolved organisation under. */
    public const STAMP = 'organisation_id';

    private function __construct(public readonly int $organisationId)
    {
        if ($organisationId < 1) {
            throw new \InvalidArgumentException('An organisation tenant must have a positive organisation.');
        }
    }

    /** The organisation a resolved scope already names. */
    public static function fromScope(OrgScope $scope): self
    {
        return new self($scope->organisationId);
    }

    /**
     * The organisation an occurrence's own parameters carry, or null.
     *
     * Null rather than an exception, because most occurrences are not about
     * an organisation at all and a fan-out asking every intent row is the
     * normal case. A stamp that is present and not a positive integer is a
     * malformed payload and answers null too: a tenant guessed at from
     * rubbish is worse than no tenant, which merely resolves to nobody.
     *
     * @param array<string, mixed> $parameters
     */
    public static function fromStampedOccurrence(array $parameters): ?self
    {
        $stamped = $parameters[self::STAMP] ?? null;

        if (!\is_int($stamped) && !(\is_string($stamped) && ctype_digit($stamped))) {
            return null;
        }

        return (int) $stamped < 1 ? null : new self((int) $stamped);
    }
}
