<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\InvitationMatch;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\InvitationMatchContext;

/**
 * @extends AbstractProviderRegistry<InvitationMatchPolicyInterface>
 */
final class InvitationMatchRegistry extends AbstractProviderRegistry
{
    /**
     * The rule every organisation's invitations are under until a site
     * chooses another.
     *
     * It is the fall-back and no longer the answer. All three call sites
     * named this constant, so a registered provider could be registered and
     * never asked anything: the registry was registrable and not selectable,
     * which is a seam with a wall behind it. `organisations.invitation_match_key`
     * is the column that chooses, it defaults to this key, and every
     * organisation stored before the column reads back as the organisation it
     * already was.
     */
    public const DEFAULT_KEY = 'strict_email';

    /** The column an organisation names its acceptance policy in. */
    public const COLUMN = 'invitation_match_key';

    protected function providerInterface(): string
    {
        return InvitationMatchPolicyInterface::class;
    }

    /**
     * The policy this organisation's invitations are under.
     *
     * A missing row or a blank column answers the free key, because a build
     * with no column yet and a build that has one but has never chosen must
     * behave identically. A key naming a provider that is not installed is
     * answered as itself and fails closed at {@see matches()}, which is the
     * published direction: an acceptance nobody can authorise is refused
     * rather than admitted under a policy the organisation did not choose.
     *
     * @param array<string, mixed>|null $organisation
     */
    public static function keyFor(?array $organisation): string
    {
        $key = $organisation[self::COLUMN] ?? null;

        return \is_string($key) && $key !== '' ? $key : self::DEFAULT_KEY;
    }

    /**
     * Whether this account may use this invitation under the named rule.
     *
     * False for an unresolved key, which is the fail-closed direction the
     * interface publishes: an acceptance nobody can authorise is refused
     * rather than admitted.
     */
    public function matches(string $key, InvitationMatchContext $context): bool
    {
        return $this->has($key) && $this->get($key)->matches($context);
    }
}
