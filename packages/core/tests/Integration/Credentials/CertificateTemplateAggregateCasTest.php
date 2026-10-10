<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateAggregate;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateFields;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class CertificateTemplateAggregateCasTest extends TestCase
{
    public function testOneOfTwoWritersWinsAndTheStalePayloadNeverAppears(): void
    {
        $ports = TestPorts::create();
        $winnerRepository = new SqlCertificateTemplateRepository($ports->database, $ports->clock);
        $loserRepository = new SqlCertificateTemplateRepository($ports->database, $ports->clock);
        $templateId = $winnerRepository->insert('Original', 'elements', '', false, 'draft');
        $winner = new CertificateTemplateAggregate($winnerRepository, $ports->clock, $ports->database);
        $loser = new CertificateTemplateAggregate($loserRepository, $ports->clock, $ports->database);

        $winnerResult = $winner->mutate(
            $templateId,
            1,
            function (array $template, array $pages, int $revision) use ($winnerRepository, $templateId): string {
                self::assertSame(2, $revision);
                self::assertSame(2, (int) $template['revision']);
                self::assertSame([], $pages);
                $winnerRepository->update($templateId, ['name' => 'Winner']);

                return 'winner';
            },
        );

        self::assertSame('winner', $winnerResult->value);
        self::assertSame(2, $winnerResult->revision);

        try {
            $loser->mutate(
                $templateId,
                1,
                function () use ($loserRepository, $templateId): void {
                    $loserRepository->update($templateId, ['name' => 'Loser']);
                },
            );
            self::fail('The stale writer was accepted.');
        } catch (ConflictException $failure) {
            self::assertSame(1, $failure->extensions['expected_revision']);
            self::assertArrayNotHasKey('current_revision', $failure->extensions);
        }

        $stored = $winnerRepository->findById($templateId);
        self::assertNotNull($stored);
        self::assertSame('Winner', $stored['name']);
        self::assertSame(2, (int) $stored['revision']);
    }

    public function testFailureImmediatelyAfterClaimRollsBackTheRevision(): void
    {
        $ports = TestPorts::create();
        $repository = new SqlCertificateTemplateRepository($ports->database, $ports->clock);
        $templateId = $repository->insert('Original', 'elements', '', false, 'draft');
        $aggregate = new CertificateTemplateAggregate($repository, $ports->clock, $ports->database);

        try {
            $aggregate->mutate($templateId, 1, static function (): never {
                throw new \RuntimeException('Injected after-claim failure.');
            });
            self::fail('The injected failure was not raised.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Injected after-claim failure.', $failure->getMessage());
        }

        $stored = $repository->findById($templateId);
        self::assertNotNull($stored);
        self::assertSame(1, (int) $stored['revision']);
        self::assertSame('Original', $stored['name']);
    }

    public function testFailureAfterChildWriteRollsBackTheGraphAndRevision(): void
    {
        $ports = TestPorts::create();
        $repository = new SqlCertificateTemplateRepository($ports->database, $ports->clock);
        $templateId = $repository->insert('Original', 'elements', '', false, 'draft');
        $aggregate = new CertificateTemplateAggregate($repository, $ports->clock, $ports->database);

        try {
            $aggregate->mutate(
                $templateId,
                1,
                static function () use ($repository, $templateId): never {
                    $repository->insertPage(new CertificateTemplatePage(0, $templateId));

                    throw new \RuntimeException('Injected after-child failure.');
                },
            );
            self::fail('The injected failure was not raised.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Injected after-child failure.', $failure->getMessage());
        }

        $stored = $repository->findById($templateId);
        self::assertNotNull($stored);
        self::assertSame(1, (int) $stored['revision']);
        self::assertSame([], $repository->pagesOf($templateId));
    }

    public function testMissingRootIsNotMisreportedAsARevisionConflict(): void
    {
        $ports = TestPorts::create();
        $repository = new SqlCertificateTemplateRepository($ports->database, $ports->clock);
        $aggregate = new CertificateTemplateAggregate($repository, $ports->clock, $ports->database);

        $this->expectException(EntityNotFoundException::class);
        $aggregate->mutate(404, 1, static fn (): null => null);
    }

    public function testFutureRevisionCannotClaimAgainstAnOlderObservedGraph(): void
    {
        $ports = TestPorts::create();
        $repository = new SqlCertificateTemplateRepository($ports->database, $ports->clock);
        $templateId = $repository->insert('Original', 'elements', '', false, 'draft');
        $aggregate = new CertificateTemplateAggregate($repository, $ports->clock, $ports->database);
        $called = false;

        try {
            $aggregate->mutate($templateId, 2, static function () use (&$called): void {
                $called = true;
            });
            self::fail('A future revision claimed an older aggregate graph.');
        } catch (ConflictException $failure) {
            self::assertSame(['expected_revision' => 2], $failure->extensions);
        }

        self::assertFalse($called);
        $stored = $repository->findById($templateId);
        self::assertNotNull($stored);
        self::assertSame(1, (int) $stored['revision']);
    }

    public function testExpectedRevisionAndNonEmptyPatchAreRequired(): void
    {
        $missing = new CertificateTemplateFields([]);
        $missing->expectedRevision();

        try {
            $missing->check();
            self::fail('A missing expected revision was accepted.');
        } catch (ValidationException $failure) {
            self::assertArrayHasKey('expected_revision', $failure->errors);
        }

        $empty = new CertificateTemplateFields(['expected_revision' => 1]);
        self::assertSame(1, $empty->expectedRevision());
        $empty->requireMutation(['name']);

        try {
            $empty->check();
            self::fail('An empty patch was accepted.');
        } catch (ValidationException $failure) {
            self::assertArrayHasKey('body', $failure->errors);
        }
    }

    public function testBumpRevisionRetriesItsCompareAndSwapInsteadOfOverwritingAWinningRevision(): void
    {
        $ports = TestPorts::create();
        $database = $this->createMock(DatabasePort::class);
        $database->expects(self::exactly(2))
            ->method('selectOne')
            ->willReturnOnConsecutiveCalls(['revision' => 7], ['revision' => 8]);

        $updates = [];
        $database->expects(self::exactly(2))
            ->method('update')
            ->willReturnCallback(static function (string $table, array $values, array $where) use (&$updates): int {
                $updates[] = [$table, $values, $where];

                return \count($updates) === 1 ? 0 : 1;
            });

        $repository = new SqlCertificateTemplateRepository($database, $ports->clock);

        self::assertSame(9, $repository->bumpRevision(12, $ports->clock->now()));
        self::assertSame(8, $updates[0][1]['revision']);
        self::assertSame(7, $updates[0][2]['revision']);
        self::assertSame(9, $updates[1][1]['revision']);
        self::assertSame(8, $updates[1][2]['revision']);
    }

    public function testClientRevisionCannotClaimPastSignedIntegerRange(): void
    {
        $ports = TestPorts::create();
        $repository = new SqlCertificateTemplateRepository($ports->database, $ports->clock);
        $templateId = $repository->insert('Original', 'elements', '', false, 'draft');
        $aggregate = new CertificateTemplateAggregate($repository, $ports->clock, $ports->database);

        $this->expectException(ValidationException::class);
        $aggregate->mutate($templateId, CertificateTemplateAggregate::MAX_STORED_REVISION, static fn (): null => null);
    }
}
