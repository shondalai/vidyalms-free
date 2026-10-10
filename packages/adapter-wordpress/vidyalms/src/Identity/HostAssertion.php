<?php

declare(strict_types=1);

/**
 * VidyaLMS: the WordPress side of the free identity clause.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Identity;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;

defined( 'ABSPATH' ) || exit;

/**
 * The call the asserted-identity port did not have on this host.
 *
 * Both adapters shipped an AssertedIdentityPort, both seated it, the core
 * shipped ApplyHostAssertion to read it, and nothing anywhere invoked that use
 * case after authenticating anybody. So the free identity clause was a set of
 * parts rather than a path: every rule an operator could author sat behind a
 * seam nothing reached. This class is the WordPress half of the missing call
 * and its Joomla twin is the onUserAfterLogin subscriber in
 * plg_system_vidyalms.
 *
 * ## What it reads, and what it refuses to read
 *
 * It reads Ports::$assertedIdentity, by way of the use case that holds it. It
 * does not read a request attribute, a cookie, a header or a posted field, and
 * it does not read the login form's own answer for anything but the account
 * id: the host has already decided who this is, and the whole point of the
 * assertion ceremony is that what the person is a member of comes from the
 * host's own directory rather than from the request that logged them in.
 *
 * ## Why wp_login and not authenticate
 *
 * wp_login is the first hook that fires with an authenticated WP_User in hand
 * and nothing left to veto, which is what makes it the right place: an earlier
 * hook would be applying a directory assertion to a login that has not
 * happened yet. Application passwords and REST authentication do not fire it,
 * and that is correct rather than a gap. A token authenticating a machine is
 * not a person arriving, and provisioning somebody's memberships on every API
 * call would be a directory read per request.
 *
 * ## Never fatal
 *
 * ApplyHostAssertion answers a ProvisioningOutcome for every ordinary result,
 * including "this host has nothing to assert", which is what a stock site with
 * no verified connection answers on every single login. Anything unexpected is
 * swallowed here, because a directory that cannot be read is not a failed
 * login and wp_login has no way to report a soft failure to the person at the
 * form.
 */
final class HostAssertion
{
    /**
     * The hook, named once so the test and the reader agree with the code.
     */
    public const HOOK = 'wp_login';

    /** @var callable():CoreKernel */
    private $kernelResolver;

    /**
     * @param callable():CoreKernel $kernelResolver
     */
    private function __construct(callable $kernelResolver)
    {
        $this->kernelResolver = $kernelResolver;
    }

    /**
     * Registers the listener. The kernel is a resolver and not an instance, so
     * a request that never logs anybody in never builds one.
     *
     * @param callable():CoreKernel $kernelResolver
     */
    public static function register(callable $kernelResolver): self
    {
        $listener = new self($kernelResolver);

        // Late, so a plugin that maps this person into a role on wp_login has
        // already done it and the directory read sees the finished account.
        add_action(self::HOOK, [$listener, 'onLogin'], 100, 2);

        return $listener;
    }

    /**
     * @param mixed $login the account name WordPress passes first, unused
     * @param mixed $user  the authenticated WP_User
     */
    public function onLogin($login = null, $user = null): void
    {
        $userId = $this->userIdOf($user);

        if ($userId < 1) {
            return;
        }

        try {
            $kernel = ($this->kernelResolver)();
            $kernel->recordLogin->execute(Principal::user($userId));
            $kernel->applyHostAssertion->execute(Principal::user($userId));
        } catch (\Throwable) {
            // Deliberately inert. See the class docblock.
        }
    }

    /**
     * The authenticated account, from the object WordPress hands over.
     *
     * get_current_user_id() is the fallback and not the first answer: wp_login
     * fires before the current user is always set on every code path that
     * reaches it, and the WP_User in the arguments is the account that was
     * actually authenticated.
     *
     * @param mixed $user
     */
    private function userIdOf($user): int
    {
        if (\is_object($user) && isset($user->ID)) {
            $id = $user->ID;

            if (\is_int($id) || \is_string($id)) {
                return (int) $id;
            }
        }

        return \function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }
}
