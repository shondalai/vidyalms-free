<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Shika;

use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\HostSource;

/**
 * Every read the Shika importer makes, in one place.
 *
 * ## The schema this was written from, and what could not be confirmed
 *
 * Shika (com_tjlms, by Techjoomla) is a commercial component and its install
 * script is not published. Its table names are taken from the component's own
 * `tj_lms_` family as the product names them, and nothing about their columns
 * could be confirmed from any published source. **Every table here is
 * therefore UNCONFIRMED**, is read with a wildcard select, and has its fields
 * picked out by name with alternatives, so one wrong guess costs a field
 * rather than a table.
 *
 * ## Two spellings of the same family
 *
 * Shika has shipped its tables under `tj_lms_` and under `tjlms_`. Both
 * spellings are in the closed list below and the run picks one by probing,
 * once, before it reads anything: a table name is never taken from an option
 * or a row, and the two spellings are two constants rather than one string
 * with a hole in it.
 *
 * The lesson formats are the one thing here that is confirmed. Techjoomla's
 * own published documentation of Shika's lesson type plugins quotes the
 * component's format array verbatim as `'scorm', 'htmlzips', 'tincanlrs',
 * 'video', 'document', 'textmedia', 'externaltool'`, and the mapper uses
 * exactly those words.
 */
final class ShikaSource extends HostSource
{
    public const T_COURSES = 'tj_lms_courses';
    public const T_MODULES = 'tj_lms_modules';
    public const T_LESSONS = 'tj_lms_lessons';
    public const T_LESSON_TRACK = 'tj_lms_lesson_track';
    public const T_QUIZ = 'tj_lms_quiz';
    public const T_QUIZ_QUESTIONS = 'tj_lms_quiz_questions';
    public const T_QUIZ_OPTIONS = 'tj_lms_quiz_options';

    public const LEGACY_COURSES = 'tjlms_courses';
    public const LEGACY_MODULES = 'tjlms_modules';
    public const LEGACY_LESSONS = 'tjlms_lessons';
    public const LEGACY_LESSON_TRACK = 'tjlms_lesson_track';
    public const LEGACY_QUIZ = 'tjlms_quiz';
    public const LEGACY_QUIZ_QUESTIONS = 'tjlms_quiz_questions';
    public const LEGACY_QUIZ_OPTIONS = 'tjlms_quiz_options';

    /**
     * The current family, keyed by the logical table, and the legacy family
     * beside it. Both are closed constant lists; resolve() chooses between
     * them and nothing else ever does.
     *
     * @var array<string, string>
     */
    private const CURRENT = [
        'courses' => self::T_COURSES,
        'modules' => self::T_MODULES,
        'lessons' => self::T_LESSONS,
        'lesson_track' => self::T_LESSON_TRACK,
        'quiz' => self::T_QUIZ,
        'quiz_questions' => self::T_QUIZ_QUESTIONS,
        'quiz_options' => self::T_QUIZ_OPTIONS,
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY = [
        'courses' => self::LEGACY_COURSES,
        'modules' => self::LEGACY_MODULES,
        'lessons' => self::LEGACY_LESSONS,
        'lesson_track' => self::LEGACY_LESSON_TRACK,
        'quiz' => self::LEGACY_QUIZ,
        'quiz_questions' => self::LEGACY_QUIZ_QUESTIONS,
        'quiz_options' => self::LEGACY_QUIZ_OPTIONS,
    ];

    /** @var array<string, string> the family this run reads */
    private array $family = self::CURRENT;

    /**
     * @return list<string>
     */
    public static function tableNames(): array
    {
        return [...array_values(self::CURRENT), ...array_values(self::LEGACY)];
    }

    /**
     * Fixes which family of table names this run reads, once, before
     * anything else. Answers false when neither family is there, which is
     * what "Shika is not installed here" looks like from the core.
     */
    public function resolve(): bool
    {
        if ($this->has(self::T_COURSES)) {
            $this->family = self::CURRENT;

            return true;
        }

        if ($this->has(self::LEGACY_COURSES)) {
            $this->family = self::LEGACY;

            return true;
        }

        return false;
    }

    /** The courses table this run is reading, for a sentence about it. */
    public function coursesTable(): string
    {
        return $this->family['courses'];
    }

    public function modulesTable(): string
    {
        return $this->family['modules'];
    }

    public function lessonsTable(): string
    {
        return $this->family['lessons'];
    }

    public function quizzesTable(): string
    {
        return $this->family['quiz'];
    }

    public function questionsTable(): string
    {
        return $this->family['quiz_questions'];
    }

    public function trackTable(): string
    {
        return $this->family['lesson_track'];
    }

    /**
     * @param list<int> $courseIds
     *
     * @return list<array<string, mixed>>
     */
    public function courses(array $courseIds): array
    {
        $query = $this->read($this->family['courses'])->select(['*'])->orderBy('id');

        if ($courseIds !== []) {
            $query->whereIn('id', $courseIds);
        }

        return $this->all($query, ['*']);
    }

    /**
     * The modules of a course, when the install has a module table at all.
     * An install without one is not broken: its lessons hang straight off the
     * course, and the importer gives them one module of its own.
     *
     * @return list<array<string, mixed>>
     */
    public function modules(int $courseId): array
    {
        if (!$this->has($this->family['modules'])) {
            return [];
        }

        return $this->ordered($this->children($this->family['modules'], ['course_id', 'courseid'], $courseId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lessons(int $courseId): array
    {
        return $this->ordered($this->children($this->family['lessons'], ['course_id', 'courseid'], $courseId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function quizzes(int $lessonId): array
    {
        return $this->ordered($this->children($this->family['quiz'], ['lesson_id', 'lessonid'], $lessonId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function questions(int $quizId): array
    {
        return $this->ordered($this->children($this->family['quiz_questions'], ['quiz_id', 'quizid'], $quizId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function options(int $questionId): array
    {
        return $this->ordered($this->children($this->family['quiz_options'], ['question_id', 'questionid'], $questionId));
    }

    /**
     * Who was on this course and what they finished.
     *
     * Shika's own enrolment table is not one this importer could confirm the
     * name of, so the tracking table the brief names is the only evidence it
     * reads: a person with a tracking row was on the course. The importer's
     * log says so, because inferring access from activity is a judgement and
     * a site owner has to be able to disagree with it.
     *
     * @return list<array<string, mixed>>
     */
    public function lessonTrack(int $courseId): array
    {
        return $this->children($this->family['lesson_track'], ['course_id', 'courseid'], $courseId);
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return self::tableNames();
    }

    /**
     * The rows of an unconfirmed table that belong to one parent, tried
     * against each foreign key spelling in turn. Only the last failure
     * escapes: a WHERE on a column that is not there is the evidence that the
     * other spelling is the right one.
     *
     * @param list<string> $foreignKeys
     *
     * @return list<array<string, mixed>>
     */
    private function children(string $table, array $foreignKeys, int $parentId): array
    {
        $last = \count($foreignKeys) - 1;

        foreach ($foreignKeys as $index => $column) {
            try {
                return $this->all($this->read($table)->select(['*'])->where($column, '=', $parentId), [$column]);
            } catch (\Throwable $failure) {
                if ($index === $last) {
                    throw $failure;
                }
            }
        }

        return [];
    }
}
