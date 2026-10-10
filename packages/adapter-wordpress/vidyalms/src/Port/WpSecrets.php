<?php

declare(strict_types=1);

/**
 * VidyaLMS: the WordPress implementation of secret resolution.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

defined('ABSPATH') || exit;

use Shondalai\VidyaLMS\Core\Port\Secrets\SecretRef;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;

/**
 * `env:VIDYALMS_SECRET_NAME` from the environment, `store:KEY` from wp-config.php or from a
 * salt-bound option. Never a plaintext row.
 *
 * The two store locations are tried in that order and both are honest
 * arrangements:
 *
 * 1. **A wp-config.php constant** named `VIDYALMS_SECRET_<KEY>`. This is what
 *    WordPress itself does with its salts, and it is the strongest option
 *    available: the value is not in the database at all, so it does not
 *    travel in a dump, a staging clone or a backup a support engineer opens.
 * 2. **A salt-bound option**, for a site whose owner cannot edit wp-config.php,
 *    which on managed hosting is most of them. The stored value is sealed with
 *    AES-256-GCM under a key derived from `wp_salt('auth')` through HKDF, so
 *    the row is ciphertext with an authentication tag and the key is a value
 *    a database dump does not carry. Rotating the site's salts, which is the
 *    documented response to a compromise, makes every sealed secret
 *    unreadable, which is the correct outcome rather than a defect: the
 *    secrets have to be re-entered, exactly as WordPress's own logged-in
 *    cookies have to be re-issued.
 *
 * The seal is bound to the key name as additional authenticated data, so a
 * row moved from one option to another stops opening. Without that, a site
 * owner with database access could point the webhook signing secret at the
 * gateway's less privileged API key and the verifier would open it happily.
 *
 * **Nothing here writes on the resolution path.** seal() exists so an
 * installer, a settings screen or a WP-CLI command can produce the stored
 * value, and it is a pure function of the plaintext, the key name and the
 * site's salt: it stores nothing itself.
 *
 * **Every failure is null**, which is the fail-closed direction the port
 * states.
 */
final class WpSecrets implements SecretsPort
{
    /** The option a sealed secret is stored in, one per key. */
    public const OPTION_PREFIX = 'vidyalms_secret_';

    /** The wp-config.php constant checked before the option. */
    public const CONSTANT_PREFIX = 'VIDYALMS_SECRET_';

    /** The sealed format, so a stored value says what opens it. */
    public const SEAL_PREFIX = 'v1.';

    private const CIPHER = 'aes-256-gcm';

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    /** Distinguishes this use of the site's auth salt from every other one. */
    private const HKDF_INFO = 'vidyalms.secrets.v1';

    public function resolve(string $ref): ?string
    {
        $parsed = SecretRef::parse($ref);

        if ($parsed === null) {
            return null;
        }

        return $parsed->isEnvironment()
            ? self::fromEnvironment($parsed->name)
            : self::fromStore($parsed->name);
    }

    /**
     * Seals a plaintext for storage under one key name.
     *
     * Public so an installer or a settings screen can produce the value
     * without a second copy of the format. Null when the site's OpenSSL
     * cannot do authenticated encryption, which is a refusal to store a
     * secret weakly rather than a fallback to storing it in the clear.
     */
    public static function seal(string $keyName, string $plaintext): ?string
    {
        if ($plaintext === '' || !self::usable($keyName)) {
            return null;
        }

        $key = self::derivedKey();

        if ($key === null) {
            return null;
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $keyName,
            self::TAG_BYTES,
        );

        if (!\is_string($ciphertext) || \strlen($tag) !== self::TAG_BYTES) {
            return null;
        }

        return self::SEAL_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /** The option name a sealed secret for that key is stored under. */
    public static function optionName(string $keyName): string
    {
        return self::OPTION_PREFIX . strtolower(str_replace(['.', '-'], '_', $keyName));
    }

    /** The wp-config.php constant checked for that key. */
    public static function constantName(string $keyName): string
    {
        return self::CONSTANT_PREFIX . strtoupper(str_replace(['.', '-'], '_', $keyName));
    }

    /**
     * getenv() first, because it is the only one of the three that sees a
     * variable set by the SAPI after the request started.
     */
    private static function fromEnvironment(string $name): ?string
    {
        $value = getenv($name);

        if (!\is_string($value) || $value === '') {
            /** @var mixed $value */
            // Provisioned server secret, restricted by SecretRef's dedicated prefix.
            // Text sanitization would corrupt key material; request headers are never read.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $value = $_ENV[$name] ?? null;
        }

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function fromStore(string $keyName): ?string
    {
        if (!self::usable($keyName)) {
            return null;
        }

        $constant = self::constantName($keyName);

        if (\defined($constant)) {
            /** @var mixed $value */
            $value = \constant($constant);

            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        /** @var mixed $stored */
        $stored = get_option(self::optionName($keyName), '');

        return \is_string($stored) ? self::open($keyName, $stored) : null;
    }

    private static function open(string $keyName, string $stored): ?string
    {
        if (!str_starts_with($stored, self::SEAL_PREFIX)) {
            // An unsealed value is refused rather than returned. Accepting it
            // would make "paste the secret into the option row" work, which is
            // the arrangement this class exists to make impossible.
            return null;
        }

        $raw = base64_decode(substr($stored, \strlen(self::SEAL_PREFIX)), true);

        if (!\is_string($raw) || \strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }

        $key = self::derivedKey();

        if ($key === null) {
            return null;
        }

        $plaintext = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $key,
            \OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES),
            $keyName,
        );

        return \is_string($plaintext) && $plaintext !== '' ? $plaintext : null;
    }

    /**
     * The key the seal is bound to, derived from this site's auth salt.
     *
     * The salt itself is never used as a key directly: HKDF with a stated
     * info string keeps this use separate from every other thing on the site
     * that reads wp_salt('auth').
     */
    private static function derivedKey(): ?string
    {
        if (!\function_exists('openssl_encrypt') || !\in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            return null;
        }

        $salt = wp_salt('auth');

        if ($salt === '') {
            return null;
        }

        return hash_hkdf('sha256', $salt, 32, self::HKDF_INFO);
    }

    /** The key name grammar SecretRef already enforces, restated for seal()'s callers. */
    private static function usable(string $keyName): bool
    {
        return SecretRef::parse(SecretsPort::SCHEME_STORE . ':' . $keyName) !== null;
    }
}
