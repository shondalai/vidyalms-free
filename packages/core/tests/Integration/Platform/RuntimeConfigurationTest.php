<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseConfigurationRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class RuntimeConfigurationTest extends TestCase
{
    public function testHeaderDefaultsBackfillOldRowsAndCustomizedValuesPersist(): void
    {
        $env = TestPorts::create();
        $repository = new DatabaseConfigurationRepository($env->database, $env->clock);
        $repository->initialize(['academy_name' => 'Existing academy']);
        $settings = new RuntimeConfiguration($repository);
        self::assertSame('full', $settings->get('learner_header_display'));
        self::assertSame(42, $settings->get('learner_header_logo_size'));
        self::assertSame('Existing academy', $settings->get('academy_name'));
        $header = [
            'learner_header_title' => 'Learn & grow', 'learner_header_tagline' => 'A little progress every day.',
            'learner_header_show_tagline' => false, 'learner_header_show_logo' => true,
            'learner_header_logo_url' => 'https://academy.example/media/brand.png',
            'learner_header_logo_size' => 64, 'learner_header_display' => 'navigation',
            'learner_header_density' => 'compact', 'learner_header_divider' => false,
        ];
        $settings->save(1, $header);
        $next = new RuntimeConfiguration($repository);
        foreach ($header as $key => $expected) {
            self::assertSame($expected, $next->get($key));
        }
        self::assertSame('Existing academy', $next->get('academy_name'));
        $next->save(2, ['learner_header_title' => '', 'learner_header_logo_url' => '', 'learner_header_display' => 'full']);
        $next->reset();
        self::assertSame('', $next->get('learner_header_title'));
        self::assertSame('', $next->get('learner_header_logo_url'));
        self::assertFalse($next->get('learner_header_show_tagline'));
    }

    public function testRemovedLearnerChoiceIsIgnoredAndDiscardedOnNextSave(): void
    {
        $env = TestPorts::create();
        $repository = new DatabaseConfigurationRepository($env->database, $env->clock);
        $repository->initialize(['learner_navigation_choice' => true] + RuntimeConfiguration::defaults());
        $settings = new RuntimeConfiguration($repository);
        self::assertArrayNotHasKey('learner_navigation_choice', $settings->snapshot()['values']);
        $settings->save(1, ['learner_navigation' => 'sidebar']);
        self::assertArrayNotHasKey('learner_navigation_choice', $repository->read()['values'] ?? []);
    }

    public function testLearnerNavigationPolicyPersistsAcrossRequests(): void
    {
        $env = TestPorts::create();
        $repository = new DatabaseConfigurationRepository($env->database, $env->clock);
        $settings = new RuntimeConfiguration($repository);
        self::assertSame('top', $settings->get('learner_navigation'));
        $settings->save(1, ['learner_navigation' => 'sidebar']);
        $nextRequest = new RuntimeConfiguration($repository);
        self::assertSame('sidebar', $nextRequest->get('learner_navigation'));
        self::assertArrayNotHasKey('learner_navigation_choice', $nextRequest->snapshot()['values']);
    }

    public function testMigrationRunsOnceAndPreservesFalseEmptyAndConfiguredLimits(): void
    {
        $env = TestPorts::create();
        $repository = new DatabaseConfigurationRepository($env->database, $env->clock);
        $calls = 0;
        $settings = new RuntimeConfiguration($repository, static function () use (&$calls): array {
            ++$calls;
            return ['self_enrolment' => 0, 'site_base_url' => '', 'upload_max_image_mb' => '16', 'rules' => ['secret']];
        });
        self::assertFalse($settings->get('self_enrolment'));
        self::assertSame('', $settings->get('site_base_url'));
        self::assertSame(16, $settings->get('upload_max_image_mb'));
        self::assertArrayNotHasKey('rules', $settings->snapshot()['values']);
        $settings->save(1, ['catalogue_per_page' => 24]);
        $settings->reset();
        self::assertSame(24, $settings->get('catalogue_per_page'));
        self::assertSame(1, $calls);
    }

    public function testHundredsOfReadsPerformOneBoundedDatabaseQuery(): void
    {
        $env = TestPorts::create();
        $database = $this->createMock(DatabasePort::class);
        $database->expects(self::once())->method('selectAll')->willReturn([
            ['name' => DatabaseConfigurationRepository::VALUES, 'value' => json_encode(RuntimeConfiguration::defaults())],
            ['name' => DatabaseConfigurationRepository::REVISION, 'value' => '3'],
        ]);
        $settings = new RuntimeConfiguration(new DatabaseConfigurationRepository($database, $env->clock));
        for ($i = 0; $i < 100; ++$i) {
            foreach (RuntimeConfiguration::defaults() as $key => $value) {
                self::assertSame($value, $settings->get($key));
            }
        }
    }

    public function testCommittedChangesReachNewRequestsAndStaleAuthorsCannotOverwrite(): void
    {
        $env = TestPorts::create();
        $repository = new DatabaseConfigurationRepository($env->database, $env->clock);
        $one = new RuntimeConfiguration($repository);
        $two = new RuntimeConfiguration($repository);
        self::assertSame(1, $one->snapshot()['revision']);
        self::assertSame(1, $two->snapshot()['revision']);
        $one->save(1, ['self_enrolment' => false, 'site_base_url' => 'https://academy.example']);
        self::assertFalse($one->get('self_enrolment'));
        self::assertFalse((new RuntimeConfiguration($repository))->get('self_enrolment'));
        try {
            $two->save(1, ['catalogue_per_page' => 40]);
            self::fail('A stale save must be refused.');
        } catch (ConflictException) {
            self::assertSame(12, (new RuntimeConfiguration($repository))->get('catalogue_per_page'));
            self::assertSame(2, ($repository->read()['revision'] ?? null));
        }
    }

    public function testInvalidPartialUpdatesNeverWriteOrEvictTheCommittedSnapshot(): void
    {
        $env = TestPorts::create();
        $repository = new DatabaseConfigurationRepository($env->database, $env->clock);
        $settings = new RuntimeConfiguration($repository);
        $settings->snapshot();
        foreach (
            [
            ['self_enrolment' => 'false'], ['catalogue_per_page' => 51], ['integrations_http_timeout' => 121],
            ['unsubscribe_base_url' => 'http://example.test'], ['site_base_url' => 'javascript:alert(1)'],
            ['site_base_url' => 'https://user:password@example.test'], ['private_storage_path' => '../private'],
            ['private_storage_dir' => '../../private'], ['integrations_http_allowlist' => 'https://example.test/path'],
            ['private_storage_path' => 'D:/private/vidyalms'], ['private_storage_path' => '/srv/www/wp-admin'],
            ['private_storage_dir' => 'wp-admin'],
            ['unknown_setting' => true],
            ['learner_navigation' => 'floating'], ['learner_navigation' => true],
            ['learner_navigation_choice' => true],
            ['learner_header_display' => 'overlay'], ['learner_header_density' => 'huge'],
            ['learner_header_logo_size' => 23], ['learner_header_logo_size' => 81], ['learner_header_logo_size' => 42.5],
            ['learner_header_logo_url' => 'javascript:alert(1)'], ['learner_header_logo_url' => 'data:image/svg+xml,test'],
            ['learner_header_logo_url' => 'https://user:password@example.test/logo.png'],
            ['learner_header_title' => str_repeat('A', 121)], ['learner_header_tagline' => str_repeat('A', 181)],
            ['learner_header_title' => "Two\nlines"], ['learner_header_show_logo' => 'false'],
            ] as $invalid
        ) {
            try {
                $settings->save(1, ['academy_name' => 'Must not be saved'] + $invalid);
                self::fail('Invalid configuration was accepted.');
            } catch (ValidationException) {
                self::assertSame('', $settings->get('academy_name'));
                self::assertSame(1, ($repository->read()['revision'] ?? null));
            }
        }
        $settings->save(1, ['integrations_http_allowlist' => ".stripe.com\napi.example.test"]);
        self::assertSame(".stripe.com\napi.example.test", $settings->get('integrations_http_allowlist'));
    }
}
