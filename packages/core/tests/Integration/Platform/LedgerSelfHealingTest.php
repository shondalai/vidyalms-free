<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Platform\FeatureProviderLedger;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Platform\FeatureProvider;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseFeatureProviderRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * An installed package has no inactive rows, and the ledger keeps that true
 * by itself.
 *
 * The signature guard says the key set has not moved, which is a weaker
 * statement than the promise the ledger makes, and the gap between the two
 * was reachable: six rows naming vidyalms-pro as missing sat on a dev site
 * while vidyalms-pro was installed and registering every one of those keys.
 * The removal placeholder and the admin report both read that table, so the
 * ledger was reporting the exact opposite of the truth and no later boot
 * would have corrected it.
 */
final class LedgerSelfHealingTest extends TestCase
{
    private const POINTS = [
        'question_type' => ['numeric', 'scalar'],
        'scoring_policy' => ['numeric_tolerance'],
    ];

    private function ledger(TestPorts $env): FeatureProviderLedger
    {
        return new FeatureProviderLedger(
            new DatabaseFeatureProviderRepository($env->database, $env->clock),
            new DatabaseSettingsRepository($env->database, $env->clock),
        );
    }

    /**
     * Inactive rows without the signature moving, which is every uninstall
     * path that did not run to the end: a process that died between the
     * update and the settings write, a database restored from before a
     * reinstall, or a build older than the signature clearing.
     */
    private function stranded(TestPorts $env): void
    {
        $env->database->update('feature_providers', ['active' => 0], ['package' => 'vidyalms-pro']);
    }

    /**
     * @return list<string>
     */
    private function activeKeys(FeatureProviderLedger $ledger): array
    {
        return array_map(
            static fn (FeatureProvider $row): string => $row->extensionPoint . ':' . $row->providerKey,
            array_values(array_filter(
                $ledger->listActive(),
                static fn (FeatureProvider $row): bool => $row->package === 'vidyalms-pro',
            )),
        );
    }

    public function testStrandedInactiveRowsAreWrittenBackOnTheNextSync(): void
    {
        $env = TestPorts::create();
        $ledger = $this->ledger($env);

        self::assertSame(3, $ledger->sync('vidyalms-pro', self::POINTS));
        self::assertSame(0, $ledger->sync('vidyalms-pro', self::POINTS));

        $this->stranded($env);
        self::assertSame([], $this->activeKeys($ledger));

        self::assertSame(
            3,
            $ledger->sync('vidyalms-pro', self::POINTS),
            'the package is registering these keys right now, so no row of it may say it is gone',
        );
        self::assertSame(
            ['question_type:numeric', 'question_type:scalar', 'scoring_policy:numeric_tolerance'],
            $this->activeKeys($ledger),
        );
    }

    /**
     * A fresh ledger object on the next request, which is what a boot
     * actually is: the healing cannot depend on the instance that wrote the
     * rows still being in memory.
     */
    public function testANewLedgerObjectHealsTheSameWay(): void
    {
        $env = TestPorts::create();
        $this->ledger($env)->sync('vidyalms-pro', self::POINTS);
        $this->stranded($env);

        self::assertSame(3, $this->ledger($env)->sync('vidyalms-pro', self::POINTS));
        self::assertSame(3, \count($this->activeKeys($this->ledger($env))));
    }

    /**
     * The healing must not undo what the signature bought. Recording every
     * key of every extension point on every request is the cost this class
     * exists to avoid, and a boot with a healthy ledger still writes nothing.
     */
    public function testAHealthyLedgerStillWritesNothingOnASecondBoot(): void
    {
        $env = TestPorts::create();
        $ledger = $this->ledger($env);

        $ledger->sync('vidyalms-pro', self::POINTS);

        self::assertSame(0, $ledger->sync('vidyalms-pro', self::POINTS));
        self::assertSame(0, $this->ledger($env)->sync('vidyalms-pro', self::POINTS));
    }

    /**
     * One package's stranded rows do not make another package rewrite its
     * own, because the count is asked per package.
     */
    public function testAnotherPackagesStrandedRowsDoNotForceThisOneToWrite(): void
    {
        $env = TestPorts::create();
        $ledger = $this->ledger($env);

        $ledger->sync('vidyalms-pro', self::POINTS);
        $ledger->sync('x-acme-extras', ['report' => ['x_acme_report']]);
        $this->stranded($env);

        self::assertSame(0, $ledger->sync('x-acme-extras', ['report' => ['x_acme_report']]));
    }

    /**
     * Both seams the free build fills are on the ledger under the core's own
     * package name, and under the point names the paid package subtracts its
     * own keys with. Two spellings of one point are two answers, and
     * packageOf() answers per point.
     */
    public function testTheFreePublicationChecksAndIntegrityKindsAreRecordedUnderTheCore(): void
    {
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
            translator: $env->translator,
            cache: $env->cache,
            clock: $env->clock,
            random: $env->random,
            logger: new NullLogger(),
        ));

        $checks = $kernel->registries->publicationChecks->keys();
        $kinds = $kernel->registries->integrityEventKinds->keys();

        self::assertNotSame([], $checks);
        self::assertNotSame([], $kinds);

        foreach ($checks as $key) {
            self::assertSame(
                'vidyalms-core',
                $kernel->featureProviders->packageOf('publication_check', $key),
                'a free check with no ledger row reads as a check nobody owns',
            );
        }

        foreach ($kinds as $key) {
            self::assertSame('vidyalms-core', $kernel->featureProviders->packageOf('integrity_event', $key));
        }

        $recorded = $env->database->selectAll(
            Query::from('feature_providers')->where('extension_point', '=', 'publication_check'),
        );

        self::assertCount(\count($checks), $recorded);
    }
}
