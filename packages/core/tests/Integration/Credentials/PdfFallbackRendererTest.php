<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Pdf\PdfDocument;
use Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\RenderCertificateTask;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * A design published under 1.0.0 as "pdf" keeps issuing once the PDF port is
 * gone.
 *
 * 1.0.0 carried the PDF port in the free build and defaulted new and cloned
 * designs to it; 1.0.1 moved the port to Pro. The same database is driven
 * first by a kernel holding a PDF port (the 1.0.0 site) and then by the
 * fixture's own kernel, which holds none (free 1.0.1 without Pro). Before the
 * fallback, issuance threw, reissue answered 422 and the completion listener's
 * failure was swallowed, so learners silently received nothing.
 */
final class PdfFallbackRendererTest extends TestCase
{
    public function testAPdfDesignFromOneZeroZeroIssuesHtmlWithoutAPdfPort(): void
    {
        $fixture = ManagementFixture::create();
        $withPdf = self::kernelWithPdf($fixture);
        $manager = $fixture->manager();
        $id = $this->publishPdfDefault($withPdf, $manager);

        $enrolment = $withPdf->enrolLearner->execute(
            ManagementFixture::LEARNER_ID,
            $fixture->courseId,
            $fixture->entitlementId,
        );
        $old = $withPdf->issueCertificate->execute($enrolment->id(), ManagementFixture::LEARNER_ID, $fixture->courseId);
        self::assertSame(['application/pdf'], $this->renders($fixture, $withPdf));

        // The free kernel from here on. The file 1.0.0 stored is still served.
        $download = $fixture->call('GET', '/me/certificates/' . $old->id . '/download', $fixture->learner());
        self::assertSame(200, $download->status);
        self::assertSame('application/pdf', $download->data['media_type'] ?? null);

        // Drawing it again gives the designed HTML, not the missing-renderer
        // placeholder.
        $html = $fixture->kernel->renderCertificate->execute($manager, $old->id, 'https://example.test/verify');
        self::assertStringContainsString('vy-certificate-page', $html);
        self::assertStringNotContainsString('vy-certificate-missing-renderer', $html);

        // Reissue and the render it queues both succeed, as HTML.
        $reissue = $fixture->call('POST', '/admin/certificates/' . $old->id . '/reissue', $manager, ['reason' => 'Name change'], [], 'r1');
        self::assertSame(201, $reissue->status, (string) json_encode($reissue->data));
        self::assertSame(['text/html'], $this->renders($fixture, $fixture->kernel));

        // The course policy still reports the design as usable.
        $templates = $fixture->call('GET', '/manage/certificate-templates', $manager);
        self::assertSame(200, $templates->status);
        self::assertSame('pdf', $templates->data['items'][0]['renderer'] ?? null);
        self::assertSame($id, (int) ($templates->data['items'][0]['id'] ?? 0));
    }

    public function testTheFallbackIsNotOfferedAndCannotBePublished(): void
    {
        $fixture = ManagementFixture::create();
        $manager = $fixture->manager();
        $fixture->env->translator->define('vidyalms.certificate_pdf.unsupported', 'PDF compatibility: {reason}');
        $fixture->env->translator->define('vidyalms.certificate_pdf.reason.unavailable', 'no PDF renderer');

        $catalogue = $fixture->call('GET', '/manage/certificate-blueprints', $manager);
        self::assertSame(['html', 'elements'], array_column(self::data($catalogue)['renderer_options'], 'key'));

        $withPdf = self::call(self::kernelWithPdf($fixture), 'GET', '/manage/certificate-blueprints', $manager);
        self::assertContains('pdf', array_column(self::data($withPdf)['renderer_options'], 'key'));

        // A draft that names "pdf" here would freeze a snapshot with no PDF
        // provenance, which a real PDF renderer could not draw later.
        $key = (string) self::data($catalogue)['items'][0]['key'];
        $clone = $fixture->call('POST', '/manage/certificate-blueprints/' . $key . '/clone', $manager, [
            'name' => 'Draft', 'version' => 1, 'renderer' => 'pdf',
        ], [], 'c1');
        self::assertSame(201, $clone->status, (string) json_encode($clone->data));
        $id = (int) self::data($clone)['id'];

        $refused = $fixture->call('POST', '/manage/certificate-templates/' . $id . '/publish', $manager, [
            'expected_revision' => (int) self::data($clone)['revision'],
        ], [], 'p1');
        self::assertSame(422, $refused->status);
        self::assertStringContainsString('no PDF renderer', (string) json_encode($refused->data));

        // Switching to the designed renderer is the way out, and it publishes.
        $patch = $fixture->call('PATCH', '/manage/certificate-templates/' . $id, $manager, [
            'expected_revision' => (int) self::data($clone)['revision'], 'renderer' => 'elements',
        ], [], 'u1');
        self::assertSame(200, $patch->status, (string) json_encode($patch->data));
        $published = $fixture->call('POST', '/manage/certificate-templates/' . $id . '/publish', $manager, [
            'expected_revision' => (int) self::data($patch)['revision'],
        ], [], 'p2');
        self::assertSame(200, $published->status, (string) json_encode($published->data));
    }

    private function publishPdfDefault(CoreKernel $kernel, Principal $manager): int
    {
        $catalogue = self::call($kernel, 'GET', '/manage/certificate-blueprints', $manager);
        $key = (string) self::data($catalogue)['items'][0]['key'];
        $clone = self::call($kernel, 'POST', '/manage/certificate-blueprints/' . $key . '/clone', $manager, [
            'name' => 'Legacy', 'version' => 1, 'renderer' => 'pdf',
        ], 'c1');
        self::assertSame(201, $clone->status, (string) json_encode($clone->data));
        $id = (int) self::data($clone)['id'];
        $published = self::call($kernel, 'POST', '/manage/certificate-templates/' . $id . '/publish', $manager, [
            'expected_revision' => (int) self::data($clone)['revision'],
        ], 'p1');
        self::assertSame(200, $published->status, (string) json_encode($published->data));
        $default = self::call($kernel, 'POST', '/manage/certificate-templates/' . $id . '/default', $manager, [
            'expected_revision' => (int) self::data($published)['revision'],
            'expected_default_id' => null,
            'expected_default_revision' => null,
        ], 'd1');
        self::assertSame(200, $default->status, (string) json_encode($default->data));

        return $id;
    }

    /**
     * Runs the queued render tasks and answers the media type each stored.
     *
     * @return list<string>
     */
    private function renders(ManagementFixture $fixture, CoreKernel $kernel): array
    {
        $types = [];
        $handler = $kernel->registries->scheduledTasks->get(RenderCertificateTask::KEY);

        foreach ($fixture->env->scheduler->tasks as $task) {
            if ($task->type === RenderCertificateTask::KEY) {
                $result = $handler->run(new ScheduledTaskRun(1, $task->payload, Principal::guest()));
                $matched = preg_match('/type=(\S+)/', (string) $result, $match);
                self::assertSame(1, $matched, (string) $result);
                $types[] = $match[1] ?? '';
            }
        }
        $fixture->env->scheduler->tasks = [];

        return $types;
    }

    /** The 1.0.0 site: the same ports, plus a PDF port. */
    private static function kernelWithPdf(ManagementFixture $fixture): CoreKernel
    {
        $p = $fixture->env->ports;
        $pdf = new class implements PdfPort {
            public function provenance(): array
            {
                return ['engine' => 'dompdf', 'engine_version' => 'stub', 'worker_grammar' => 2];
            }

            public function render(PdfDocument $document): string
            {
                return "%PDF-1.4\n% stub\n";
            }
        };
        $ports = new Ports(
            $p->database,
            $p->users,
            $p->authorization,
            $p->storage,
            $p->mailer,
            $p->scheduler,
            $p->eventBridge,
            $p->commerce,
            $p->translator,
            $p->cache,
            $p->clock,
            $p->random,
            new NullLogger(),
            $p->locale,
            $p->recipientProfile,
            $p->courseCategories,
            $p->courseLinks,
            $pdf,
            secrets: $p->secrets,
        );

        return new CoreKernel($ports, [
            'verification_base_url' => 'https://example.test/verify',
            'expiry_sweep_schedule' => 'hourly',
            'host_settings_url' => 'https://example.test/administrator',
            'self_enrolment' => true,
            'envelope_domain' => 'example.test',
        ]);
    }

    /** @return array<string, mixed> */
    private static function data(ApiResponse $response): array
    {
        self::assertNotNull($response->data);

        return $response->data;
    }

    /** @param array<string, mixed> $body */
    private static function call(
        CoreKernel $kernel,
        string $method,
        string $path,
        Principal $principal,
        array $body = [],
        ?string $idempotencyKey = null,
    ): ApiResponse {
        return $kernel->apiKernel()->handle(new ApiRequest($method, $path, $principal, [], $body, $idempotencyKey));
    }
}
