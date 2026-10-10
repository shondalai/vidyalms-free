<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Provider\ExportFormat\CsvExportFormat;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatRegistry;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/** Provider references and enabled state round-trip through the settings API. */
final class ProviderSecretReferenceTest extends TestCase
{
    /**
     * What a screen sends, which is the derived alias the export settings screen
     * was written against.
     */
    private const REQUESTED = 'ExportFormat';

    /**
     * What the row is stored under, which is the registry's own published
     * extension point and the key every family and the provider resolver read.
     *
     * The two used to be the same string and the row was written under the
     * alias, so a saved reference resolved null and a provider switched off
     * stayed on, because an absent row is enabled.
     */
    private const REGISTRY = ExportFormatRegistry::EXTENSION_POINT;

    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testAReferenceIsStoredAndReportedAsSetWithoutBeingEchoed(): void
    {
        $before = $this->provider();

        self::assertFalse($before['secret_ref_set']);
        self::assertTrue($before['enabled'], 'a provider nobody has configured is on');

        $this->save(['secret_ref' => 'env:VIDYALMS_SECRET_VIDYALMS_TEST_PROVIDER_SECRET']);

        $after = $this->provider();

        self::assertTrue($after['secret_ref_set']);
        self::assertNotContains(
            'env:VIDYALMS_SECRET_VIDYALMS_TEST_PROVIDER_SECRET',
            array_map(static fn (mixed $value): string => var_export($value, true), $after),
            'the stored reference was echoed back to the screen that wrote it',
        );
    }

    public function testTheStoredReferenceIsTheOneProviderSecretsResolves(): void
    {
        $this->save(['secret_ref' => 'env:VIDYALMS_SECRET_VIDYALMS_TEST_PROVIDER_SECRET']);

        self::assertSame(
            'env:VIDYALMS_SECRET_VIDYALMS_TEST_PROVIDER_SECRET',
            $this->fixture->kernel->providerSettings->secretRef(self::REGISTRY, CsvExportFormat::KEY),
            'the settings route and the secret resolver must read one row',
        );
    }

    public function testASecretValuePastedInsteadOfAReferenceIsRefusedByName(): void
    {
        $this->expectException(ValidationException::class);

        $this->fixture->kernel->manageProviderSettings->save(
            $this->fixture->manager(),
            self::REGISTRY,
            CsvExportFormat::KEY,
            'site',
            0,
            [],
            'whsec_a_real_looking_signing_secret',
        );
    }

    public function testAnUnknownSchemeIsRefusedAndAnEmptyTailIsNotAReference(): void
    {
        foreach (['file:/etc/secret', 'env:', 'store:'] as $ref) {
            try {
                $this->fixture->kernel->manageProviderSettings->save(
                    $this->fixture->manager(),
                    self::REGISTRY,
                    CsvExportFormat::KEY,
                    'site',
                    0,
                    [],
                    $ref,
                );

                self::fail(sprintf('"%s" was accepted as a secret reference.', $ref));
            } catch (ValidationException $refusal) {
                self::assertStringContainsString('env:VIDYALMS_SECRET_NAME', $refusal->errors['secret_ref'] ?? '');
            }
        }
    }

    public function testDisablingAProviderIsStoredAndSurvivesASaveThatDoesNotMentionIt(): void
    {
        $this->save(['enabled' => false]);

        self::assertFalse($this->provider()['enabled']);
        self::assertFalse(
            $this->fixture->kernel->providerSettings->isEnabled(self::REGISTRY, CsvExportFormat::KEY),
        );

        $this->save(['params' => []]);

        self::assertFalse(
            $this->provider()['enabled'],
            'a save that says nothing about the switch must not turn the provider back on',
        );
    }

    public function testASaveThatOmitsTheReferenceKeepsTheStoredOne(): void
    {
        $this->save(['secret_ref' => 'store:export_secret']);
        $this->save(['enabled' => true]);

        self::assertSame(
            'store:export_secret',
            $this->fixture->kernel->providerSettings->secretRef(self::REGISTRY, CsvExportFormat::KEY),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function save(array $body): void
    {
        $response = $this->fixture->api->handle(new ApiRequest(
            'PUT',
            '/manage/providers/' . self::REQUESTED . '/' . CsvExportFormat::KEY . '/settings',
            $this->fixture->manager(),
            [],
            $body,
            'secret-ref-' . bin2hex(random_bytes(6)),
        ));

        self::assertSame(200, $response->status, (string) json_encode($response->data));
    }

    /**
     * @return array<string, mixed>
     */
    private function provider(): array
    {
        $listed = $this->fixture->kernel->manageProviderSettings->list(
            $this->manager(),
            self::REGISTRY,
            'site',
            0,
        );

        /** @var list<array<string, mixed>> $providers */
        $providers = $listed['providers'];

        foreach ($providers as $provider) {
            if ($provider['key'] === CsvExportFormat::KEY) {
                return $provider;
            }
        }

        self::fail('The CSV provider is not in the ExportFormat registry.');
    }

    private function manager(): Principal
    {
        return $this->fixture->manager();
    }
}
