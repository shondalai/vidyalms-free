<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportMapEntry;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportMapRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportSourceKey;

/**
 * The tenant prefix, and the collision it exists to stop: two districts
 * exporting school-01 from one student information system into one site whose
 * import_map unique index is global.
 */
final class ImportSourceKeyTest extends TestCase
{
    public function testTheReservedVocabularyIsTheEightThatArePublished(): void
    {
        self::assertSame(
            ['org', 'org_unit', 'org_member', 'org_role', 'invitation', 'seat', 'user', 'catalogue_entry'],
            ImportSourceKey::RESERVED_TYPES,
        );
    }

    public function testTwoDistrictsExportingTheSameSourcedIdDoNotCollide(): void
    {
        self::assertNotSame(
            ImportSourceKey::forOrganisation(7, 'school-01'),
            ImportSourceKey::forOrganisation(9, 'school-01'),
        );
        self::assertSame('7:school-01', ImportSourceKey::forOrganisation(7, 'school-01'));
    }

    public function testAKeyWithNoOrganisationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('never the file');

        ImportSourceKey::forOrganisation(0, 'school-01');
    }

    public function testAComposedKeyReadsBackToItsOrganisationAndItsForeignKey(): void
    {
        $key = ImportSourceKey::forOrganisation(42, 'emp-001');

        self::assertSame(42, ImportSourceKey::organisationOf($key));
        self::assertSame('emp-001', ImportSourceKey::foreignKeyOf($key));
        self::assertNull(ImportSourceKey::organisationOf('emp-001'));
        self::assertSame('emp-001', ImportSourceKey::foreignKeyOf('emp-001'));
    }

    public function testAForeignKeyCarryingAColonOfItsOwnStillReadsBack(): void
    {
        $key = ImportSourceKey::forOrganisation(3, '2024:cohort:9');

        self::assertSame(3, ImportSourceKey::organisationOf($key));
        self::assertSame('2024:cohort:9', ImportSourceKey::foreignKeyOf($key));
    }

    public function testTheMapRefusesAReservedTypeWhoseKeyOmitsTheTenant(): void
    {
        $map = new ImportMap($this->repository());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Validation failed.');

        $map->duringRun(9, static function () use ($map): void {
            $map->remember('oneroster-csv-1.2:in', ImportSourceKey::TYPE_ORG_UNIT, 'school-01', 'org_unit', 5, 1);
        });
    }

    public function testTheMapRefusesAnUnprefixedLookupTooBecauseThatIsWhereTheWrongRowIsHandedBack(): void
    {
        $map = new ImportMap($this->repository());

        $this->expectException(ValidationException::class);

        $map->duringRun(9, static function () use ($map): void {
            $map->lookup('oneroster-csv-1.2:in', ImportSourceKey::TYPE_ORG_UNIT, 'school-01');
        });
    }

    /**
     * A reserved type outside a run that names an organisation.
     *
     * The prefix check used to be shape-only, so "2024:cohort-a" satisfied it
     * with no tenant in it at all and was written as organisation 2024. A
     * numeric-prefixed sourcedId is ordinary in a student information system
     * export, so this is not an exotic case.
     */
    public function testTheMapRefusesAReservedTypeOutsideAnOrganisationRunHoweverItsKeyLooks(): void
    {
        $map = new ImportMap($this->repository());

        $this->expectException(ValidationException::class);

        $map->remember('roster', ImportSourceKey::TYPE_ORG_UNIT, '2024:cohort-a', 'org_unit', 111, 1);
    }

    public function testTheMapAcceptsAProperlyComposedKey(): void
    {
        $repository = $this->repository();
        $map = new ImportMap($repository);

        $map->duringRun(9, static function () use ($map): void {
            $map->remember(
                'oneroster-csv-1.2:in',
                ImportSourceKey::TYPE_ORG_UNIT,
                ImportSourceKey::forOrganisation(9, 'school-01'),
                'org_unit',
                5,
                1,
            );

            self::assertSame(
                5,
                $map->lookup(
                    'oneroster-csv-1.2:in',
                    ImportSourceKey::TYPE_ORG_UNIT,
                    ImportSourceKey::forOrganisation(9, 'school-01'),
                ),
            );
        });

        self::assertNull(
            $map->duringRun(7, static fn (): ?int => $map->lookup(
                'oneroster-csv-1.2:in',
                ImportSourceKey::TYPE_ORG_UNIT,
                ImportSourceKey::forOrganisation(7, 'school-01'),
            )),
            'Which is the whole point: organisation 7 has not created this unit.',
        );
    }

    /**
     * A key whose prefix names an organisation this run is not.
     *
     * The shape-only guard accepted it, so a job stamped organisation 7 could
     * read and re-point organisation 9's rows with a key that looked perfectly
     * well composed. Nothing compared the prefix to the run.
     */
    public function testTheMapRefusesAKeyPrefixedWithSomebodyElsesOrganisation(): void
    {
        $map = new ImportMap($this->repository());

        $this->expectException(ValidationException::class);

        $map->duringRun(7, static function () use ($map): void {
            $map->remember(
                'oneroster-csv-1.2:in',
                ImportSourceKey::TYPE_ORG_UNIT,
                ImportSourceKey::forOrganisation(9, 'school-01'),
                'org_unit',
                5,
                1,
            );
        });
    }

    /** The narrowing is restored even when the run throws. */
    public function testTheRunTenantIsRestoredAfterTheRun(): void
    {
        $map = new ImportMap($this->repository());

        try {
            $map->duringRun(9, static function (): void {
                throw new \RuntimeException('the importer blew up');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->expectException(ValidationException::class);

        $map->remember(
            'oneroster-csv-1.2:in',
            ImportSourceKey::TYPE_ORG_UNIT,
            ImportSourceKey::forOrganisation(9, 'school-01'),
            'org_unit',
            5,
            1,
        );
    }

    public function testAnImporterUsingItsOwnWordsIsUntouched(): void
    {
        $repository = $this->repository();
        $map = new ImportMap($repository);

        $map->remember('community-quiz:in', 'cq_quiz', '41', 'quiz', 8, 1);

        self::assertSame(8, $map->lookup('community-quiz:in', 'cq_quiz', '41'));
    }

    private function repository(): ImportMapRepository
    {
        return new class implements ImportMapRepository {
            /** @var array<string, ImportMapEntry> */
            private array $rows = [];

            public function remember(
                string $importer,
                string $sourceType,
                string $sourceKey,
                string $localType,
                int $localId,
                int $jobId,
            ): void {
                $this->rows[$importer . '|' . $sourceType . '|' . $sourceKey] = new ImportMapEntry(
                    \count($this->rows) + 1,
                    $importer,
                    $sourceType,
                    $sourceKey,
                    $localType,
                    $localId,
                    $jobId,
                );
            }

            public function find(string $importer, string $sourceType, string $sourceKey): ?ImportMapEntry
            {
                return $this->rows[$importer . '|' . $sourceType . '|' . $sourceKey] ?? null;
            }

            public function forLocal(string $localType, int $localId): ?ImportMapEntry
            {
                foreach ($this->rows as $row) {
                    if ($row->localType === $localType && $row->localId === $localId) {
                        return $row;
                    }
                }

                return null;
            }

            /**
             * @return list<ImportMapEntry>
             */
            public function forJob(int $jobId): array
            {
                return array_values(array_filter(
                    $this->rows,
                    static fn (ImportMapEntry $row): bool => $row->jobId === $jobId,
                ));
            }
        };
    }
}
