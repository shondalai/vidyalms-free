<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Provider\ExportFormat\CsvExportFormat;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatRegistry;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/** Provider settings use the published registry key for both writes and reads. */
final class ProviderRegistryKeyTest extends TestCase
{
    /**
     * The two stored spellings that predate every derivation and may not move.
     *
     * @var list<string>
     */
    private const LEGACY_PINNED = ['audience', 'notification_channel'];

    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    /**
     * A save through the shipped use case is resolvable through the resolver
     * the export providers use, under the key those families name.
     */
    public function testASavedReferenceResolvesUnderThePublishedExtensionPoint(): void
    {
        $this->fixture->kernel->manageProviderSettings->save(
            $this->fixture->manager(),
            ExportFormatRegistry::EXTENSION_POINT,
            CsvExportFormat::KEY,
            'site',
            0,
            [],
            'env:VIDYALMS_SECRET_VIDYALMS_TEST_PROVIDER_SECRET',
            false,
        );

        $settings = $this->fixture->kernel->providerSettings;

        self::assertSame(
            'env:VIDYALMS_SECRET_VIDYALMS_TEST_PROVIDER_SECRET',
            $settings->secretRef(ExportFormatRegistry::EXTENSION_POINT, CsvExportFormat::KEY),
        );
        self::assertFalse(
            $settings->isEnabled(ExportFormatRegistry::EXTENSION_POINT, CsvExportFormat::KEY),
            'an export provider switched off through the route stays off for the family that reads the row',
        );
    }

    /** The derived alias an existing screen sends reaches the same row. */
    public function testTheDerivedAliasWritesAndReadsTheCanonicalRow(): void
    {
        $this->fixture->kernel->manageProviderSettings->save(
            $this->fixture->manager(),
            'ExportFormat',
            CsvExportFormat::KEY,
            'site',
            0,
            [],
            'store:export_secret',
        );

        self::assertSame(
            'store:export_secret',
            $this->fixture->kernel->providerSettings->secretRef(
                ExportFormatRegistry::EXTENSION_POINT,
                CsvExportFormat::KEY,
            ),
        );
    }

    /**
     * Every seated registry that publishes an extension point is configurable
     * under it, so a provider package never has to guess a second spelling.
     */
    public function testEveryPublishedExtensionPointIsAReachableRegistryKey(): void
    {
        $registries = $this->fixture->kernel->registries;
        $checked = 0;

        foreach (get_object_vars($registries) as $registry) {
            if (!$registry instanceof AbstractProviderRegistry) {
                continue;
            }

            if (!\defined($registry::class . '::EXTENSION_POINT')) {
                continue;
            }

            $point = \constant($registry::class . '::EXTENSION_POINT');
            self::assertIsString($point);

            if (\in_array($point, self::LEGACY_PINNED, true)) {
                // Two spellings are in databases and may not move, which the
                // use case states and which this asserts rather than assumes.
                continue;
            }

            $listed = $this->fixture->kernel->manageProviderSettings->list(
                $this->fixture->manager(),
                $point,
                'site',
                0,
            );

            self::assertSame($point, $listed['registry_key'], $registry::class);
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
    }
}
