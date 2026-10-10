<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Assessment\QuestionTypes;
use Shondalai\VidyaLMS\Core\Application\Platform\FeatureProviderLedger;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseFeatureProviderRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The ledger is written when the registry key set moves, and it is read.
 *
 * Both halves were broken and they were one defect. Recording every key of
 * every extension point on every kernel construction meant more than forty
 * upserts before a learner opening a quiz page did any work, and a repo-wide
 * search for a reader of the table found none, so the stated purpose (the
 * placeholder names the missing package, the admin can say which package is
 * gone) was unimplemented on both hosts.
 */
final class FeatureProviderLedgerTest extends TestCase
{
    public function testASecondBootWithTheSameProvidersWritesNothing(): void
    {
        $env = TestPorts::create();

        $this->buildKernel($env);
        $first = $this->updatedAtStamps($env);

        self::assertNotSame([], $first, 'the first boot records the key set');

        $env->clock->advance('PT1H');
        $this->buildKernel($env);

        self::assertSame(
            $first,
            $this->updatedAtStamps($env),
            'a boot whose key set has not moved rewrites no row',
        );
    }

    public function testAddingAProviderRewritesTheLedger(): void
    {
        $env = TestPorts::create();
        $ledger = $this->ledger($env);

        $written = $ledger->sync('vidyalms', ['question_type' => ['single_choice']]);
        self::assertSame(1, $written);

        self::assertSame(0, $ledger->sync('vidyalms', ['question_type' => ['single_choice']]));
        self::assertSame(
            2,
            $ledger->sync('vidyalms', ['question_type' => ['single_choice', 'x_acme_essay']]),
            'a key set that moved is written again',
        );
    }

    /**
     * A package that is removed and installed again heals its own ledger.
     *
     * The signature describes rows, so an uninstall that marks the rows
     * inactive has to invalidate it too. Leaving it meant the reinstall's next
     * boot wrote nothing, because the key set had not moved, and the ledger
     * went on saying nobody owned keys the package had just registered: the
     * removal placeholder then named no package at all.
     */
    public function testAReinstalledPackageWritesItsRowsAgain(): void
    {
        $env = TestPorts::create();
        $ledger = $this->ledger($env);
        $points = ['question_type' => ['numeric'], 'scoring_policy' => ['numeric_tolerance']];

        self::assertSame(2, $ledger->sync('vidyalms-pro', $points));
        self::assertSame(0, $ledger->sync('vidyalms-pro', $points), 'an unchanged key set writes nothing');

        $ledger->markInactive('vidyalms-pro');

        self::assertSame(
            2,
            $ledger->sync('vidyalms-pro', $points),
            'the reinstall writes its rows back rather than trusting a signature about rows that are gone',
        );
        self::assertSame('vidyalms-pro', $ledger->packageOf('question_type', 'numeric'));

        $active = [];

        foreach ($ledger->listActive() as $row) {
            $active[$row->extensionPoint . ':' . $row->providerKey] = $row->package;
        }

        self::assertSame('vidyalms-pro', $active['question_type:numeric'] ?? null);
        self::assertSame('vidyalms-pro', $active['scoring_policy:numeric_tolerance'] ?? null);
    }

    /**
     * The reader the design names. Without it the removal placeholder can only
     * print the type key, which is the failure the ledger exists to prevent.
     */
    public function testTheMissMessageNamesThePackageThatOwnedTheKey(): void
    {
        $env = TestPorts::create();
        $ledger = $this->ledger($env);
        $ledger->record(QuestionTypes::EXTENSION_POINT, 'x_acme_essay', 'acme-questions');
        $ledger->markInactive('acme-questions');

        self::assertSame('acme-questions', $ledger->packageOf(QuestionTypes::EXTENSION_POINT, 'x_acme_essay'));

        $kernel = $this->buildKernel($env);
        $resolver = new QuestionTypes($kernel->registries->questionTypes, $env->translator, $ledger);

        $resolved = $resolver->resolve('x_acme_essay');

        self::assertFalse($resolved->isAvailable());
        self::assertSame(
            'vidyalms.assessment.miss.unknown_type_package',
            $resolved->missFeedback(),
            'the row names the package, not only the type key',
        );
    }

    public function testAKeyNoPackageEverRecordedStillGetsAReadableRow(): void
    {
        $env = TestPorts::create();
        $kernel = $this->buildKernel($env);
        $resolver = new QuestionTypes($kernel->registries->questionTypes, $env->translator, $this->ledger($env));

        self::assertSame(
            'vidyalms.assessment.miss.unknown_type',
            $resolver->resolve('x_nobody_knows')->missFeedback(),
        );
    }

    private function ledger(TestPorts $env): FeatureProviderLedger
    {
        return new FeatureProviderLedger(
            new DatabaseFeatureProviderRepository($env->database, $env->clock),
            new DatabaseSettingsRepository($env->database, $env->clock),
        );
    }

    /**
     * @return array<string, string>
     */
    private function updatedAtStamps(TestPorts $env): array
    {
        $stamps = [];

        foreach ($env->database->selectAll(Query::from('feature_providers')->orderBy('id')) as $row) {
            $stamps[(string) $row['extension_point'] . ':' . (string) $row['provider_key']]
                = (string) $row['updated_at'];
        }

        return $stamps;
    }

    private function buildKernel(TestPorts $env): CoreKernel
    {
        return new CoreKernel(new Ports(
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
    }
}
