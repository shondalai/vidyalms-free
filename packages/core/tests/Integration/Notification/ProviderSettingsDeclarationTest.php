<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\ManageProviderSettings;
use Shondalai\VidyaLMS\Core\Application\Notification\RecordDeliveryFeedback;
use Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationSuppressionRepository;
use Shondalai\VidyaLMS\Core\Provider\Notification\EmailNotificationChannel;
use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsFields;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;

/**
 * A provider that has configuration says so, and the screen can render it.
 *
 * The email channel's feedback secret is the case that named the defect: the
 * PUT accepted it, the inbound feedback route was closed without it, and the
 * GET returned no field for it because the field list was derived from the
 * stored row. Every assertion here is about a value nobody has stored yet.
 */
final class ProviderSettingsDeclarationTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testHostBillingPermissionIsCheckedBeforeProviderSettingsAreWritten(): void
    {
        $provider = new class implements \Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelInterface,
            \Shondalai\VidyaLMS\Core\Registry\Settings\AuthorizesSettingsWrite {
            public bool $allowed = false;
            public function key(): string { return 'host-billing'; }
            public function label(): string { return 'Host billing'; }
            public function send(\Shondalai\VidyaLMS\Core\Registry\Notification\Notification $notification,
                \Shondalai\VidyaLMS\Core\Registry\Context\SendContext $context): \Shondalai\VidyaLMS\Core\Registry\Notification\SendResult
            { throw new \LogicException('No mail in this test'); }
            public function requireSettingsWrite(\Shondalai\VidyaLMS\Core\Application\Shared\Principal $principal): void
            {
                if (!$this->allowed) {
                    throw \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException::forCapability('host.billing');
                }
            }
        };
        $this->fixture->kernel->registries->notificationChannels->register($provider);
        try {
            $this->fixture->kernel->manageProviderSettings->save($this->fixture->manager(), 'Notification',
                $provider->key(), 'site', 0, ['consumer_email' => 'victim@example.test']);
            self::fail('LMS management alone must not configure another system billing account');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException) {
            self::assertSame(0, $this->fixture->env->database->count(Query::from('provider_settings')->where('provider_key', '=', $provider->key())));
        }
        $provider->allowed = true;
        $this->fixture->kernel->manageProviderSettings->save($this->fixture->manager(), 'Notification',
            $provider->key(), 'site', 0, ['consumer_email' => 'approved@example.test']);
        self::assertSame(1, $this->fixture->env->database->count(Query::from('provider_settings')->where('provider_key', '=', $provider->key())));
    }

    public function testTheEmailChannelOffersItsFieldsBeforeAnythingIsStored(): void
    {
        $fields = $this->fieldsOf('Notification', EmailNotificationChannel::KEY);

        self::assertSame(
            [
                EnvelopeSender::SETTING_DOMAIN,
                EnvelopeSender::SETTING_PREFIX,
                RecordDeliveryFeedback::SETTING_SOFT_BOUNCE_THRESHOLD,
                'feedback_sns_topic_arn',
            ],
            array_keys($fields),
            'the channel must offer exactly the rows it reads',
        );

        self::assertArrayNotHasKey(
            RecordDeliveryFeedback::SETTING_SECRET,
            $fields,
            'a shared secret is a secret_ref and never a parameter field',
        );
    }

    /**
     * The shared secret never reaches the parameter bag, on this channel or
     * on any other.
     *
     * `write_only` masked the read path and stored the value in cleartext all
     * the same: a PUT carrying the raw value was accepted and the row read
     * back {"feedback_secret": "..."} on a table that is readable on every
     * shared-hosting install, two fields along from the `secret_ref` column
     * that refuses the same value by name. The refusal is what makes the two
     * columns answer one question one way.
     */
    public function testASecretIsRefusedInTheParameterBagAndNamesTheColumnThatTakesIt(): void
    {
        $canary = 'review-int-CANARY-sk_live_never_in_a_params_column';

        foreach (
            [
                RecordDeliveryFeedback::SETTING_SECRET,
                'api_key',
                'smtp_password',
                'signing_token',
            ] as $secretKey
        ) {
            try {
                $this->fixture->kernel->manageProviderSettings->save(
                    $this->fixture->manager(),
                    'Notification',
                    EmailNotificationChannel::KEY,
                    'site',
                    0,
                    [$secretKey => $canary, EnvelopeSender::SETTING_DOMAIN => 'mail.example.org'],
                );

                self::fail(sprintf('"%s" was stored in the parameter bag.', $secretKey));
            } catch (ValidationException $refusal) {
                self::assertStringContainsString('secret_ref', $refusal->errors[$secretKey] ?? '');
            }
        }

        $row = $this->fixture->env->database->selectOne(Query::from('provider_settings'));
        self::assertStringNotContainsString(
            $canary,
            $row === null ? '' : (string) json_encode($row, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The ordinary fields still save, so the refusal above is about secrets
     * and not about the save path.
     */
    public function testAnOrdinaryFieldStillSavesAndIsEchoedBack(): void
    {
        $this->fixture->kernel->manageProviderSettings->save(
            $this->fixture->manager(),
            'Notification',
            EmailNotificationChannel::KEY,
            'site',
            0,
            [EnvelopeSender::SETTING_DOMAIN => 'mail.example.org'],
        );

        $after = $this->fieldsOf('Notification', EmailNotificationChannel::KEY);
        self::assertSame('mail.example.org', $after[EnvelopeSender::SETTING_DOMAIN]['current']);
    }

    public function testSigningReferencesRejectMaterialAndUnrelatedEnvironmentVariables(): void
    {
        foreach (['key_ref', 'job_key_ref', 'feedback_secret_ref'] as $name) {
            foreach ([str_repeat('ab', 32), 'env:DB_PASSWORD', "store:KEY\n", ['seed' => 'private']] as $value) {
                try {
                    $this->fixture->kernel->manageProviderSettings->save($this->fixture->manager(),
                        'Notification', EmailNotificationChannel::KEY, 'site', 0, [$name => $value]);
                    self::fail('Invalid secret reference was persisted');
                } catch (ValidationException) {
                    self::assertSame(0, $this->fixture->env->database->count(Query::from('provider_settings')));
                }
            }
        }
        $this->fixture->kernel->manageProviderSettings->save($this->fixture->manager(),
            'Notification', EmailNotificationChannel::KEY, 'site', 0, ['key_ref' => 'store:BADGE_SIGNING']);
        self::assertSame('store:BADGE_SIGNING', $this->fieldsOf('Notification', EmailNotificationChannel::KEY)['key_ref']['current']);
    }

    public function testPreviouslyPastedSigningMaterialIsNotReturned(): void
    {
        $this->fixture->kernel->manageProviderSettings->save($this->fixture->manager(),
            'Notification', EmailNotificationChannel::KEY, 'site', 0, ['key_ref' => 'store:BADGE_SIGNING']);
        $this->fixture->env->database->update('provider_settings', [
            'params' => json_encode(['key_ref' => str_repeat('ab', 32)], JSON_THROW_ON_ERROR),
        ], ['provider_key' => EmailNotificationChannel::KEY]);
        self::assertNull($this->fieldsOf('Notification', EmailNotificationChannel::KEY)['key_ref']['current']);
    }

    /**
     * The declared default is the same number the code falls back to, so the
     * screen does not teach a value the engine does not use.
     */
    public function testTheDeclaredDefaultsMatchWhatTheCodeFallsBackTo(): void
    {
        $fields = $this->fieldsOf('Notification', EmailNotificationChannel::KEY);

        self::assertSame(
            EnvelopeSender::DEFAULT_PREFIX,
            $fields[EnvelopeSender::SETTING_PREFIX]['default'],
        );
        self::assertSame(
            SqlNotificationSuppressionRepository::DEFAULT_SOFT_BOUNCE_THRESHOLD,
            $fields[RecordDeliveryFeedback::SETTING_SOFT_BOUNCE_THRESHOLD]['default'],
        );
        self::assertSame(
            EmailNotificationChannel::SETTING_FEEDBACK_SECRET,
            RecordDeliveryFeedback::SETTING_SECRET,
        );
        self::assertSame(
            EmailNotificationChannel::SETTING_SOFT_BOUNCE_THRESHOLD,
            RecordDeliveryFeedback::SETTING_SOFT_BOUNCE_THRESHOLD,
        );
        self::assertSame(
            EmailNotificationChannel::DEFAULT_SOFT_BOUNCE_THRESHOLD,
            SqlNotificationSuppressionRepository::DEFAULT_SOFT_BOUNCE_THRESHOLD,
        );
    }

    public function testEveryConfigurablePolicyOffersItsOwnSettings(): void
    {
        $expected = [
            'consent' => ['default_granted'],
            'cross_rule' => ['window_hours'],
            'duplicate_window' => ['enabled'],
            'frequency_cap' => ['max_per_day'],
            'quiet_hours' => ['enabled', 'start', 'end'],
        ];

        foreach ($expected as $providerKey => $keys) {
            self::assertSame(
                $keys,
                array_keys($this->fieldsOf('NotificationPolicy', $providerKey)),
                $providerKey . ' does not offer the rows it reads',
            );
        }
    }

    /**
     * A policy holds a repository and no translator, so it names catalogue
     * keys; the route is what turns them into copy. A key the catalogue does
     * not define would reach the screen as a raw dotted string.
     */
    public function testEveryDeclaredStringIsTranslatedCopyAndNotAKey(): void
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';

        // Read through a route holding the real catalogue rather than the
        // key-echoing stub, because a key that survives translation is
        // exactly what a site owner would read on the screen.
        $route = new ManageProviderSettings(
            $this->fixture->kernel->registries,
            $this->fixture->kernel->providerSettings,
            $this->fixture->kernel->guard,
            new CatalogueTranslator(),
        );

        foreach (['Notification', 'NotificationPolicy'] as $registryKey) {
            /** @var list<array<string, mixed>> $providers */
            $providers = $route->list($this->fixture->manager(), $registryKey, 'site', 0)['providers'];

            foreach ($providers as $provider) {
                /** @var list<array<string, mixed>> $groups */
                $groups = $provider['groups'];

                foreach ($groups as $group) {
                    /** @var list<array<string, mixed>> $groupFields */
                    $groupFields = $group['fields'];

                    foreach ($groupFields as $field) {
                        foreach (['label', 'description'] as $member) {
                            $value = $field[$member] ?? null;

                            if (!\is_string($value)) {
                                continue;
                            }

                            self::assertStringStartsNotWith('vidyalms.', $value, sprintf(
                                '%s/%s field "%s" reached the screen as an untranslated key',
                                $registryKey,
                                (string) $provider['key'],
                                (string) $field['key'],
                            ));
                        }
                    }
                }
            }
        }

        foreach ($this->rawDeclarations() as $label => $group) {
            foreach ($group['fields'] as $field) {
                foreach (['label', 'description'] as $member) {
                    $value = $field[$member] ?? null;

                    if (!\is_string($value) || !str_starts_with($value, 'vidyalms.')) {
                        continue;
                    }

                    self::assertArrayHasKey($value, $catalogue, sprintf(
                        '%s names "%s"; the catalogue does not define it.',
                        $label,
                        $value,
                    ));
                }
            }
        }
    }

    /**
     * Every declared type is one the contract's SettingsField enumeration
     * carries, including the types derived from a stored value: the route
     * used to answer "text", which no form is written against.
     */
    public function testEveryFieldTypeIsOneTheContractDeclares(): void
    {
        $allowed = [
            SettingsFields::TYPE_BOOLEAN,
            SettingsFields::TYPE_INTEGER,
            SettingsFields::TYPE_NUMBER,
            SettingsFields::TYPE_STRING,
            SettingsFields::TYPE_DATETIME,
            SettingsFields::TYPE_DURATION,
            SettingsFields::TYPE_ENUM,
            SettingsFields::TYPE_OBJECT,
        ];

        $this->fixture->kernel->manageProviderSettings->save(
            $this->fixture->manager(),
            'NotificationPolicy',
            'cross_rule',
            'site',
            0,
            ['window_hours' => 6, 'an_orphan' => 'left by a removed package'],
        );

        foreach (['Notification', 'NotificationPolicy', 'Audience', 'TokenResolver'] as $registryKey) {
            foreach ($this->providers($registryKey) as $provider) {
                foreach ($provider['groups'] as $group) {
                    foreach ($group['fields'] as $field) {
                        self::assertContains($field['type'], $allowed, sprintf(
                            '%s/%s declares the type "%s"',
                            $registryKey,
                            (string) $provider['key'],
                            (string) $field['type'],
                        ));
                    }
                }
            }
        }
    }

    /**
     * A value stored under a key nothing declares stays visible, so a row
     * written by a package that has since been removed can be found and
     * cleared rather than silently orphaned.
     */
    public function testAStoredKeyNothingDeclaresIsStillReported(): void
    {
        $this->fixture->kernel->manageProviderSettings->save(
            $this->fixture->manager(),
            'NotificationPolicy',
            'cross_rule',
            'site',
            0,
            ['window_hours' => 6, 'an_orphan' => 'left by a removed package'],
        );

        $fields = $this->fieldsOf('NotificationPolicy', 'cross_rule');

        self::assertSame(6, $fields['window_hours']['current']);
        self::assertSame('left by a removed package', $fields['an_orphan']['current']);
    }

    /**
     * A provider with nothing to configure reports no group at all, which is
     * what the contract says an empty groups array means and what the screen
     * renders as "none".
     */
    public function testAProviderWithNoConfigurationReportsNoGroup(): void
    {
        $providers = $this->providers('TokenResolver');

        self::assertNotSame([], $providers);

        foreach ($providers as $provider) {
            self::assertSame([], $provider['groups'], (string) $provider['key']);
        }
    }

    /**
     * A registry becomes configurable when it is seated, not when
     * ManageProviderSettings is edited.
     *
     * Learning reports and shop catalogue providers share this configuration path.
     */
    public function testEverySeatedRegistryIsConfigurableAndTheStoredKeysAreUnchanged(): void
    {
        // The nine keys that predate the derivation. registry_key is a stored
        // column, so a change here orphans every configured row on every site.
        foreach (
            [
            'AvailabilityCondition',
            'ScheduleAnchor',
            'LatePolicy',
            'NotificationTrigger',
            'NotificationPolicy',
            'Audience',
            'DeliveryFeedbackMapper',
            'TokenResolver',
            'Notification',
            ] as $legacy
        ) {
            $listed = $this->listing($legacy);
            self::assertSame($legacy, $listed['registry_key']);
            self::assertNotSame([], $listed['providers'], $legacy . ' stopped resolving');
        }

        // And the seams the wave seated, which used to be 404s.
        foreach (
            [
            'ExportFormat',
            'ReportDelivery',
            'CommerceSource',
            ] as $seam
        ) {
            $listed = $this->listing($seam);
            self::assertSame($seam, $listed['registry_key']);
            self::assertIsArray($listed['providers']);
        }

        // A name nothing seats is still a 404, so the route did not become a
        // directory of arbitrary strings.
        $this->expectException(EntityNotFoundException::class);
        $this->listing('NoSuchRegistry');
    }

    /**
     * @return array<string, mixed>
     */
    private function listing(string $registryKey): array
    {
        return $this->fixture->kernel->manageProviderSettings->list(
            $this->fixture->manager(),
            $registryKey,
            'site',
            0,
        );
    }

    /**
     * The providers' own declarations, before the route translates them.
     *
     * @return array<string, array{fields: list<array<string, mixed>>}>
     */
    private function rawDeclarations(): array
    {
        $registries = $this->fixture->kernel->registries;
        $context = SettingsDeclarationContext::create('Notification', 'site', 0);
        $raw = [];

        foreach ([$registries->notificationChannels->all(), $registries->notificationPolicies->all()] as $providers) {
            foreach ($providers as $provider) {
                if ($provider instanceof DeclaresSettings) {
                    $raw[$provider::class] = ['fields' => $provider->settingsGroup($context)['fields']];
                }
            }
        }

        return $raw;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fieldsOf(string $registryKey, string $providerKey): array
    {
        foreach ($this->providers($registryKey) as $provider) {
            if ($provider['key'] !== $providerKey) {
                continue;
            }

            $fields = [];

            foreach ($provider['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    $fields[(string) $field['key']] = $field;
                }
            }

            return $fields;
        }

        self::fail(sprintf('%s has no provider %s', $registryKey, $providerKey));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function providers(string $registryKey): array
    {
        $listed = $this->fixture->kernel->manageProviderSettings->list(
            $this->fixture->manager(),
            $registryKey,
            'site',
            0,
        );

        /** @var list<array<string, mixed>> $providers */
        $providers = $listed['providers'];

        return $providers;
    }
}
