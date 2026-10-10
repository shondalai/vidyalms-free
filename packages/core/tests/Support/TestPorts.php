<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Port\Ports;

/**
 * One call builds a complete, isolated core environment: an in-memory SQLite
 * database with the full schema installed and a fake for every other port.
 * Individual fakes stay reachable for arrangement and assertion.
 */
final class TestPorts
{
    private function __construct(
        public readonly Ports $ports,
        public readonly SqliteDatabase $database,
        public readonly FakeUsers $users,
        public readonly StubAuthorization $authorization,
        public readonly FakeStorage $storage,
        public readonly CollectingMailer $mailer,
        public readonly CollectingScheduler $scheduler,
        public readonly CollectingEventBridge $eventBridge,
        public readonly StubCommerce $commerce,
        public readonly StubTranslator $translator,
        public readonly MutableLocale $locale,
        public readonly InMemoryCache $cache,
        public readonly FrozenClock $clock,
        public readonly FakeRandom $random,
        public readonly FakeCourseCategories $courseCategories,
        public readonly FakeSecrets $secrets,
    ) {
    }

    public static function create(bool $allowAllAuthorization = false): self
    {
        $database = SqliteDatabase::inMemory();
        SqliteSchemaInstaller::install($database);

        $users = new FakeUsers();
        $authorization = new StubAuthorization($allowAllAuthorization);
        $storage = new FakeStorage();
        $mailer = new CollectingMailer();
        $scheduler = new CollectingScheduler();
        $eventBridge = new CollectingEventBridge();
        $commerce = new StubCommerce();
        $translator = new StubTranslator();
        $locale = new MutableLocale();
        $cache = new InMemoryCache();
        $clock = new FrozenClock();
        $random = new FakeRandom();
        $courseCategories = new FakeCourseCategories();
        $secrets = new FakeSecrets();

        $ports = new Ports(
            database: $database,
            users: $users,
            authorization: $authorization,
            storage: $storage,
            mailer: $mailer,
            scheduler: $scheduler,
            eventBridge: $eventBridge,
            commerce: $commerce,
            translator: $translator,
            cache: $cache,
            clock: $clock,
            random: $random,
            logger: new NullLogger(),
            locale: $locale,
            courseCategories: $courseCategories,
            secrets: $secrets,
        );

        return new self(
            $ports,
            $database,
            $users,
            $authorization,
            $storage,
            $mailer,
            $scheduler,
            $eventBridge,
            $commerce,
            $translator,
            $locale,
            $cache,
            $clock,
            $random,
            $courseCategories,
            $secrets,
        );
    }
}
