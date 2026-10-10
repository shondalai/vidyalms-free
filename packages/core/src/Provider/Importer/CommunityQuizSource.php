<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Every read the Community Quiz importer makes, in one place.
 *
 * Community Quiz keeps its tables in the same database as VidyaLMS, so the
 * rows come back through the same DatabasePort the rest of the core uses,
 * named as host tables (Query::fromHostTable) so the adapter resolves them
 * with the host prefix and none of ours: "cq_courses" becomes "#__cq_courses"
 * on Joomla. Only reads exist here, and only reads are possible: the port's
 * write methods have no host form.
 *
 * The table prefix inside the host database is a constructor argument rather
 * than a constant because the core may not assume the name of a table it does
 * not own. The importer takes it from the "table_prefix" option, which the
 * adapter supplies, and it defaults to Community Quiz's own "cq_".
 *
 * Columns are listed explicitly: this is a foreign schema, and the list is the
 * contract with it. A column the importer does not read is a column Community
 * Quiz may rename without breaking the migration.
 */
final class CommunityQuizSource
{
    /**
     * The prefix and every table name must compile to a bare SQL identifier,
     * which is also what the adapters enforce before they touch the database.
     */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly string $prefix,
    ) {
        if (preg_match(self::IDENTIFIER, $prefix . 'courses') !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('"%s" is not a usable Community Quiz table prefix.', $prefix));
        }
    }

    /**
     * Which Community Quiz this is. Two installs can share one database under
     * two prefixes, and their ids overlap, so the import map keys everything
     * under this.
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * The courses the source selector picked, oldest first. Both filters
     * empty means every course.
     *
     * @param list<int> $courseIds
     * @param list<int> $categoryIds
     *
     * @return list<array<string, mixed>>
     */
    public function courses(array $courseIds, array $categoryIds): array
    {
        $query = $this->read('courses')->select([
            'id',
            'catid',
            'title',
            'alias',
            'subtitle',
            'description',
            'level',
            'access_model',
            'completion',
            'drip',
            'governance',
            'cert_template_id',
            'cert_config',
            'visibility',
            'show_in_catalog',
            'state',
            'language',
        ])->orderBy('id');

        if ($courseIds !== []) {
            $query->whereIn('id', $courseIds);
        }

        if ($categoryIds !== []) {
            $query->whereIn('catid', $categoryIds);
        }

        return $this->database->selectAll($query);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function modules(int $courseId): array
    {
        return $this->database->selectAll(
            $this->read('course_modules')
                ->select(['id', 'course_id', 'title', 'summary', 'ordering'])
                ->where('course_id', '=', $courseId)
                ->orderBy('ordering')
                ->orderBy('id'),
        );
    }

    /**
     * Every lesson of the course in curriculum order, whichever module it
     * belongs to.
     *
     * @return list<array<string, mixed>>
     */
    public function lessons(int $courseId): array
    {
        return $this->database->selectAll(
            $this->read('lessons')
                ->select([
                    'id',
                    'course_id',
                    'module_id',
                    'title',
                    'type',
                    'content',
                    'quiz_id',
                    'is_preview',
                    'duration',
                    'ordering',
                ])
                ->where('course_id', '=', $courseId)
                ->orderBy('ordering')
                ->orderBy('id'),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function quiz(int $quizId): ?array
    {
        return $this->database->selectOne(
            $this->read('quizzes')
                ->select([
                    'id',
                    'title',
                    'intro',
                    'kind',
                    'duration',
                    'pass_mark',
                    'scale_max',
                    'attempts_allowed',
                    'feedback',
                    'state',
                ])
                ->where('id', '=', $quizId),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sections(int $quizId): array
    {
        return $this->database->selectAll(
            $this->read('sections')
                ->select(['id', 'quiz_id', 'type', 'title', 'ordering'])
                ->where('quiz_id', '=', $quizId)
                ->orderBy('ordering')
                ->orderBy('id'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sectionItems(int $sectionId): array
    {
        return $this->database->selectAll(
            $this->read('section_items')
                ->select(['id', 'section_id', 'question_id', 'points_override', 'ordering'])
                ->where('section_id', '=', $sectionId)
                ->orderBy('ordering')
                ->orderBy('id'),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function question(int $questionId): ?array
    {
        return $this->database->selectOne(
            $this->read('questions')
                ->select(['id', 'type', 'title', 'stem', 'content', 'points', 'feedback', 'rubric_id', 'state'])
                ->where('id', '=', $questionId),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enrolments(int $courseId): array
    {
        return $this->database->selectAll(
            $this->read('enrollments')
                ->select(['id', 'course_id', 'user_id', 'status', 'source', 'enrolled_at', 'expires_at'])
                ->where('course_id', '=', $courseId)
                ->orderBy('id'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function prerequisites(int $courseId): array
    {
        return $this->database->selectAll(
            $this->read('course_prerequisites')
                ->select(['id', 'course_id', 'required_type', 'required_id'])
                ->where('course_id', '=', $courseId)
                ->orderBy('id'),
        );
    }

    public function lessonProgressCount(int $courseId): int
    {
        return $this->database->count(
            $this->read('lesson_progress')->select(['id'])->where('course_id', '=', $courseId),
        );
    }

    public function attemptCount(int $quizId): int
    {
        return $this->database->count(
            $this->read('attempts')->select(['id'])->where('quiz_id', '=', $quizId),
        );
    }

    public function blueprintCount(int $quizId): int
    {
        return $this->database->count(
            $this->read('blueprints')->select(['id'])->where('quiz_id', '=', $quizId),
        );
    }

    public function accommodationCount(int $quizId): int
    {
        return $this->database->count(
            $this->read('accommodations')->select(['id'])->where('quiz_id', '=', $quizId),
        );
    }

    private function read(string $table): Query
    {
        return Query::fromHostTable($this->prefix . $table);
    }
}
