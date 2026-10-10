<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Port;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Http\HttpPolicy;
use Shondalai\VidyaLMS\Core\Port\Http\HttpRequest;
use Shondalai\VidyaLMS\Core\Port\Http\HttpResponse;
use Shondalai\VidyaLMS\Core\Port\Http\RefusingHttp;
use Shondalai\VidyaLMS\Core\Port\Secrets\NoSecrets;
use Shondalai\VidyaLMS\Tooling\Contract\HttpPortContract;
use Shondalai\VidyaLMS\Tooling\Contract\SecretsPortContract;

/**
 * The harness side of the two ports R2 added, which shipped with no
 * conformance contract at all.
 *
 * `tooling/contract` held a contract for DatabasePort and nothing for either
 * of these, so each adapter reinvented the parts that are not transport: the
 * two timeout constants, the host-match loop, the clamp and the classification
 * of a transport failure were all written twice, and a Drupal adapter would
 * have written them a third time. The first one written slightly differently
 * is the one where an allowlist entry stops meaning what it means elsewhere.
 */
final class PortContractsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 5) . '/tooling/contract/bootstrap.php';
    }

    public function testTheRefusingDefaultSatisfiesTheHttpPortContract(): void
    {
        self::assertSame([], HttpPortContract::failures(new RefusingHttp()));
    }

    public function testThePolicyHalfIsCheckableWithNoAdapterAtAll(): void
    {
        self::assertSame([], HttpPortContract::policyFailures());
    }

    public function testTheContractCatchesASuffixMatchingAllowlist(): void
    {
        // The failure the contract exists for, driven through a port that has
        // it: api.stripe.com must not admit notapi.stripe.com, which is a host
        // an attacker can register.
        $failures = HttpPortContract::failures(new SuffixMatchingHttp());

        self::assertNotSame([], $failures);
        self::assertStringContainsString(
            HttpPortContract::FORBIDDEN_HOST,
            implode(' ', $failures),
        );
    }

    public function testTheNoSecretsDefaultSatisfiesTheSecretsPortContract(): void
    {
        self::assertSame([], SecretsPortContract::failures(new NoSecrets()));
    }

    public function testTheContractCatchesAStoreThatResolvesAnUnprefixedValue(): void
    {
        // The one failure that matters most: without the rule a site owner can
        // paste the secret itself into provider_settings.secret_ref, and it
        // would work, which is worse than it failing.
        $failures = SecretsPortContract::failures(new PermissiveSecrets());

        self::assertNotSame([], $failures);
        self::assertStringContainsString('whsec_pasted_directly_into_the_column', implode(' ', $failures));
    }

    public function testTheTimeoutClampIsACeilingAndNeverAFloor(): void
    {
        self::assertSame(HttpPolicy::DEFAULT_TIMEOUT_SECONDS, HttpPolicy::timeoutFor(null));
        self::assertSame(5, HttpPolicy::timeoutFor(5, 30));
        self::assertSame(30, HttpPolicy::timeoutFor(600, 30));
        self::assertSame(HttpPolicy::MAX_TIMEOUT_SECONDS, HttpPolicy::timeoutFor(600, 9999));
    }
}
