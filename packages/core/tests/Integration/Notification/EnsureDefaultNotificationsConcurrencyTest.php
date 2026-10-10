<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\EnsureDefaultNotifications;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\EnrolledTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\OrganisationInvitationTrigger;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsListHeaders;
use Shondalai\VidyaLMS\Core\Tests\Support\HidingTemplateIdentityDatabase;
use Shondalai\VidyaLMS\Core\Tests\Support\InjectingRuleWinnerDatabase;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class EnsureDefaultNotificationsConcurrencyTest extends TestCase
{
    public function testMySqlObjectKeyReorderingRepairsProtectedSystemDraftsAndOldSeedSignature(): void
    {
        $env = TestPorts::create();
        $kernel = new CoreKernel($env->ports, [
            'organisation_invitation_secret' => str_repeat('fixture-key-', 4),
            'organisation_invitation_url' => 'https://example.test/membership',
        ]);
        foreach ([false, true] as $proof) {
            $trigger = new OrganisationInvitationTrigger($proof);
            $source = $trigger->notificationTemplateBlueprint()->source;
            $reordered = array_reverse($source, true);
            self::assertNotSame($source, $reordered);
            $kernel->notificationTemplates->saveDraft(
                $trigger->key(),
                'en-GB',
                'email',
                $reordered,
                0,
                null,
                SqlNotificationTemplateRepository::DRAFT_ORIGIN_SYSTEM,
            );
        }
        // Reproduce the cached success from the old algorithm: without a new signature,
        // even a fixed comparison would never revisit these interrupted system drafts.
        $keys = $kernel->registries->notificationTriggers->keys();
        foreach ($kernel->notificationBlueprints->all() as $registration) {
            $keys[] = $registration->trigger->defaultTemplateKey() . ':' . hash('sha256', json_encode([
                $registration->blueprint->version, $registration->blueprint->source,
            ], JSON_THROW_ON_ERROR));
        }
        foreach ($kernel->registries->notificationChannels->all() as $channel) {
            $keys[] = $channel->key() . ':' . ($channel instanceof SupportsListHeaders ? 'list' : 'plain');
        }
        $keys[] = 'studio-layout-1:' . $env->ports->locale->current();
        sort($keys);
        $oldSignature = hash('sha256', implode('|', $keys));
        $kernel->settings->set(EnsureDefaultNotifications::SIGNATURE_SETTING, $oldSignature);
        $kernel->ensureDefaultNotifications->execute();
        self::assertNotSame($oldSignature, $kernel->settings->getString(EnsureDefaultNotifications::SIGNATURE_SETTING));
        foreach ([false, true] as $proof) {
            $row = $kernel->notificationTemplates->find((new OrganisationInvitationTrigger($proof))->key(), 'en-GB', 'email');
            self::assertNotNull($row);
            self::assertSame('published', $row['state']);
            self::assertSame(1, $row['body_version']);
            self::assertNull($row['draft_source']);
            self::assertIsArray($row['published_source']);
            self::assertStringContainsString('<table', (string) $row['body_html']);
        }
    }

    public function testRepairPreservesOperatorDraftsOrderedContentAndPublishedTranslations(): void
    {
        $env = TestPorts::create();
        $kernel = new CoreKernel($env->ports, [
            'organisation_invitation_secret' => str_repeat('fixture-key-', 4),
            'organisation_invitation_url' => 'https://example.test/membership',
        ]);
        $invitation = new OrganisationInvitationTrigger();
        $source = $invitation->notificationTemplateBlueprint()->source;
        // Even a byte-for-byte default-looking operator draft is not permission to publish it.
        $operator = $kernel->notificationTemplates->saveDraft($invitation->key(), 'en-GB', 'email', $source, 0, 91);
        $proof = new OrganisationInvitationTrigger(true);
        $ordered = $proof->notificationTemplateBlueprint()->source;
        self::assertIsArray($ordered['paragraphs']);
        $ordered['paragraphs'] = array_reverse($ordered['paragraphs']);
        $changedList = $kernel->notificationTemplates->saveDraft(
            $proof->key(),
            'en-GB',
            'email',
            $ordered,
            0,
            null,
            SqlNotificationTemplateRepository::DRAFT_ORIGIN_SYSTEM,
        );
        $typed = $kernel->notificationBlueprints->forTemplate(EnrolledTrigger::KEY)->blueprint->source;
        $typed['heading'] = 0;
        $changedType = $kernel->notificationTemplates->saveDraft(
            EnrolledTrigger::KEY,
            'en-GB',
            'email',
            $typed,
            0,
            null,
            SqlNotificationTemplateRepository::DRAFT_ORIGIN_SYSTEM,
        );
        $kernel->notificationTemplates->save($invitation->key(), 'fr', 'email', [
            'subject' => 'Notre invitation', 'body_text' => 'Contenu personnalisé.', 'state' => 'published',
        ]);
        $translation = $kernel->notificationTemplates->find($invitation->key(), 'fr', 'email');
        $kernel->ensureDefaultNotifications->execute();
        self::assertSame($operator, $kernel->notificationTemplates->find($invitation->key(), 'en-GB', 'email'));
        self::assertSame($changedList, $kernel->notificationTemplates->find($proof->key(), 'en-GB', 'email'));
        self::assertSame($changedType, $kernel->notificationTemplates->find(EnrolledTrigger::KEY, 'en-GB', 'email'));
        self::assertSame($translation, $kernel->notificationTemplates->find($invitation->key(), 'fr', 'email'));
    }

    public function testTemplateRaceDoesNotAbortAndASecondPassReconcilesTheSignature(): void
    {
        $env = TestPorts::create();
        $kernel = new CoreKernel($env->ports);
        $winner = $kernel->notificationTemplates->saveDraft(
            EnrolledTrigger::KEY,
            'en-GB',
            'email',
            ['heading' => 'Operator draft'],
            0,
            91,
        );
        $database = new HidingTemplateIdentityDatabase($env->database, EnrolledTrigger::KEY);
        $templates = new SqlNotificationTemplateRepository($database, $env->clock);
        $defaults = new EnsureDefaultNotifications(
            $kernel->registries->notificationTriggers,
            $kernel->notificationRules,
            $templates,
            $env->ports->translator,
            $env->ports->locale,
            $kernel->settings,
            $kernel->notificationBlueprints,
            $kernel->notificationTemplateCompiler,
            $kernel->registries->notificationChannels,
        );

        $env->database->transactional(function () use ($defaults, $kernel): void {
            $kernel->settings->set('test.business_write', true);
            $defaults->execute();
        });

        self::assertTrue($kernel->settings->getBool('test.business_write'));
        self::assertSame('', $kernel->settings->getString(EnsureDefaultNotifications::SIGNATURE_SETTING));
        $afterRace = $kernel->notificationTemplates->find(EnrolledTrigger::KEY, 'en-GB', 'email');
        self::assertNotNull($afterRace);
        self::assertSame($winner['id'], $afterRace['id']);
        self::assertSame(['heading' => 'Operator draft'], $afterRace['draft_source']);
        self::assertSame(SqlNotificationTemplateRepository::DRAFT_ORIGIN_USER, $afterRace['draft_origin']);
        self::assertSame(91, $afterRace['draft_updated_by']);
        self::assertNotNull($kernel->notificationRules->findByKey(EnrolledTrigger::KEY));

        $defaults->execute();

        self::assertNotSame('', $kernel->settings->getString(EnsureDefaultNotifications::SIGNATURE_SETTING));
        $reconciled = $kernel->notificationTemplates->find(EnrolledTrigger::KEY, 'en-GB', 'email');
        self::assertNotNull($reconciled);
        self::assertSame(['heading' => 'Operator draft'], $reconciled['draft_source']);
        self::assertSame(1, $reconciled['draft_revision']);
    }

    public function testRuleRaceKeepsTheOperatorWinnerAndItsChannels(): void
    {
        $env = TestPorts::create();
        $kernel = new CoreKernel($env->ports);
        $baseRules = $kernel->notificationRules;
        $operator = new NotificationRule(
            null,
            EnrolledTrigger::KEY,
            EnrolledTrigger::KEY,
            'site_managers',
            'security',
            'operator.custom',
            ['sms'],
            state: NotificationRule::STATE_DISABLED,
            createdBy: 77,
        );
        $database = new InjectingRuleWinnerDatabase(
            $env->database,
            EnrolledTrigger::KEY,
            static fn (): NotificationRule => $baseRules->save($operator),
        );
        $rules = new SqlNotificationRuleRepository($database, $env->clock);
        $defaults = new EnsureDefaultNotifications(
            $kernel->registries->notificationTriggers,
            $rules,
            $kernel->notificationTemplates,
            $env->ports->translator,
            $env->ports->locale,
            $kernel->settings,
            $kernel->notificationBlueprints,
            $kernel->notificationTemplateCompiler,
            $kernel->registries->notificationChannels,
        );

        $defaults->execute();

        $winner = $rules->findByKey(EnrolledTrigger::KEY);
        self::assertNotNull($winner);
        self::assertSame('site_managers', $winner->audienceKey);
        self::assertSame('security', $winner->categoryKey);
        self::assertSame('operator.custom', $winner->templateKey);
        self::assertSame(['sms'], $winner->channels);
        self::assertSame(77, $winner->createdBy);
        self::assertSame(1, $env->database->count(
            Query::from(SqlNotificationRuleRepository::TABLE)->where('rule_key', '=', EnrolledTrigger::KEY),
        ));
        self::assertNotSame('', $kernel->settings->getString(EnsureDefaultNotifications::SIGNATURE_SETTING));
    }
}
