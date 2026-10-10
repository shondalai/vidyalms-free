<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\FieldConflictPolicy;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceInterface;

/**
 * The three free provenances: a person added them, they accepted an
 * invitation, or a file was loaded.
 *
 * One parameterised provider rather than three near-identical classes, exactly
 * as the four free organisation roles are one. They differ in a key, a label
 * and nothing else, because all three answer the same way to both questions:
 * none of them is continuous, and none of them governs a field.
 *
 * **That is the whole point of registering them.** org_memberships.
 * provisioned_by has held one of these three words since the spine shipped and
 * nothing resolved it, so the column was a string a screen printed. Now every
 * value in it resolves through a registry, a Pro or Business source is a
 * registration into the same seam rather than a fourth word added to a
 * comment, and the console's third row shape has a question to ask: is this
 * row governed somewhere else, and if so, where.
 *
 * None of the three declares NamesGoverningSource, and that is a decision
 * rather than an omission. An administrator who added somebody by hand does
 * not own the row afterwards; neither does an invitation that was accepted
 * last March, nor a CSV that was uploaded once. Rows they created are
 * ordinary, editable rows, which is what the console has always shown.
 */
final class CoreProvisioningSource implements ProvisioningSourceInterface
{
    /** The family every core provenance sits in, for the picker's ordering. */
    public const GROUP = 'organisation';

    private function __construct(
        private readonly string $name,
        private readonly int $position,
    ) {
    }

    /** Somebody with the people right added them in the console. */
    public static function manual(): self
    {
        return new self('manual', 10);
    }

    /** They accepted an invitation and proved they hold the mailbox. */
    public static function invitation(): self
    {
        return new self('invitation', 20);
    }

    /** A roster or member file an operator uploaded. */
    public static function csv(): self
    {
        return new self('csv', 30);
    }

    /**
     * The host itself, asserting about its own accounts and groups.
     *
     * The free build own identity provenance, and the fourth word this column
     * may hold. It exists because the host-assertion path had no registered
     * source to attribute a write to and ApplyProvisioningAssertion fails
     * closed on an unregistered one: without it the free identity clause could
     * not have been executed even by a caller that wanted to.
     *
     * It is continuous, unlike the other three, and that is the honest answer
     * rather than a convenience: the host is asked again on every sign-in, so a
     * person whose host groups changed is re-read, which is exactly what the
     * other three cannot do.
     */
    public static function hostGroup(): self
    {
        return new self('host_group', 40);
    }

    /**
     * All three, in the order a picker shows them.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::manual(), self::invitation(), self::csv(), self::hostGroup()];
    }

    public function key(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'org.provisioning.' . $this->name;
    }

    /**
     * None of the first three is continuous, and the console says so.
     *
     * An organisation whose people all arrived by one of those routes has no
     * standing source that will close a membership or release a seat when
     * somebody leaves. That is a true and important limitation and it is
     * stated rather than discovered: nothing in a file upload tells this
     * product that a person has left the company.
     *
     * The host-group source is the exception and the reason the word exists on
     * a free build: the host is asked again on every sign-in.
     */
    public function isContinuous(): bool
    {
        return $this->name === 'host_group';
    }

    /**
     * A person's edit always stands.
     *
     * provisioning_only rather than sticky, because sticky implies a source
     * that would otherwise keep writing and has agreed not to. None of these
     * three ever writes a field again after the row exists, so there is
     * nothing to hold back and nothing for an administrator to release.
     */
    public function conflictPolicy(): string
    {
        return FieldConflictPolicy::PROVISIONING_ONLY;
    }

    /** For the picker's ordering, alongside the free roles and types. */
    public function order(): int
    {
        return $this->position;
    }

    public function group(): string
    {
        return self::GROUP;
    }
}
