<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Commerce;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Commerce\ProviderSecrets;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Secrets\NoSecrets;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretRef;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The two columns whose whole purpose is that a gateway package does not
 * invent its own credential handling.
 *
 * `provider_settings.secret_ref` and `provider_settings.enabled` shipped with
 * no reader and no writer anywhere in the core: find() selected `params`
 * alone, save() wrote four columns, and SecretsPort::resolve had zero callers.
 * A leaked webhook signing secret lets an attacker forge entitlement grants,
 * so a column that holds a pointer instead of a value only helps if something
 * reads the pointer.
 */
final class ProviderSecretsTest extends TestCase
{
    private const REGISTRY = 'WebhookIntake';
    private const PROVIDER = 'acme-pay';

    public function testTheReferenceIsWrittenReadBackAndResolvedThroughThePort(): void
    {
        $env = TestPorts::create();
        $settings = new SqlProviderSettingsRepository($env->database, $env->clock);

        $settings->save(
            self::REGISTRY,
            self::PROVIDER,
            SqlProviderSettingsRepository::SCOPE_SITE,
            0,
            ['mode' => 'live'],
            secretRef: 'env:VIDYALMS_SECRET_ACME_WEBHOOK_SECRET',
        );

        $record = $settings->record(self::REGISTRY, self::PROVIDER, SqlProviderSettingsRepository::SCOPE_SITE, 0);
        self::assertNotNull($record);
        self::assertSame('env:VIDYALMS_SECRET_ACME_WEBHOOK_SECRET', $record->secretRef);
        self::assertSame(['mode' => 'live'], $record->params);
        self::assertTrue($record->enabled, 'a provider nobody switched off is on');

        $resolved = new ProviderSecrets($settings, new StubSecrets(['env:VIDYALMS_SECRET_ACME_WEBHOOK_SECRET' => 'whsec_live']));
        self::assertSame('whsec_live', $resolved->for(self::REGISTRY, self::PROVIDER));

        // The whole point of the column: the table holds a pointer and never a
        // value, so a readable table on shared hosting leaks nothing.
        $row = $env->database->selectOne(
            \Shondalai\VidyaLMS\Core\Port\Database\Query::from('provider_settings'),
        );
        self::assertNotNull($row);
        self::assertStringNotContainsString('whsec_live', json_encode($row, \JSON_THROW_ON_ERROR));
    }

    public function testASavedParameterBagDoesNotBlankAStoredReference(): void
    {
        $env = TestPorts::create();
        $settings = new SqlProviderSettingsRepository($env->database, $env->clock);

        $settings->save(self::REGISTRY, self::PROVIDER, 'site', 0, [], secretRef: 'store:ACME_KEY');
        $settings->save(self::REGISTRY, self::PROVIDER, 'site', 0, ['mode' => 'test']);

        self::assertSame(
            'store:ACME_KEY',
            $settings->record(self::REGISTRY, self::PROVIDER, 'site', 0)?->secretRef,
            'a settings save blanked the signing secret pointer',
        );
    }

    public function testADisabledProviderResolvesNothing(): void
    {
        $env = TestPorts::create();
        $settings = new SqlProviderSettingsRepository($env->database, $env->clock);
        $settings->save(self::REGISTRY, self::PROVIDER, 'site', 0, [], secretRef: 'env:VIDYALMS_SECRET_ACME', enabled: false);

        self::assertFalse($settings->isEnabled(self::REGISTRY, self::PROVIDER));

        // A switch that leaves the credential resolvable turns nothing off.
        $resolved = new ProviderSecrets($settings, new StubSecrets(['env:VIDYALMS_SECRET_ACME' => 'whsec']));
        self::assertNull($resolved->for(self::REGISTRY, self::PROVIDER));
    }

    public function testEveryFailureIsTheSameFailClosedNull(): void
    {
        $env = TestPorts::create();
        $settings = new SqlProviderSettingsRepository($env->database, $env->clock);
        $secrets = new ProviderSecrets($settings, new NoSecrets());

        // No row at all.
        self::assertNull($secrets->for(self::REGISTRY, self::PROVIDER));

        // A row with no reference.
        $settings->save(self::REGISTRY, self::PROVIDER, 'site', 0, ['mode' => 'live']);
        self::assertNull($secrets->for(self::REGISTRY, self::PROVIDER));

        // A reference the grammar refuses: an unprefixed value would let a
        // site owner paste the secret itself into the column, and it working
        // would be worse than it failing.
        self::assertNull(SecretRef::parse('whsec_pasted_here'));
        $settings->save(self::REGISTRY, self::PROVIDER, 'site', 0, [], secretRef: 'whsec_pasted_here');
        self::assertNull($secrets->for(self::REGISTRY, self::PROVIDER));

        // A well-formed reference no host can resolve.
        $settings->save(self::REGISTRY, self::PROVIDER, 'site', 0, [], secretRef: 'env:VIDYALMS_SECRET_NOT_SET_ANYWHERE');
        self::assertNull($secrets->for(self::REGISTRY, self::PROVIDER));
    }

    public function testTheNarrowestScopeWins(): void
    {
        $env = TestPorts::create();
        $settings = new SqlProviderSettingsRepository($env->database, $env->clock);
        $settings->save(self::REGISTRY, self::PROVIDER, 'site', 0, [], secretRef: 'env:VIDYALMS_SECRET_SITE_WIDE');
        $settings->save(self::REGISTRY, self::PROVIDER, 'course', 7, [], secretRef: 'env:VIDYALMS_SECRET_PER_COURSE');

        $secrets = new ProviderSecrets($settings, new StubSecrets([
            'env:VIDYALMS_SECRET_SITE_WIDE' => 'site',
            'env:VIDYALMS_SECRET_PER_COURSE' => 'course',
        ]));

        self::assertSame('site', $secrets->for(self::REGISTRY, self::PROVIDER));
        self::assertSame('course', $secrets->for(self::REGISTRY, self::PROVIDER, ['course' => 7]));
    }
}
