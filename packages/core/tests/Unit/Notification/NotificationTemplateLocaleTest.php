<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateLocale;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class NotificationTemplateLocaleTest extends TestCase
{
    public function testCanonicalizesLanguageScriptRegionAndExtensions(): void
    {
        self::assertSame('fr-CA', NotificationTemplateLocale::canonicalize(' FR-ca '));
        self::assertSame('zh-Hant-TW', NotificationTemplateLocale::canonicalize('ZH-hANT-tw'));
        self::assertSame('en-US-u-ca-gregory', NotificationTemplateLocale::canonicalize('EN-us-U-CA-GREGORY'));
        self::assertSame('de-DE-x-private', NotificationTemplateLocale::canonicalize('DE-de-X-PRIVATE'));
    }

    public function testBuildsExactLanguageAndCanonicalDefaultChainWithoutDuplicates(): void
    {
        self::assertSame(
            ['zh-Hant-TW', 'zh', 'fr-CA', 'fr', 'en-GB'],
            NotificationTemplateLocale::chain(['ZH-hant-tw', 'FR-ca']),
        );
        self::assertSame(['en-GB'], NotificationTemplateLocale::chain([]));
        self::assertSame(['en-GB', 'en'], NotificationTemplateLocale::chain(['EN-gb']));
    }

    public function testRejectsMalformedOrOversizedIdentities(): void
    {
        foreach (['', 'en_US', 'e', 'en--GB', 'en-GB-this-is-too-long'] as $locale) {
            try {
                NotificationTemplateLocale::canonicalize($locale);
                self::fail('Accepted malformed locale ' . $locale);
            } catch (ValidationException $failure) {
                self::assertArrayHasKey('locale', $failure->errors);
            }
        }
    }
}
