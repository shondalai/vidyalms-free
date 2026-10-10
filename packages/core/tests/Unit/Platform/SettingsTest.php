<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Platform\Setting;
use Shondalai\VidyaLMS\Core\Domain\Platform\SettingsRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class SettingsTest extends TestCase
{
    public function testMissingNameFallsBackToDefault(): void
    {
        $settings = new Settings($this->repositoryWith([]));

        self::assertSame('fallback', $settings->get('missing', 'fallback'));
        self::assertSame('site', $settings->getString('missing', 'site'));
        self::assertSame(7, $settings->getInt('missing', 7));
        self::assertTrue($settings->getBool('missing', true));
        self::assertSame(['a' => 1], $settings->getArray('missing', ['a' => 1]));
    }

    public function testStoredNullIsAValueNotAnAbsence(): void
    {
        $settings = new Settings($this->repositoryWith(['flag' => null]));

        self::assertNull($settings->get('flag', 'fallback'));
    }

    public function testTypedGettersRejectMismatchedValues(): void
    {
        $settings = new Settings($this->repositoryWith([
            'name' => 42,
            'limit' => 'not an int',
            'enabled' => 1,
            'tags' => 'not an array',
        ]));

        self::assertSame('default', $settings->getString('name', 'default'));
        self::assertSame(10, $settings->getInt('limit', 10));
        self::assertFalse($settings->getBool('enabled'));
        self::assertSame([], $settings->getArray('tags'));
    }

    public function testTypedGettersReturnMatchingValues(): void
    {
        $settings = new Settings($this->repositoryWith([
            'name' => 'Academy',
            'limit' => 25,
            'enabled' => true,
            'tags' => ['a', 'b'],
        ]));

        self::assertSame('Academy', $settings->getString('name'));
        self::assertSame(25, $settings->getInt('limit'));
        self::assertTrue($settings->getBool('enabled'));
        self::assertSame(['a', 'b'], $settings->getArray('tags'));
    }

    public function testSetRejectsAnEmptyName(): void
    {
        $settings = new Settings($this->repositoryWith([]));

        $this->expectException(ValidationException::class);
        $settings->set('   ', 'value');
    }

    public function testSetStoresThroughTheRepository(): void
    {
        $repository = $this->repositoryWith([]);
        $settings = new Settings($repository);

        $settings->set('site.name', 'Academy');

        self::assertSame('Academy', $settings->get('site.name'));
    }

    public function testInitialisationCannotReplaceAnExistingSigningSecret(): void
    {
        $settings = new Settings($this->repositoryWith([]));
        self::assertSame('first-request', $settings->initialiseString('secret', 'first-request'));
        self::assertSame('first-request', $settings->initialiseString('secret', 'concurrent-request'));
        self::assertSame('first-request', $settings->getString('secret'));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function repositoryWith(array $values): SettingsRepository
    {
        $repository = new class implements SettingsRepository {
            /** @var array<string, Setting> */
            public array $items = [];

            public function find(string $name): ?Setting
            {
                return $this->items[$name] ?? null;
            }

            public function set(string $name, mixed $value): void
            {
                $this->items[$name] = new Setting($name, $value);
            }

            public function setIfAbsent(string $name, mixed $value): Setting
            {
                return $this->items[$name] ??= new Setting($name, $value);
            }
        };

        foreach ($values as $name => $value) {
            $repository->set($name, $value);
        }

        return $repository;
    }
}
