<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Provider\ReportDelivery\NotificationLinkDelivery;
use Shondalai\VidyaLMS\Core\Provider\Token\ReportTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The regression the integration wave found on a live site.
 *
 * A scheduled report was delivered to a real mailbox with the subject
 * "Your {report.key} report is ready" and a body offering "{report.url}".
 * Nothing was wrong with the template or with the delivery: the delivery put
 * the six names in the queued parameter bag and the renderer resolves a dotted
 * placeholder through the token registry only, where no provider owned the
 * prefix.
 */
#[CoversClass(ReportTokenResolver::class)]
final class ReportTokenResolverTest extends TestCase
{
    public function testEveryNameTheDeliveryQueuesIsAnswered(): void
    {
        $resolver = new ReportTokenResolver();
        $context = TokenContext::create([
            'report.key' => 'org-roster',
            'report.format' => 'csv',
            'report.row_count' => 12,
            'report.url' => 'https://example.test/reports/9',
            'report.url_expires_at' => '2026-09-11T00:00:00+00:00',
            'report.expires_at' => '2026-09-17T00:00:00+00:00',
        ], 'en-GB', 7, null, 'UTC');

        self::assertSame('org-roster', $resolver->resolve('report.key', $context));
        self::assertSame('csv', $resolver->resolve('report.format', $context));
        self::assertSame('12', $resolver->resolve('report.row_count', $context));
        self::assertSame('https://example.test/reports/9', $resolver->resolve('report.url', $context));
        self::assertSame('2026-09-11T00:00:00+00:00', $resolver->resolve('report.url_expires_at', $context));
        self::assertSame('2026-09-17T00:00:00+00:00', $resolver->resolve('report.expires_at', $context));
    }

    /**
     * A name outside the published set answers null rather than an empty
     * string, which is what leaves the placeholder visible instead of shipping
     * a message with a hole where a link belongs.
     */
    public function testAnUnpublishedNameAnswersNullRatherThanEmpty(): void
    {
        $resolver = new ReportTokenResolver();
        $context = TokenContext::create(['report.secret' => 'no'], 'en-GB', 7, null, 'UTC');

        self::assertNull($resolver->resolve('report.secret', $context));
        self::assertNull($resolver->resolve('report.key', $context));
    }

    /**
     * The registration, not the class. The defect was a provider that existed
     * nowhere, so a test of the class alone would have passed against the
     * broken build too.
     */
    public function testTheFreeKernelOwnsTheReportPrefix(): void
    {
        $env = TestPorts::create();
        $kernel = new CoreKernel($env->ports, []);

        $resolver = $kernel->registries->tokenResolvers->resolverFor('report.url');

        self::assertNotNull($resolver, 'No provider owns the report prefix, so every report email ships its placeholders.');
        self::assertSame(ReportTokenResolver::PREFIX, $resolver->prefix());
    }

    /**
     * The two ends agree. The delivery is the only writer of this bag, so a
     * name it queues and this provider does not publish is a placeholder that
     * survives into somebody's inbox.
     */
    public function testTheProviderPublishesEveryNameTheFreeDeliveryWrites(): void
    {
        $file = (new \ReflectionClass(NotificationLinkDelivery::class))->getFileName();

        self::assertIsString($file);

        $source = (string) file_get_contents($file);

        preg_match_all("/'(report\\.[a-z_]+)'\\s*=>/", $source, $matches);

        $queued = array_values(array_unique($matches[1]));

        self::assertNotEmpty($queued);

        foreach ($queued as $name) {
            self::assertContains(
                $name,
                (new ReportTokenResolver())->tokens(),
                $name . ' is queued by the delivery and published by no resolver.',
            );
        }
    }
}
