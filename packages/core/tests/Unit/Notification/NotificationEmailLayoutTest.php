<?php
declare(strict_types=1);
namespace Shondalai\VidyaLMS\Core\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationEmailLayout;
use Shondalai\VidyaLMS\Core\Application\Notification\TemplateSource;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

final class NotificationEmailLayoutTest extends TestCase
{
    public function testComplianceCopyUsesTheHostTranslatorAndEscapesItsHtml(): void
    {
        $translator = new class implements TranslatorPort {
            public function translate(string $key, array $parameters = []): string
            {
                return str_ends_with($key, '.reason') ? 'Votre notification <test>' : 'Gérer les notifications';
            }
        };
        $layout = new NotificationEmailLayout($translator);
        $source = TemplateSource::fromArray([
            'subject' => 'Cours', 'preheader' => 'Cours', 'heading' => 'Cours',
            'paragraphs' => ['Un nouveau cours.'], 'footer' => 'École', 'accent_color' => '#3155A6',
        ]);
        $rendered = $layout->render($source, true, true, 'https://example.test/unsubscribe', 'fr-FR');
        self::assertStringContainsString('Votre notification <test>', $rendered['body_text']);
        self::assertStringContainsString('Gérer les notifications', $rendered['body_text']);
        self::assertStringContainsString('Votre notification &lt;test&gt;', (string) $rendered['body_html']);
        self::assertStringNotContainsString('You are receiving', (string) $rendered['body_html']);
    }
}
