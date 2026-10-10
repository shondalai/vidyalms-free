<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Secrets;

/**
 * Resolves a reference to a secret, never a stored secret.
 *
 * `provider_settings.secret_ref` holds a pointer and the table holds no
 * values, because provider_settings is a readable table on a shared-hosting
 * install and a leaked webhook signing secret lets an attacker forge
 * entitlement grants. Resolution has to happen somewhere, and per-adapter
 * resolution with no interface, no ref grammar and no stated miss direction
 * is a per-adapter invention for the one value whose leak is unrecoverable.
 *
 * **The grammar is portable and closed:**
 *
 * - `env:VIDYALMS_SECRET_NAME` reads an environment variable.
 * - `store:KEY` reads the host's own secret store (a Joomla secret, a
 *   WordPress constant or option, a Drupal key entity).
 *
 * Nothing else resolves. A reference in any other shape is a miss, not an
 * attempt: allowing an unprefixed value would let a site owner paste the
 * secret itself into the column, which is the exact thing the column exists
 * to prevent, and it would work, which is worse.
 *
 * **The miss is fail closed, and it is fail closed in both directions that
 * matter.** A session is not created and a delivery is not verified, rather
 * than being created without a credential or verified against nothing. A
 * verifier that treated an unresolved secret as an empty string would accept
 * every forged delivery on a site whose configuration had drifted.
 *
 * Like HttpPort, this is a **defaulted trailing member on Ports**, so an
 * adapter that predates it keeps booting and gets {@see NoSecrets}.
 */
interface SecretsPort
{
    public const SCHEME_ENV = 'env';
    public const SCHEME_STORE = 'store';

    /** @var list<string> */
    public const SCHEMES = [self::SCHEME_ENV, self::SCHEME_STORE];

    /**
     * The secret behind a reference, or null when it cannot be resolved.
     *
     * Null covers every failure on purpose: an unknown scheme, a malformed
     * reference, an unset variable, a store the host does not have. A caller
     * must treat all of them the same way, because the only safe action is
     * the same in every case, and distinguishing them in a return value
     * invites a caller to proceed on one of them.
     */
    public function resolve(string $ref): ?string;
}
