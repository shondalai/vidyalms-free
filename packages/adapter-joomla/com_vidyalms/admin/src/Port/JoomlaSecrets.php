<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Port\Secrets\SecretRef;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;

/**
 * The Joomla implementation of secret resolution.
 *
 * `env:VIDYALMS_SECRET_NAME` is the environment, which is what a managed host, a container and
 * a systemd unit all supply and what a database dump never carries.
 *
 * `store:KEY` is a file under the component's **private storage**, one secret
 * per file, in the dedicated `.secrets` directory the organisation invitation
 * key already established: outside the web root, refused when the private root
 * resolves inside it, refused when the path is a symbolic link, and refused
 * when the file's permissions are broader than the owner. That precedent is
 * followed rather than a second mechanism invented, because it is the one
 * place on a Joomla site this product has already proved it can keep bytes a
 * web request cannot fetch.
 *
 * **Nothing here ever writes.** A secret arrives by the site owner placing it,
 * which is what keeps the audit trail outside the application: a component
 * that could mint a gateway credential could also silently replace one.
 * Reading is all a verifier and a session opener need.
 *
 * **Every failure is null**, which is the fail-closed direction the port
 * states. An unknown scheme, a missing variable, an absent file, a file with
 * loose permissions and an unreadable private root are one answer, because
 * the only safe action is the same for all of them and telling them apart in
 * a return value invites a caller to proceed on one.
 *
 * @since 1.1.0
 */
final class JoomlaSecrets implements SecretsPort
{
    /** The dedicated directory, shared with the invitation key by design. */
    public const DIRECTORY = '.secrets';

    /** One secret per file. The suffix keeps a stray listing readable to a human. */
    public const SUFFIX = '.secret';

    /** Longer than any gateway credential and short enough that a stray file is not read into memory. */
    private const MAX_BYTES = 8192;

    public function __construct(
        private readonly JoomlaStorage $storage,
    ) {
    }

    public function resolve(string $ref): ?string
    {
        $parsed = SecretRef::parse($ref);

        if ($parsed === null) {
            return null;
        }

        return $parsed->isEnvironment()
            ? self::fromEnvironment($parsed->name)
            : $this->fromStore($parsed->name);
    }

    /**
     * getenv() first, because it is the only one of the three that sees a
     * variable set by the SAPI after the request started. Only the environment
     * is trusted, never request-derived server variables.
     */
    private static function fromEnvironment(string $name): ?string
    {
        $value = getenv($name);

        if (!\is_string($value) || $value === '') {
            $value = $_ENV[$name] ?? null;
        }

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function fromStore(string $name): ?string
    {
        try {
            if ($this->storage->isWebExposed()) {
                return null;
            }

            $root = realpath($this->storage->privateRoot());

            if ($root === false) {
                return null;
            }

            $path = $root . '/' . self::DIRECTORY . '/' . $name . self::SUFFIX;

            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                return null;
            }

            // A secret readable by every account on a shared host is not a
            // secret. Windows has no comparable mode, and the invitation key
            // makes the same exception for the same reason.
            if (PHP_OS_FAMILY !== 'Windows') {
                $mode = fileperms($path);

                if ($mode === false || ($mode & 0077) !== 0) {
                    return null;
                }
            }

            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                return null;
            }

            try {
                $contents = stream_get_contents($handle, self::MAX_BYTES);
            } finally {
                fclose($handle);
            }

            if (!\is_string($contents)) {
                return null;
            }

            // A trailing newline is what every editor and every "echo > file"
            // adds, and a signature computed over one is wrong in a way that
            // is very hard to see.
            $contents = trim($contents);

            return $contents === '' ? null : $contents;
        } catch (\Throwable) {
            return null;
        }
    }
}
