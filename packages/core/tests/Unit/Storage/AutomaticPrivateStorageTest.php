<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\AutomaticPrivateStorage;

final class AutomaticPrivateStorageTest extends TestCase
{
    public function testWebChoosesAnExternalRootWhichCliReuses(): void
    {
        $parent = str_replace('\\', '/', sys_get_temp_dir());
        $web = $parent . '/vidyalms-auto-web';
        $site = $web . '/subdirectory';
        $record = AutomaticPrivateStorage::resolve($site, [$web, $site], 'site-a', 'server-secret', null, true);
        self::assertNotNull($record);
        self::assertSame($parent, dirname($record['path']));
        self::assertSame($record, AutomaticPrivateStorage::resolve($site, [$site], 'site-a', 'server-secret', $record, false));
        self::assertFalse(is_dir($record['path']), 'Resolving never creates files or directories.');
    }

    public function testCliCannotGuessTheDocumentRootOnFirstSetup(): void
    {
        self::assertNull(AutomaticPrivateStorage::resolve(sys_get_temp_dir(), [], 'a', 'secret', null, false));
    }

    public function testADatabaseEditCannotRepointStorage(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()) . '/vidyalms-auto-web';
        $record = AutomaticPrivateStorage::resolve($root, [$root], 'site-a', 'secret', null, true);
        self::assertNotNull($record);
        $record['path'] = dirname($root) . '/another-application';
        $this->expectException(\RuntimeException::class);
        AutomaticPrivateStorage::resolve($root, [$root], 'site-a', 'secret', $record, false);
    }

    public function testACloneCannotReuseTheOriginalSiteRecord(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()) . '/vidyalms-auto-web';
        $record = AutomaticPrivateStorage::resolve($root, [$root], 'site-a', 'secret', null, true);
        self::assertNotNull($record);
        $this->expectException(\RuntimeException::class);
        AutomaticPrivateStorage::resolve($root, [$root], 'site-clone', 'secret', $record, false);
    }
}
