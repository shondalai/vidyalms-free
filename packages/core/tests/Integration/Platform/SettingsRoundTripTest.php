<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class SettingsRoundTripTest extends TestCase
{
    public function testScalarsRoundTrip(): void
    {
        $env = TestPorts::create();
        $settings = $this->settings($env);

        $settings->set('site.name', 'Academy');
        $settings->set('site.page_size', 25);
        $settings->set('site.open', true);
        $settings->set('site.ratio', 0.5);

        self::assertSame('Academy', $settings->get('site.name'));
        self::assertSame(25, $settings->get('site.page_size'));
        self::assertTrue($settings->getBool('site.open'));
        self::assertSame(0.5, $settings->get('site.ratio'));
    }

    public function testNullRoundTripsAsAStoredValue(): void
    {
        $env = TestPorts::create();
        $settings = $this->settings($env);

        $settings->set('site.banner', null);

        self::assertNull($settings->get('site.banner', 'fallback'));
        self::assertSame(1, $env->database->count(Query::from('settings')->where('name', '=', 'site.banner')));
    }

    public function testNestedArraysRoundTrip(): void
    {
        $env = TestPorts::create();
        $settings = $this->settings($env);
        $value = [
            'channels' => ['email' => true, 'push' => false],
            'digest' => ['hour' => 8, 'days' => ['mon', 'thu']],
        ];

        $settings->set('notifications', $value);

        self::assertSame($value, $settings->getArray('notifications'));
    }

    public function testSettingAnExistingNameUpserts(): void
    {
        $env = TestPorts::create();
        $settings = $this->settings($env);

        $settings->set('site.name', 'Academy');
        $settings->set('site.name', 'Campus');

        self::assertSame('Campus', $settings->get('site.name'));
        self::assertSame(1, $env->database->count(Query::from('settings')));
    }

    public function testMissingNameReturnsTheDefault(): void
    {
        $env = TestPorts::create();
        $settings = $this->settings($env);

        self::assertSame('fallback', $settings->get('never.set', 'fallback'));
    }

    private function settings(TestPorts $env): Settings
    {
        return new Settings(new DatabaseSettingsRepository($env->database, $env->clock));
    }
}
