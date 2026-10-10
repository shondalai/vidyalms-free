<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateCompiler;
use Shondalai\VidyaLMS\Core\Application\Notification\TemplateRenderContext;
use Shondalai\VidyaLMS\Core\Application\Notification\TemplateSource;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/** Every installed Core trigger owns a complete, renderable English blueprint. */
final class NotificationTemplateBlueprintsTest extends TestCase
{
    public function testEveryInstalledCoreTriggerPreviewsAndPublishesWithoutDemoValues(): void
    {
        $fixture = ManagementFixture::create();
        $registries = $fixture->kernel->registries;
        $catalogue = new NotificationTemplateBlueprintCatalogue($registries->notificationTriggers);
        $compiler = new NotificationTemplateCompiler(
            $catalogue,
            $registries->tokenResolvers,
            $registries->notificationChannels,
        );
        $seen = [];

        foreach ($catalogue->all() as $registration) {
            $key = $registration->trigger->defaultTemplateKey();
            $source = TemplateSource::fromArray($registration->blueprint->source);
            $preview = $compiler->compile(
                $key,
                $source,
                'email',
                TemplateRenderContext::synthetic($registration->blueprint->syntheticValues),
            );
            $published = $compiler->compileForPublication($key, $source, 'email');

            self::assertNotSame('', $preview->subject, $key);
            self::assertNotSame('', $preview->bodyText, $key);
            self::assertNotNull($preview->bodyHtml, $key);
            self::assertStringContainsString('role="presentation"', (string) $preview->bodyHtml, $key);
            self::assertSame([], $preview->unresolvedTokens, $key);
            self::assertSame('', $registration->blueprint->syntheticValues['site.logo_url'], $key);
            self::assertSame('{site.logo_url}', $registration->blueprint->source['logo_url'], $key);
            self::assertStringNotContainsString('<img', (string) $preview->bodyHtml, $key);
            self::assertStringContainsString('src="{site.logo_url}"', (string) $published->bodyHtml, $key);

            $brandedPreview = $compiler->compile(
                $key,
                $source,
                'email',
                TemplateRenderContext::synthetic(array_replace(
                    $registration->blueprint->syntheticValues,
                    ['site.logo_url' => 'https://learning.example.test/brand/logo.png'],
                )),
            );
            self::assertStringContainsString(
                'src="https://learning.example.test/brand/logo.png"',
                (string) $brandedPreview->bodyHtml,
                $key,
            );
            self::assertSame([], $brandedPreview->unresolvedTokens, $key);
            self::assertStringNotContainsString('Avery', json_encode($published->parts(), \JSON_THROW_ON_ERROR), $key);
            self::assertStringContainsString('{', json_encode($published->parts(), \JSON_THROW_ON_ERROR), $key);
            $seen[] = $key;
        }

        sort($seen);
        self::assertSame([
            'vidyalms.availability.unlocked',
            'vidyalms.certificate.reissued',
            'vidyalms.mail.attempt_graded',
            'vidyalms.mail.certificate_issued',
            'vidyalms.mail.course_completed',
            'vidyalms.mail.enrolled',
            // The three seat threshold alerts. They are the first free
            // identities addressed to an administrator rather than a learner,
            // which is why they carry none of the learner placeholders and
            // why their samples name a pool instead of a person.
            'vidyalms.mail.seats_exhausted',
            'vidyalms.mail.seats_limited',
            'vidyalms.mail.seats_summary',
            'vidyalms.mail.submission_received',
            'vidyalms.sweep.entitlement_expiring',
        ], $seen);
    }
}
