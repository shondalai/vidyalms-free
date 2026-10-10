<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Port;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;
use Shondalai\VidyaLMS\Tooling\Contract\DatabasePortContract;

/**
 * The harness side of the shared port contract. The same assertions run
 * against JoomlaDatabase and WpDatabase from each adapter's build/smoke.php,
 * so an aggregate that works here and not on MySQL fails there rather than
 * in production.
 */
final class DatabasePortContractTest extends TestCase
{
    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 5) . '/tooling/contract/bootstrap.php';
    }

    public function testTheSqliteHarnessSatisfiesTheDatabasePortContract(): void
    {
        $env = TestPorts::create();

        self::assertSame([], DatabasePortContract::failures($env->database));
    }

    public function testTheContractRollsBackEverythingItWrote(): void
    {
        $env = TestPorts::create();

        DatabasePortContract::failures($env->database);

        self::assertSame(
            0,
            $env->database->count(Query::from('courses')->whereContains('title', DatabasePortContract::MARKER)),
        );
        self::assertSame(0, $env->database->count(Query::from('enrolments')));
    }

    public function testLiteralPrefixDoesNotMatchAnInfixOrSqlWildcards(): void
    {
        $env = TestPorts::create();
        foreach (['course_7', 'xcourse_7', 'courseX7', 'course%7', 'course_8'] as $name) {
            $env->database->insert('settings', ['name' => $name, 'value' => '"test"',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        }
        $rows = $env->database->selectAll(Query::from('settings')->select(['name'])
            ->whereStartsWith('name', 'course_')->orderBy('name'));
        self::assertSame(['course_7', 'course_8'], array_column($rows, 'name'));
        self::assertSame(1, $env->database->count(Query::from('settings')->whereStartsWith('name', 'course%')));
    }

    public function testAnAggregateRefusesAnythingThatIsNotAnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Aggregate::countOf('COUNT(*) FROM sqlite_master', 'x');
    }

    public function testAnAggregateAliasRefusesAQualifiedName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Aggregate::count('c.total');
    }

    public function testAPredicateRefusesAnythingThatIsNotAnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Predicate::compare('1=1 OR title', '=', 'x');
    }

    public function testAnOrOfCompoundIdentitiesIsBoundAndExecutedAsGroupedPredicates(): void
    {
        $env = TestPorts::create();
        $timestamps = ['created_at' => '2026-09-05 00:00:00', 'updated_at' => '2026-09-05 00:00:00'];
        $first = $env->database->insert(
            'settings',
            ['name' => 'course:7', 'value' => '"first"', ...$timestamps],
        );
        $second = $env->database->insert(
            'settings',
            ['name' => 'quiz:9', 'value' => '"second"', ...$timestamps],
        );
        $env->database->insert(
            'settings',
            ['name' => 'course:9', 'value' => '"outside"', ...$timestamps],
        );

        $rows = $env->database->selectAll(
            Query::from('settings')
                ->select(['id'])
                ->whereAnyOf(
                    Predicate::allOf(
                        Predicate::contains('name', 'course'),
                        Predicate::compare('id', '=', $first),
                    ),
                    Predicate::allOf(
                        Predicate::contains('name', 'quiz'),
                        Predicate::compare('id', '=', $second),
                    ),
                )
                ->orderBy('id'),
        );

        self::assertSame([$first, $second], array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows,
        ));
    }

    public function testAnEmptyAndGroupIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Predicate::allOf();
    }

    public function testAQueryRefusesAnAggregateBesideAStarSelection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Query::from('enrolments')->aggregate(Aggregate::count('rows'));
    }

    public function testAQueryRefusesAStarSelectionAfterAnAggregate(): void
    {
        $query = Query::from('enrolments')->select(['course_id'])->aggregate(Aggregate::count('rows'));

        $this->expectException(\InvalidArgumentException::class);

        $query->select(['*']);
    }

    public function testNullsLastOrderingRefusesAnExpression(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Query::from('enrolments')->orderBy('COALESCE(a, b)', Query::SORT_ASC, nullsLast: true);
    }
}
