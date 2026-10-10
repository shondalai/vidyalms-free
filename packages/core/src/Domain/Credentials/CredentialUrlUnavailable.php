<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * A credential id or a status list address was asked for on a site that has
 * configured neither.
 *
 * The two addresses are kernel options the host supplies at boot, and they
 * are options rather than something composed from the request for one
 * reason: issuance and bulk reissue are scheduled work. A task has no request
 * behind it, so a credential minted by cron used to come out carrying an id
 * like `http://localhost/cli/script.php/index.php/...`, which is
 * unresolvable, in a document that cannot be corrected once a wallet holds it
 * and cannot be corrected at all once it is signed.
 *
 * So the miss is named rather than papered over. An empty id is not an IRI,
 * `id` is a required member of the credential, and a document emitted with a
 * blank one fails the schema it claims to be one signature away from. The
 * refusal names the option the host has to supply.
 */
final class CredentialUrlUnavailable extends \RuntimeException
{
    public const OPTION_CREDENTIAL_BASE = 'credential_base_url';
    public const OPTION_STATUS_LIST_BASE = 'status_list_base_url';

    private function __construct(public readonly string $option, string $message)
    {
        parent::__construct($message);
    }

    public static function credentialBase(): self
    {
        return new self(
            self::OPTION_CREDENTIAL_BASE,
            'No credential base address is configured, so a credential id cannot be minted. '
            . 'The host supplies it to the kernel as the "credential_base_url" option, '
            . 'which defaults from "verification_base_url".',
        );
    }

    public static function statusListBase(): self
    {
        return new self(
            self::OPTION_STATUS_LIST_BASE,
            'No status list base address is configured, so a status list address cannot be minted. '
            . 'The host supplies it to the kernel as the "status_list_base_url" option, '
            . 'which defaults from "credential_base_url".',
        );
    }
}
