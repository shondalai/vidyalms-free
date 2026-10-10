<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Port;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Http\HttpPort;
use Shondalai\VidyaLMS\Core\Port\Http\HttpRequest;
use Shondalai\VidyaLMS\Core\Port\Http\HttpResponse;
use Shondalai\VidyaLMS\Core\Port\Http\RefusingHttp;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Port\Secrets\NoSecrets;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretRef;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The two ports commerce cannot be built without, and the direction both of
 * their defaults refuse in.
 */
final class OutboundPortsTest extends TestCase
{
    public function testBothAreDefaultedSoAnAdapterThatPredatesThemStillBoots(): void
    {
        $ports = TestPorts::create()->ports;

        self::assertInstanceOf(HttpPort::class, $ports->http);
        self::assertInstanceOf(SecretsPort::class, $ports->secrets);

        // Which is the whole reason they are trailing and defaulted: a
        // required new member on this object turns a core upgrade into a fatal
        // on every site whose adapter has not been rebuilt.
        $reflection = new \ReflectionMethod(Ports::class, '__construct');
        $parameters = $reflection->getParameters();
        $last = \array_slice($parameters, -2);

        self::assertSame(['http', 'secrets'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $last,
        ));

        foreach ($last as $parameter) {
            self::assertTrue($parameter->isDefaultValueAvailable(), $parameter->getName());
        }
    }

    public function testTheHttpDefaultFailsClosedRatherThanSilentlyOffline(): void
    {
        $http = new RefusingHttp();
        $response = $http->request(HttpRequest::create('POST', 'https://api.example.test/v1/charges'));

        self::assertFalse($response->wasMade());
        self::assertFalse($response->isSuccessful());
        self::assertSame(HttpResponse::REFUSED_NO_IMPLEMENTATION, $response->refusedReason);
        // A settings screen asking whether a configured gateway is reachable
        // gets a truthful no rather than an optimistic yes.
        self::assertFalse($http->allows('api.example.test'));
    }

    public function testAnOutboundRequestIsHttpsAndCarriesNoCredentialInClear(): void
    {
        $request = HttpRequest::create(
            'POST',
            'https://api.example.test/v1/charges',
            ['Idempotency-Key' => 'ORD-1001:session'],
            '{}',
            timeoutSeconds: 10,
            retryable: true,
        );

        self::assertSame('api.example.test', $request->host());
        self::assertSame('ORD-1001:session', $request->header('idempotency-key'));
        self::assertTrue($request->retryable);

        $this->expectException(\InvalidArgumentException::class);
        HttpRequest::create('POST', 'http://api.example.test/v1/charges');
    }

    public function testARefusalIsAResponseAndNeverAnException(): void
    {
        // A reconciliation pass must record one failed call rather than stop,
        // and a gateway package must be able to tell "this site will not call
        // that host" from "the remote said 500" without matching on messages.
        $refused = HttpResponse::refused(HttpResponse::REFUSED_HOST_NOT_ALLOWED);
        $answered = HttpResponse::of(500, 'oops');

        self::assertSame(0, $refused->status);
        self::assertFalse($refused->wasMade());
        self::assertTrue($answered->wasMade());
        self::assertNull($answered->refusedReason);
        self::assertFalse($answered->isSuccessful());
    }

    public function testTheSecretGrammarIsTwoSchemesAndNothingElse(): void
    {
        self::assertSame(['env', 'store'], SecretsPort::SCHEMES);

        $env = SecretRef::parse('env:VIDYALMS_SECRET_STRIPE_WEBHOOK_SECRET');
        self::assertNotNull($env);
        self::assertTrue($env->isEnvironment());
        self::assertSame('VIDYALMS_SECRET_STRIPE_WEBHOOK_SECRET', $env->name);

        $store = SecretRef::parse('store:stripe_webhook');
        self::assertNotNull($store);
        self::assertFalse($store->isEnvironment());
        self::assertSame('store:stripe_webhook', (string) $store);
    }

    public function testAnUnprefixedValueDoesNotResolveSoASecretCannotBePastedIntoTheColumn(): void
    {
        // This is the failure that matters: if a bare value resolved, a site
        // owner pasting the secret itself into provider_settings.secret_ref
        // would work, and the column exists precisely so that it cannot.
        self::assertNull(SecretRef::parse('whsec_abc123'));
        self::assertNull(SecretRef::parse('file:/etc/passwd'));
        self::assertNull(SecretRef::parse('env:../../etc/passwd'));
        self::assertNull(SecretRef::parse('env:'));
        self::assertNull(SecretRef::parse('env:DB_PASSWORD'));
        self::assertNull(SecretRef::parse('env:AWS_SECRET_ACCESS_KEY'));
        self::assertNull(SecretRef::parse('env:VIDYALMS_DATABASE_PASSWORD'));
    }

    public function testTheSecretsDefaultResolvesNothingSoAVerifierRefusesRatherThanVerifiesAgainstNothing(): void
    {
        $secrets = new NoSecrets();

        self::assertNull($secrets->resolve('env:VIDYALMS_SECRET_STRIPE_WEBHOOK_SECRET'));
        self::assertNull($secrets->resolve('store:anything'));
    }
}
