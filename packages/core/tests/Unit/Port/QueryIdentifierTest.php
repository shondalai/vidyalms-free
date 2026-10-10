<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Port;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Database\Identifier;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The read port's own guarantee, which used to hold only for aggregates,
 * aliases and nulls-last ordering.
 *
 * Every column a Query carries reaches SQL as text rather than as a bound
 * parameter, so the port decides what one may look like. The adapters check
 * again, and that is defence in depth: a third adapter that trusted the
 * port's docblock and checked nothing would still be safe.
 */
final class QueryIdentifierTest extends TestCase
{
    /**
     * @return array<string, array{callable(): mixed}>
     */
    public static function injections(): array
    {
        return [
            'select' => [static fn (): mixed => Query::from('courses')->select(['id, (SELECT 1)'])],
            'where' => [static fn (): mixed => Query::from('courses')->where('id = 1 OR 1', '=', 1)],
            'whereContains' => [static fn (): mixed => Query::from('courses')->whereContains('title) OR (1', 'x')],
            'whereIn' => [static fn (): mixed => Query::from('courses')->whereIn('id) OR (1', [1])],
            'whereNull' => [static fn (): mixed => Query::from('courses')->whereNull('id) OR (1')],
            'whereNotNull' => [static fn (): mixed => Query::from('courses')->whereNotNull('id) OR (1')],
            'orderBy' => [static fn (): mixed => Query::from('courses')->orderBy('title; DROP TABLE courses')],
            'groupBy' => [static fn (): mixed => Query::from('courses')->groupBy('id) OR (1')],
            'having' => [static fn (): mixed => Query::from('courses')->having('rows) OR (1', '>', 0)],
            'join column' => [
                static fn (): mixed => Query::from('courses', 'c')->join('lessons', 'l', 'l.course_id', 'c.id OR 1'),
            ],
            'join alias' => [
                static fn (): mixed => Query::from('courses', 'c')->join('lessons', 'l x', 'l.course_id', 'c.id'),
            ],
            'join table' => [
                static fn (): mixed => Query::from('courses', 'c')->join('lessons; DROP', 'l', 'l.course_id', 'c.id'),
            ],
            'table' => [static fn (): mixed => Query::from('courses; DROP TABLE courses')],
            'alias' => [static fn (): mixed => Query::from('courses', 'c OR 1')],
        ];
    }

    /**
     * @dataProvider injections
     *
     * @param callable(): mixed $build
     */
    public function testNothingButAnIdentifierReachesTheQuery(callable $build): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $build();
    }

    public function testTheShapesARealQueryNeedsAreStillAccepted(): void
    {
        $query = Query::from('courses', 'c')
            ->select(['c.*', 'c.title', 'title', 'c.slug AS course_slug'])
            ->join('lessons', 'l', 'l.course_id', 'c.id')
            ->leftJoin('modules', 'm', 'm.course_id', 'c.id')
            ->where('c.state', '=', 'published')
            ->whereContains('c.title', '50%')
            ->whereIn('c.id', [1, 2])
            ->whereNull('c.category_id')
            ->whereNotNull('c.published_at')
            ->groupBy('c.id')
            ->orderBy('c.title')
            ->orderBy('c.published_at', Query::SORT_DESC, true);

        self::assertSame(['c.*', 'c.title', 'title', 'c.slug AS course_slug'], $query->columns());
        self::assertCount(2, $query->joins());
        self::assertCount(5, $query->conditions());
    }

    public function testSelectFormsAreNamedRatherThanGuessedAt(): void
    {
        self::assertSame('*', Identifier::selection('*'));
        self::assertSame('c.*', Identifier::selection('c.*'));
        self::assertSame('c.slug AS course_slug', Identifier::selection('c.slug AS course_slug'));

        $this->expectException(\InvalidArgumentException::class);
        Identifier::selection('count(*)');
    }

    public function testAWildcardIsASelectionAndNeverAColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Identifier::column('c.*');
    }

    public function testATrailingNewlineIsNotPartOfAnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identifier::column("course_id\n");
    }
}
