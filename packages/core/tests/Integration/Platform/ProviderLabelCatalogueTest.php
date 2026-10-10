<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\PresetContext;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Guard against catalogue drift: every provider label the free build
 * registers must resolve through TranslatorPort with a key the neutral
 * catalogue defines. A hardcoded English label or a key missing from the
 * catalogue fails here.
 */
final class ProviderLabelCatalogueTest extends TestCase
{
    public function testEveryDefaultProviderLabelKeyExistsInTheCatalogue(): void
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
             * Returns the keys recorded since the last drain and resets.
             *
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

        $providers = array_merge(
            $kernel->registries->lessonTypes->all(),
            $kernel->registries->questionTypes->all(),
            $kernel->registries->enrolmentRules->all(),
            $kernel->registries->commerceSources->all(),
            $kernel->registries->notificationChannels->all(),
            $kernel->registries->reports->all(),
            $kernel->registries->certificateRenderers->all(),
            $kernel->registries->importers->all(),
            // The quiz-level seams. Their labels reach an author through the
            // settings schema, so they are copy and go through the same port
            // as every other provider label.
            $kernel->registries->attemptPolicies->all(),
            $kernel->registries->aggregationMethods->all(),
            $kernel->registries->quizCompositions->all(),
        );

        self::assertNotSame([], $providers, 'The free build must register default providers.');

        $recorder->drain();

        foreach ($providers as $provider) {
            $label = $provider->label();
            $keys = $recorder->drain();

            self::assertNotSame([], $keys, sprintf(
                '%s::label() must resolve through TranslatorPort, not hardcode copy.',
                $provider::class,
            ));

            foreach ($keys as $key) {
                self::assertStringStartsWith('vidyalms.', $key, sprintf(
                    '%s::label() must use a vidyalms.* catalogue key, got "%s".',
                    $provider::class,
                    $key,
                ));
                self::assertArrayHasKey($key, $catalogue, sprintf(
                    '%s::label() translates "%s" but the catalogue does not define it.',
                    $provider::class,
                    $key,
                ));
            }

            self::assertContains($label, $keys, sprintf(
                '%s::label() must return the translated string.',
                $provider::class,
            ));
        }

        // A preset's label reaches the author's picker through
        // GET /question-types, so it is copy and goes through the same port.
        // It used to be an English literal handed in at boot, which also made
        // the label unlocalisable per request.
        $presetContext = PresetContext::create('en-GB');

        foreach ($kernel->registries->questionPresets->all() as $preset) {
            $recorder->drain();
            $label = $preset->label($presetContext);
            $keys = $recorder->drain();

            self::assertSame([$label], $keys, sprintf(
                'preset %s must resolve its label through TranslatorPort, not hardcode copy.',
                $preset->key(),
            ));
            self::assertArrayHasKey($label, $catalogue, sprintf(
                'preset %s translates "%s" but the catalogue does not define it.',
                $preset->key(),
                $label,
            ));
        }
    }

    /**
     * The nine AI seams, and the three disclosure audiences of every
     * placement.
     *
     * They are a case of their own rather than rows in the loop above,
     * because an AI provider's label is a catalogue KEY returned as a string
     * rather than a call through TranslatorPort: the presenter translates it
     * at the edge, so the assertion that can be made here is that every key
     * the registries publish is a key the catalogue defines.
     *
     * All ninety-two of them were missing. `GET /ai/placements` answered with
     * the raw keys in its disclosure block, so the Article 50 text a learner
     * is owed before the first interaction was a dotted identifier, and no
     * gate looked at any of it: this test's own registry list walked eleven
     * registries and not one of the AI ones.
     */
    public function testEveryAiProviderLabelAndDisclosureKeyExistsInTheCatalogue(): void
    {
        $registries = ManagementFixture::create()->kernel->registries;

        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';

        $keys = [];

        foreach (
            [
                'aiTasks' => $registries->aiTasks,
                'aiPlacements' => $registries->aiPlacements,
                'aiSurfaces' => $registries->aiSurfaces,
                'modelBridges' => $registries->modelBridges,
                'aiPolicies' => $registries->aiPolicies,
                'aiRedactors' => $registries->aiRedactors,
                'aiFactProviders' => $registries->aiFactProviders,
                'aiDraftAcceptors' => $registries->aiDraftAcceptors,
                'aiRecipes' => $registries->aiRecipes,
            ] as $name => $registry
        ) {
            self::assertNotSame([], $registry->keys(), $name . ' ships no free provider');

            foreach ($registry->all() as $provider) {
                $keys[$provider->label()] = $name;
            }
        }

        foreach ($registries->aiPlacements->all() as $placement) {
            self::assertInstanceOf(AiPlacementInterface::class, $placement);
            $disclosure = $placement->disclosure();

            // All three audiences, because a placement that discloses to the
            // reviewer and not to the learner has met none of the obligation.
            $keys[$disclosure->beforeInteractionKey] = 'disclosure of ' . $placement->key();
            $keys[$disclosure->atReadTimeKey] = 'disclosure of ' . $placement->key();
            $keys[$disclosure->atReviewKey] = 'disclosure of ' . $placement->key();
        }

        foreach ($keys as $key => $source) {
            self::assertArrayHasKey($key, $catalogue, sprintf(
                'the %s publishes "%s" and the catalogue does not define it, so a screen renders the key',
                $source,
                $key,
            ));
            self::assertNotSame('', trim($catalogue[$key]), $key . ' is defined as an empty string');
        }
    }

    /**
     * The AI readers translate before they publish, rather than handing the
     * screen a dotted identifier.
     *
     * The case above proves the catalogue defines every AI label key. It does
     * not prove anybody looks one up, and nobody did: an AI placement's
     * `label()` answers its own catalogue key by construction, and the three
     * readers that publish it passed it through untouched. The first live
     * administrator to open the AI screen therefore read
     * "ai.placement.admin_narrative.label" beside each of the nine helper
     * toggles, and `GET /ai/placements` answered the same to the generic mount.
     * NotificationCatalogue and ManageProviderSettings had translated on the
     * way out since the wave that built them; these three had not.
     */
    public function testTheAiReadersPublishTranslatedLabelsAndNeverACatalogueKey(): void
    {
        $env = TestPorts::create(true);
        $kernel = new CoreKernel(new Ports(
            database: $env->database,
            users: $env->users,
            authorization: $env->authorization,
            storage: $env->storage,
            mailer: $env->mailer,
            scheduler: $env->scheduler,
            eventBridge: $env->eventBridge,
            commerce: $env->commerce,
            translator: new CatalogueTranslator(),
            cache: $env->cache,
            clock: $env->clock,
            random: $env->random,
            logger: new NullLogger(),
        ));

        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';
        $manager = Principal::user(1);

        $enablement = $kernel->aiStopControl->enablement($manager);
        /** @var list<array<string, mixed>> $placements */
        $placements = \is_array($enablement['placements'] ?? null) ? $enablement['placements'] : [];

        self::assertNotSame([], $placements, 'the enablement panel lists no placement to label');

        foreach ($placements as $placement) {
            $key = (string) ($placement['key'] ?? '');
            $label = (string) ($placement['label'] ?? '');

            self::assertArrayNotHasKey($label, $catalogue, sprintf(
                'the enablement panel published the catalogue key for %s instead of its copy',
                $key,
            ));
            self::assertSame($catalogue['ai.placement.' . $key . '.label'] ?? null, $label, sprintf(
                'the enablement panel must publish the catalogue copy for %s',
                $key,
            ));
        }

        $catalogueRead = $kernel->aiPlacementCatalogue->execute($manager, null, []);
        /** @var list<array<string, mixed>> $offered */
        $offered = \is_array($catalogueRead['items'] ?? null) ? $catalogueRead['items'] : [];

        self::assertNotSame([], $offered, 'the placement catalogue offers nothing to label');

        foreach ($offered as $offer) {
            foreach (['label', 'surface_label'] as $field) {
                $value = (string) ($offer[$field] ?? '');

                self::assertArrayNotHasKey($value, $catalogue, sprintf(
                    'the placement catalogue published a catalogue key in %s for %s',
                    $field,
                    (string) ($offer['key'] ?? ''),
                ));
            }

            /** @var list<array<string, mixed>> $tasks */
            $tasks = \is_array($offer['tasks'] ?? null) ? $offer['tasks'] : [];

            foreach ($tasks as $task) {
                self::assertArrayNotHasKey((string) ($task['label'] ?? ''), $catalogue, sprintf(
                    'the placement catalogue published the catalogue key for task %s',
                    (string) ($task['key'] ?? ''),
                ));
            }
        }
    }

    /**
     * The neutral English the core falls back to when a caller has no
     * translator must be the catalogue's own, or a detached grade row and a
     * request-path grade row say different things.
     */
    public function testTheInlineFallbackEnglishMatchesTheCatalogue(): void
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';

        self::assertNotSame([], NeutralStrings::catalogue());

        foreach (NeutralStrings::catalogue() as $key => $english) {
            self::assertArrayHasKey($key, $catalogue, sprintf('NeutralStrings names "%s"; the catalogue does not.', $key));
            self::assertSame($catalogue[$key], $english, sprintf('NeutralStrings and the catalogue disagree about "%s".', $key));
        }
    }
}
