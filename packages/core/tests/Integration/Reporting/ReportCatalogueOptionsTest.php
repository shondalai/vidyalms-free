<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Boot\KernelReady;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The catalogue publishes the registered export formats and delivery providers
 * beside the reports.
 *
 * Without them an export control can only hold its own list of formats, which
 * is a screen that has to be edited the day a package registers `xlsx`, and
 * that edit is the one the registry exists to remove. The test that matters is
 * therefore the third case: a format registered from a `KernelReady` listener
 * appears here with nothing in the core naming it.
 */
final class ReportCatalogueOptionsTest extends TestCase
{
    private const MANAGER = 1;

    private CoreKernel $kernel;
    private ApiKernel $api;

    protected function setUp(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $env->authorization->grant(self::MANAGER, Capability::VIEW_REPORTS);
        $env->users->add(self::MANAGER, 'Mo Manager', 'mo@example.test');

        $this->kernel = new CoreKernel($env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);
        $this->api = $this->kernel->apiKernel();
    }

    public function testTheFreeFormatsTravelWithTheCatalogueInGroupAndOrder(): void
    {
        $data = $this->catalogue();

        $keys = array_map(static fn (array $format): string => (string) $format['key'], $data['formats']);
        self::assertContains('csv', $keys);
        self::assertContains('json', $keys);

        foreach ($data['formats'] as $format) {
            self::assertArrayHasKey('label', $format);
            self::assertArrayHasKey('group', $format);
            self::assertArrayHasKey('extension', $format);
            self::assertArrayHasKey('mime_type', $format);
        }
    }

    /**
     * Published rather than inferred, because the refusal it drives has to be
     * visible to the screen that offers the choice and not only to the use case
     * that refuses it afterwards.
     */
    public function testEveryDeliverySaysWhetherItAttachesTheArtefact(): void
    {
        $data = $this->catalogue();

        $keys = array_map(static fn (array $delivery): string => (string) $delivery['key'], $data['deliveries']);
        self::assertContains('download', $keys);
        self::assertContains('notification-link', $keys);

        foreach ($data['deliveries'] as $delivery) {
            // Neither free provider attaches: both hand back a signed expiring
            // URL, because revoking access revokes a link and does not revoke
            // an attachment sitting in an inbox.
            self::assertFalse($delivery['attaches']);
        }
    }

    public function testAFormatRegisteredByAPackageAppearsWithNothingInTheCoreNamingIt(): void
    {
        $before = array_map(
            static fn (array $format): string => (string) $format['key'],
            $this->catalogue()['formats'],
        );
        self::assertNotContains(ThrowawayExportFormat::KEY, $before);

        $ready = new KernelReady($this->kernel);
        (static function (KernelReady $event): void {
            $event->registries()->exportFormats->register(new ThrowawayExportFormat());
        })($ready);

        $after = $this->catalogue()['formats'];
        $keys = array_map(static fn (array $format): string => (string) $format['key'], $after);

        self::assertContains(ThrowawayExportFormat::KEY, $keys);

        foreach ($after as $format) {
            if ($format['key'] === ThrowawayExportFormat::KEY) {
                self::assertSame('spreadsheet', $format['group']);
                self::assertSame('tsv', $format['extension']);

                return;
            }
        }

        self::fail('the registered format is not in the catalogue');
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogue(): array
    {
        $response = $this->api->handle(new ApiRequest(
            'GET',
            '/manage/reports',
            Principal::user(self::MANAGER),
        ));

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertArrayHasKey('formats', $response->data);
        self::assertArrayHasKey('deliveries', $response->data);

        return $response->data;
    }
}
