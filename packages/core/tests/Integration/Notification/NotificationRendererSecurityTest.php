<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationRenderer;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateCompiler;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Token\NotificationTokenType;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\Notification\BlueprintTrigger;
use Shondalai\VidyaLMS\Core\Tests\Support\Notification\CompilerChannel;
use Shondalai\VidyaLMS\Core\Tests\Support\Notification\CompilerTokenResolver;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class NotificationRendererSecurityTest extends TestCase
{
    public function testReportCopyIsPlainTextOnePassAndCannotOverrideOtherTemplates(): void
    {
        $env = TestPorts::create();
        $templates = new SqlNotificationTemplateRepository($env->database, $env->clock);
        $key = \Shondalai\VidyaLMS\Core\Provider\ReportDelivery\NotificationLinkDelivery::TEMPLATE_KEY;
        foreach ([$key, 'other.message'] as $templateKey) {
            $templates->save($templateKey, 'en-GB', 'email', ['subject' => 'Default', 'body_text' => 'Default body',
                'body_html' => '<p>Default body</p>', 'state' => SqlNotificationTemplateRepository::STATE_PUBLISHED]);
        }
        $tokens = new TokenResolverRegistry();
        $tokens->register(new \Shondalai\VidyaLMS\Core\Provider\Token\ReportTokenResolver());
        $renderer = new NotificationRenderer($templates, $tokens);
        $params = ['report.key' => 'Roster{report.row_count}', 'report.row_count' => 2,
            '_report_message_subject' => "Ready {report.key}\r\nBcc: test@example.test",
            '_report_message_body' => '<script>example</script> {report.row_count} rows'];
        $message = $renderer->render(new Notification(42, $key, $params, 'run:1'), 'email', ['en-GB']);
        self::assertNotNull($message);
        self::assertSame('Ready Roster{report.row_count} Bcc: test@example.test', $message->subject);
        self::assertSame('<script>example</script> 2 rows', $message->bodyText);
        self::assertNull($message->bodyHtml);
        $other = $renderer->render(new Notification(42, 'other.message', $params, 'other:1'), 'email', ['en-GB']);
        self::assertNotNull($other);
        self::assertSame('Default', $other->subject);
        self::assertSame('<p>Default body</p>', $other->bodyHtml);
    }

    public function testLegacyRenderingEscapesHtmlValuesSanitisesSubjectAndExpandsOnlyOnce(): void
    {
        $env = TestPorts::create();
        $templates = new SqlNotificationTemplateRepository($env->database, $env->clock);
        $templates->save('legacy.security', 'en-GB', 'email', [
            'subject' => 'Hello {display_name}',
            'body_text' => 'Text {user.name} / {display_name}',
            'body_html' => '<p>{user.name} / {display_name}</p>',
            'body_short' => 'Short {display_name}',
            'state' => SqlNotificationTemplateRepository::STATE_PUBLISHED,
        ]);

        $tokens = new TokenResolverRegistry();
        $tokens->register(new CompilerTokenResolver('user', ['user.name' => NotificationTokenType::Text]));
        $renderer = new NotificationRenderer($templates, $tokens);
        $rendered = $renderer->render(
            new Notification(
                42,
                'legacy.security',
                [
                    'user.name' => '<img src=x onerror=alert(1)>{display_name}',
                    'display_name' => "<b>Ada</b>\r\nBcc: attacker@example.test",
                ],
                'legacy-security:1',
            ),
            'email',
            ['en-GB'],
        );

        self::assertNotNull($rendered);
        self::assertStringNotContainsString("\r", $rendered->subject);
        self::assertStringNotContainsString("\n", $rendered->subject);
        self::assertStringContainsString('<b>Ada</b>', $rendered->subject);

        self::assertSame(
            'Text <img src=x onerror=alert(1)>{display_name} / <b>Ada</b>' . "\r\n"
                . 'Bcc: attacker@example.test',
            $rendered->bodyText,
        );
        self::assertNotNull($rendered->bodyHtml);
        self::assertStringContainsString(
            '&lt;img src=x onerror=alert(1)&gt;{display_name} / &lt;b&gt;Ada&lt;/b&gt;',
            $rendered->bodyHtml,
        );
        self::assertStringNotContainsString('<img', $rendered->bodyHtml);
        self::assertStringNotContainsString('<b>Ada</b>', $rendered->bodyHtml);
        self::assertStringContainsString('{display_name}', $rendered->bodyHtml);

        self::assertSame(
            ['user.name', 'display_name'],
            $renderer->placeholdersIn('{user.name} {display_name} {user.name}'),
        );
    }

    public function testStructuredPublishedSourceIsRenderedAtDeliveryAndRequiredMissesFailClosed(): void
    {
        $env = TestPorts::create();
        $source = [
            'subject' => 'Hello {user.name}',
            'preheader' => 'Your update is ready.',
            'heading' => 'Hello {user.name}',
            'paragraphs' => ['Continue at {site.name}.'],
            'action_label' => 'Open course',
            'action_url' => '{course.url}',
            'footer' => 'Sent by {site.name}.',
            'logo_url' => null,
            'logo_alt' => null,
            'accent_color' => '#3155A6',
            'short_body' => 'Hello {user.name}',
        ];
        $samples = [
            'user.name' => 'Avery Learner',
            'site.name' => 'VidyaLMS Academy',
            'course.url' => 'https://example.test/courses/one',
        ];
        $triggers = new NotificationTriggerRegistry();
        $triggers->register(new BlueprintTrigger(
            NotificationClass::Transactional,
            new NotificationTemplateBlueprint(1, $source, $samples, ['user.name', 'site.name', 'course.url']),
        ));
        $tokens = new TokenResolverRegistry();
        $tokens->register(new CompilerTokenResolver('user', ['user.name' => NotificationTokenType::Text]));
        $tokens->register(new CompilerTokenResolver('site', ['site.name' => NotificationTokenType::Text]));
        $tokens->register(new CompilerTokenResolver('course', ['course.url' => NotificationTokenType::Url]));
        $channels = new NotificationChannelRegistry();
        $channels->register(new CompilerChannel());
        $compiler = new NotificationTemplateCompiler(
            new NotificationTemplateBlueprintCatalogue($triggers),
            $tokens,
            $channels,
        );
        $templates = new SqlNotificationTemplateRepository($env->database, $env->clock);
        $publication = $compiler->compileForPublication(
            BlueprintTrigger::TEMPLATE_KEY,
            $source,
            'email',
        );
        $draft = $templates->saveDraft(
            BlueprintTrigger::TEMPLATE_KEY,
            'fr',
            'email',
            $source,
            0,
            null,
            SqlNotificationTemplateRepository::DRAFT_ORIGIN_SYSTEM,
        );
        $templates->publishDraft(
            BlueprintTrigger::TEMPLATE_KEY,
            'fr',
            'email',
            (int) $draft['draft_revision'],
            $publication->parts(),
            null,
        );
        self::assertIsArray($templates->find(BlueprintTrigger::TEMPLATE_KEY, 'fr', 'email')['published_source'] ?? null);
        $renderer = new NotificationRenderer($templates, $tokens, $compiler);
        $rendered = $renderer->render(new Notification(
            42,
            BlueprintTrigger::TEMPLATE_KEY,
            [
                'user.name' => '<b>Ada</b>{site.name}',
                'site.name' => 'Northlight Academy',
                'course.url' => 'https://example.test/courses/one?from=mail&learner=42',
            ],
            'structured-security:1',
        ), 'email', ['fr']);

        self::assertNotNull($rendered);
        self::assertSame('fr', $rendered->locale);
        self::assertStringContainsString('<b>Ada</b>{site.name}', $rendered->subject);
        self::assertStringContainsString('&lt;b&gt;Ada&lt;/b&gt;{site.name}', (string) $rendered->bodyHtml);
        self::assertStringNotContainsString('Northlight Academy</h1>', (string) $rendered->bodyHtml);
        self::assertStringContainsString('lang="fr"', (string) $rendered->bodyHtml);
        self::assertStringContainsString('learner=42', $rendered->bodyText);
        self::assertStringContainsString('learner=42', (string) $rendered->bodyHtml);
        self::assertStringNotContainsString('Avery', json_encode($rendered, \JSON_THROW_ON_ERROR));

        $this->expectException(ValidationException::class);
        $renderer->render(new Notification(
            42,
            BlueprintTrigger::TEMPLATE_KEY,
            ['site.name' => 'Northlight Academy', 'course.url' => 'https://example.test/courses/one'],
            'structured-security:missing',
        ), 'email', ['fr']);
    }
}
