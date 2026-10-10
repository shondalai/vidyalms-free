<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The same drift guard the provider labels have, for the option labels an
 * importer declares. An option is copy on a screen, so it goes through
 * TranslatorPort with a key the neutral catalogue defines, and a hardcoded
 * English label or a missing key fails here rather than shipping as a blank
 * control on a translated site.
 */
final class ImporterOptionCatalogueTest extends TestCase
{
    public function testEveryDeclaredImporterOptionResolvesThroughTheCatalogue(): void
    {
        $recorder = new class implements TranslatorPort {
            /** @var list<string> */
            private array $keys = [];

            public function translate(string $key, array $parameters = []): string
            {
                $this->keys[] = $key;

                return $key;
            }

            /**
             * @return list<string>
             */
            public function drain(): array
            {
                $keys = $this->keys;
                $this->keys = [];

                return $keys;
            }
        };

        $env = TestPorts::create();
        $kernel = new CoreKernel(new Ports(
            database: $env->database,
            users: $env->users,
            authorization: $env->authorization,
            storage: $env->storage,
            mailer: $env->mailer,
            scheduler: $env->scheduler,
            eventBridge: $env->eventBridge,
            commerce: $env->commerce,
            translator: $recorder,
            cache: $env->cache,
            clock: $env->clock,
            random: $env->random,
            logger: new NullLogger(),
        ));

        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';

        $declared = 0;

        foreach ($kernel->registries->importers->all() as $importer) {
            if (!$importer instanceof ConfigurableImporterInterface) {
                continue;
            }

            $recorder->drain();
            $options = $importer->importOptions();
            $keys = $recorder->drain();

            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalogue, sprintf(
                    '%s declares an option string "%s" that the catalogue does not define.',
                    $importer->key(),
                    $key,
                ));
            }

            $seen = [];

            foreach ($options as $option) {
                self::assertNotContains($option->key, $seen, sprintf(
                    '%s declares the "%s" option twice.',
                    $importer->key(),
                    $option->key,
                ));
                $seen[] = $option->key;

                self::assertContains($option->label, $keys, sprintf(
                    'The label of %s option "%s" must be the translated string.',
                    $importer->key(),
                    $option->key,
                ));

                ++$declared;
            }
        }

        self::assertGreaterThan(0, $declared, 'The free build declares importer options.');
    }
}
