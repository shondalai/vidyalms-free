<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Port\Secrets\SecretRef;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;

/**
 * What every implementation of SecretsPort must answer identically: NoSecrets
 * in the core, JoomlaSecrets on Joomla, WpSecrets on WordPress.
 *
 * The two shipped store backends differ fundamentally, and deliberately so:
 * JoomlaSecrets keeps a file, WpSecrets seals AES-256-GCM under an HKDF of the
 * site's own salt. That is exactly why a conformance kit is needed here rather
 * than a shared base class. The **grammar and the miss direction** are the
 * contract; where a host keeps the bytes is the host's business.
 *
 * **The miss is fail closed and it is a single null.** An unknown scheme, a
 * malformed reference, an unset variable and a store the host does not have
 * all answer null, because the only safe action is the same in every case and
 * distinguishing them in the return value invites a caller to proceed on one
 * of them. A verifier handed an empty string instead of a refusal accepts
 * every forged delivery on a site whose configuration has drifted.
 *
 * **An unprefixed value never resolves.** That is the whole reason the column
 * holds a pointer: without the rule a site owner can paste the secret itself
 * into `provider_settings.secret_ref`, and it would work, which is worse than
 * it failing.
 *
 * It reads nothing the caller did not put there and writes nothing at all, so
 * it is safe to run against a live site.
 */
final class SecretsPortContract
{
    /** @var list<string> */
    private array $failures = [];

    private function __construct(
        private readonly SecretsPort $port,
    ) {
    }

    /**
     * @param ?string $resolvableRef a reference this site genuinely resolves,
     *                               when the caller has arranged one. Absent,
     *                               only the refusals are checked, which is
     *                               every implementation's whole contract on a
     *                               site that has configured no secret.
     * @param ?string $expectedValue what that reference must resolve to
     *
     * @return list<string> one line per broken expectation
     */
    public static function failures(
        SecretsPort $port,
        ?string $resolvableRef = null,
        ?string $expectedValue = null,
    ): array {
        $contract = new self($port);
        $contract->run($resolvableRef, $expectedValue);

        return $contract->failures;
    }

    private function run(?string $resolvableRef, ?string $expectedValue): void
    {
        $this->checkGrammar();

        // Every refusal is the same refusal. A caller may only ever branch on
        // "I have a secret" against "I do not".
        foreach ([
            'whsec_pasted_directly_into_the_column',
            'file:/etc/passwd',
            'env:',
            'env:../../etc/passwd',
            'store:with spaces',
            'env:NOTHING_ON_THIS_HOST_IS_CALLED_THIS_' . self::class,
            '',
        ] as $ref) {
            $this->same(
                null,
                $this->port->resolve($ref),
                sprintf('resolve("%s") answered something rather than refusing', $ref),
            );
        }

        if ($resolvableRef === null) {
            return;
        }

        $this->same(
            $expectedValue,
            $this->port->resolve($resolvableRef),
            'a reference this site was told it can resolve did not resolve',
        );
    }

    private function checkGrammar(): void
    {
        // The grammar is published once so three adapters parse a reference
        // identically. An adapter that accepted more would decide for itself
        // what a path traversal looks like in its own store.
        $this->true(SecretRef::parse('env:VIDYALMS_SECRET_ACME') !== null, 'a well-formed env reference does not parse');
        $this->true(SecretRef::parse('store:ACME_SECRET') !== null, 'a well-formed store reference does not parse');
        $this->true(SecretRef::parse('env:VIDYALMS_SECRET_ACME')?->isEnvironment() === true, 'the env scheme is not reported');
        $this->same('env:VIDYALMS_SECRET_ACME', (string) SecretRef::parse('env:VIDYALMS_SECRET_ACME'), 'a reference does not round trip');

        foreach ([
            'whsec_pasted_directly',
            'vault:ACME',
            'env:',
            'env:../escape',
            'env:has space',
            'ENV:ACME',
        ] as $refused) {
            $this->true(
                SecretRef::parse($refused) === null,
                sprintf('"%s" parsed, and it must not', $refused),
            );
        }
    }

    private function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected === $actual) {
            return;
        }

        $this->failures[] = sprintf(
            '%s: expected %s, got %s',
            $what,
            json_encode($expected),
            json_encode($actual),
        );
    }

    private function true(bool $condition, string $what): void
    {
        if (!$condition) {
            $this->failures[] = $what;
        }
    }
}
