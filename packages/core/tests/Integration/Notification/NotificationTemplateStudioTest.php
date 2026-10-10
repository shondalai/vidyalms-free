<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\EnsureDefaultNotifications;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateCompiler;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateStudio;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelInterface;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\EntitlementExpiringSweep;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

final class NotificationTemplateStudioTest extends TestCase
{
    private const KEY = 'vidyalms.mail.enrolled';
    private ManagementFixture $fixture;
    private NotificationTemplateStudio $studio;
    private EnsureDefaultNotifications $defaults;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $kernel = $this->fixture->kernel;
        $ports = $this->fixture->env->ports;
        $blueprints = new NotificationTemplateBlueprintCatalogue($kernel->registries->notificationTriggers);
        $compiler = new NotificationTemplateCompiler(
            $blueprints,
            $kernel->registries->tokenResolvers,
            $kernel->registries->notificationChannels,
        );
        $this->defaults = new EnsureDefaultNotifications(
            $kernel->registries->notificationTriggers,
            $kernel->notificationRules,
            $kernel->notificationTemplates,
            $ports->translator,
            $ports->locale,
            $kernel->settings,
            $blueprints,
            $compiler,
            $kernel->registries->notificationChannels,
        );
        $this->studio = new NotificationTemplateStudio(
            $kernel->notificationTemplates,
            $blueprints,
            $compiler,
            $kernel->registries->notificationChannels,
            new AccessGuard($ports->authorization),
            $ports->locale,
            $ports->translator,
            $this->defaults,
            'http://localhost/vidyalms-wp',
        );
    }

    public function testPreviewUsesOnlySamplesAndDoesNotPersistAnything(): void
    {
        $detail = $this->detail('fr-CA');
        $before = $this->fixture->env->database->selectAll(Query::from('notification_templates'));
        $preview = $this->studio->preview(
            $this->fixture->manager(),
            self::KEY,
            'fr-CA',
            'email',
            $detail['default_source'],
        );
        self::assertTrue($preview['sample_data']);
        self::assertSame('fr-CA', $preview['locale']);
        self::assertSame([], $preview['unresolved_tokens']);
        self::assertStringContainsString('<table', $preview['body_html']);
        self::assertSame($before, $this->fixture->env->database->selectAll(Query::from('notification_templates')));
        self::assertStringNotContainsString('lena@example.test', json_encode($preview, JSON_THROW_ON_ERROR));
    }

    public function testDraftLifecycleDoesNotReplaceLiveUntilPublishAndRejectsStaleRevision(): void
    {
        $detail = $this->detail('fr');
        $source = $detail['default_source'];
        $source['heading'] = 'First version, {user.first_name}';
        $saved = $this->studio->saveDraft($this->fixture->manager(), self::KEY, 'fr', 'email', $source, 0);
        self::assertSame('draft_only', $saved['status']);
        self::assertSame(1, $saved['draft_revision']);
        $published = $this->studio->publish($this->fixture->manager(), self::KEY, 'fr', 'email', 1);
        self::assertSame(1, $published['body_version']);
        self::assertSame('published', $published['status']);
        self::assertNull($published['draft_source']);
        $liveBefore = $this->fixture->kernel->notificationTemplates->resolve(self::KEY, 'email', ['fr']);
        self::assertNotNull($liveBefore);
        $source['heading'] = 'Second version, {user.first_name}';
        $draft = $this->studio->saveDraft(
            $this->fixture->manager(),
            self::KEY,
            'fr',
            'email',
            $source,
            $published['draft_revision'],
        );
        self::assertSame('draft_changes', $draft['status']);
        $liveAfter = $this->fixture->kernel->notificationTemplates->resolve(self::KEY, 'email', ['fr']);
        self::assertNotNull($liveAfter);
        self::assertSame($liveBefore['body_html'], $liveAfter['body_html']);
        self::assertSame($liveBefore['published_source'], $liveAfter['published_source']);
        self::assertSame($liveBefore['body_version'], $liveAfter['body_version']);
        try {
            $this->studio->publish($this->fixture->manager(), self::KEY, 'fr', 'email', $published['draft_revision']);
            self::fail('A stale draft must not publish.');
        } catch (ConflictException) {
            self::assertSame(1, $this->detail('fr')['body_version']);
        }
        $discarded = $this->studio->discardDraft(
            $this->fixture->manager(),
            self::KEY,
            'fr',
            'email',
            $draft['draft_revision'],
        );
        self::assertSame('published', $discarded['status']);
        self::assertSame($liveBefore['published_source'], $discarded['published_source']);
        self::assertGreaterThan($draft['draft_revision'], $discarded['draft_revision']);
    }

    public function testLocaleSearchAndDraftStatusAreEvaluatedBeforePaging(): void
    {
        $source = $this->detail()['default_source'];
        foreach (['fr', 'de', 'es'] as $locale) {
            $this->studio->saveDraft($this->fixture->manager(), self::KEY, $locale, 'email', $source, 0);
        }
        $page = $this->studio->list($this->fixture->manager(), [
            'locale' => 'fr', 'template_key' => self::KEY, 'status' => 'draft_only',
        ], 1, 1);
        self::assertSame(1, $page['total']);
        self::assertCount(1, $page['items']);
        self::assertSame('fr', $page['items'][0]['locale']);
        $beyond = $this->studio->list($this->fixture->manager(), ['locale' => 'fr'], 2, 1);
        self::assertSame(1, $beyond['total']);
        self::assertSame([], $beyond['items']);
        $mismatch = $this->studio->list($this->fixture->manager(), ['trigger_key' => 'not.registered'], 1, 25);
        self::assertSame([], $mismatch['items']);
    }

    public function testDetailNamesFallbackWithoutPretendingItIsAnExactTranslation(): void
    {
        $this->defaults->execute();
        $detail = $this->detail('fr-CA');
        self::assertNull($detail['id']);
        self::assertSame('en-GB', $detail['resolved_locale']);
        self::assertTrue($detail['using_fallback']);
        self::assertNull($detail['published_source']);
        self::assertSame(0, $detail['draft_revision']);
    }

    public function testInvalidDraftIsRejectedBeforeAnyWrite(): void
    {
        $detail = $this->detail('fr');
        $source = $detail['default_source'];
        $source['heading'] = '{{not_declared}}';
        try {
            $this->studio->saveDraft($this->fixture->manager(), self::KEY, 'fr', 'email', $source, 0);
            self::fail('Unknown tokens must fail.');
        } catch (ValidationException $error) {
            self::assertNotEmpty($error->errors);
            self::assertNull($this->fixture->kernel->notificationTemplates->find(self::KEY, 'fr', 'email'));
        }
    }

    public function testTeacherCannotOpenStudioOrTriggerDefaultSeeding(): void
    {
        $before = $this->fixture->env->database->selectAll(Query::from('notification_templates'));
        try {
            $this->studio->list($this->fixture->teacher(), [], 1, 25);
            self::fail('Only managers or administrators can use the studio.');
        } catch (NotAuthorizedException) {
            self::assertSame($before, $this->fixture->env->database->selectAll(Query::from('notification_templates')));
        }
    }

    public function testUpgradeKeepsCustomizedLiveMessageAndDiscardedSystemDraftStaysDiscarded(): void
    {
        $templates = $this->fixture->kernel->notificationTemplates;
        $templates->save(self::KEY, 'en-GB', 'email', [
            'subject' => 'Our custom subject', 'body_text' => 'Our live body', 'state' => 'published',
        ]);
        // Reproduce an actual pre-studio row, not a structured row the fixture's
        // modern kernel already seeded before this migration scenario starts.
        $this->fixture->env->database->update('notification_templates', [
            'published_source' => null, 'draft_source' => null, 'draft_revision' => 0, 'draft_origin' => null,
        ], ['template_key' => self::KEY, 'locale' => 'en-GB', 'channel_key' => 'email']);
        $this->fixture->kernel->settings->set(EnsureDefaultNotifications::SIGNATURE_SETTING, '');
        $this->defaults->execute();
        $detail = $this->detail();
        self::assertSame('Our custom subject', $detail['subject']);
        self::assertSame('Our live body', $detail['live_parts']['body_text']);
        self::assertSame('system', $detail['draft_origin']);
        $this->studio->discardDraft($this->fixture->manager(), self::KEY, 'en-GB', 'email', $detail['draft_revision']);
        $this->fixture->kernel->settings->set(EnsureDefaultNotifications::SIGNATURE_SETTING, '');
        $this->defaults->execute();
        self::assertNull($this->detail()['draft_source']);
        self::assertSame('Our custom subject', $this->detail()['subject']);
    }

    /** @return array<string, mixed> */
    private function detail(string $locale = 'en-GB'): array
    {
        return $this->studio->detail($this->fixture->manager(), self::KEY, $locale, 'email');
    }

    public function testHttpSiteWarnsEvenWhenSyntheticPreviewSucceeds(): void
    {
        $detail = $this->detail();
        self::assertFalse($detail['delivery_ready']);
        self::assertSame('https_required_for_runtime_urls', $detail['delivery_warnings'][0]['code']);
        self::assertTrue($detail['delivery_warnings'][0]['blocking']);
        $preview = $this->studio->preview(
            $this->fixture->manager(),
            self::KEY,
            'en-GB',
            'email',
            $detail['default_source'],
        );
        self::assertFalse($preview['delivery_ready']);
        self::assertSame($detail['delivery_warnings'], $preview['delivery_warnings']);
        $page = $this->studio->list($this->fixture->manager(), ['template_key' => self::KEY], 1, 1);
        self::assertFalse($page['items'][0]['delivery_ready']);
    }

    public function testBlueprintProvenanceDoesNotPretendLegacyWasPublishedFromCurrentSource(): void
    {
        $this->defaults->execute();
        $detail = $this->detail();
        self::assertSame($detail['blueprint_version'], $detail['current_blueprint_version']);
        self::assertSame($detail['blueprint_version'], $detail['published_blueprint_version']);
        self::assertFalse($detail['update_available']);
        $this->fixture->kernel->notificationTemplates->save(self::KEY, 'en-GB', 'email', [
            'subject' => 'Legacy custom subject', 'body_text' => 'Custom', 'state' => 'published',
        ]);
        $legacy = $this->detail();
        self::assertNull($legacy['published_blueprint_version']);
        self::assertTrue($legacy['legacy_published']);
        self::assertFalse($legacy['update_available']);
    }

    public function testChannelAvailabilityUsesMessageComplianceCapabilities(): void
    {
        $this->defaults->execute();
        $channel = $this->createStub(NotificationChannelInterface::class);
        $channel->method('key')->willReturn('email');
        $channel->method('label')->willReturn('Email without list headers');
        $this->fixture->kernel->registries->notificationChannels->replace($channel);
        $detail = $this->studio->detail(
            $this->fixture->manager(),
            EntitlementExpiringSweep::KEY,
            'en-GB',
            'email',
        );
        self::assertFalse($detail['available']);
        self::assertStringContainsString('unsubscribe headers', $detail['unavailable_reason']);
        $newTranslation = $this->studio->detail(
            $this->fixture->manager(),
            EntitlementExpiringSweep::KEY,
            'fr',
            'email',
        );
        self::assertFalse($newTranslation['available']);
        self::assertTrue($this->detail()['available']);
    }

    public function testHttpsReadinessDoesNotAddSampleWarnings(): void
    {
        $kernel = $this->fixture->kernel;
        $ports = $this->fixture->env->ports;
        $studio = new NotificationTemplateStudio(
            $kernel->notificationTemplates,
            $kernel->notificationBlueprints,
            $kernel->notificationTemplateCompiler,
            $kernel->registries->notificationChannels,
            new AccessGuard($ports->authorization),
            $ports->locale,
            $ports->translator,
            $this->defaults,
            'https://learning.example.test',
        );
        self::assertSame(['delivery_ready' => true, 'delivery_warnings' => []], $studio->deliveryReadiness());
    }

    public function testOverlongSearchIsRejectedBeforeDefaultSeeding(): void
    {
        $before = $this->fixture->env->database->selectAll(Query::from('notification_templates'));
        try {
            $this->studio->list($this->fixture->manager(), ['search' => str_repeat('a', 201)], 1, 25);
            self::fail('Search must be bounded before accessing the database.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('search', $error->errors);
            self::assertSame($before, $this->fixture->env->database->selectAll(Query::from('notification_templates')));
        }
    }
}
