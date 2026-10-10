<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DescriptionContext;

/**
 * "Why can this student not see it", answered leaf by leaf.
 *
 * Exposing the evaluator only through a debug string for unit tests is why
 * that question is a permanent forum genre on every platform that keeps it to
 * itself. This runs the real composer against the real facts for that learner
 * at a chosen instant, and marks each leaf pass or fail with the reason its
 * own description returned.
 *
 * Never and exhausted are shown here and never on a learner-facing route: to
 * a learner an item that will never open and an item that has closed both
 * read as blocked with a reason, and publishing the distinction would say
 * which exclusions were administrative.
 */
final class PreviewAvailabilityRule
{
    public function __construct(
        private readonly SqlAvailabilityRuleRepository $rules,
        private readonly AvailabilityComposer $composer,
        private readonly AvailabilityConditionRegistry $conditions,
        private readonly CourseAccess $courseAccess,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly LocalePort $locale,
    ) {
    }

    /**
     * @param array<string, mixed>|null $draft an unsaved tree, evaluated without being stored
     *
     * @return array<string, mixed>
     */
    public function execute(
        Principal $principal,
        int $userId,
        ?GateSubject $subject,
        ?int $courseId,
        ?array $draft,
        ?\DateTimeImmutable $asOf,
    ): array {
        if ($userId < 1) {
            throw ValidationException::forField('user_id', 'A learner is required.');
        }

        // The subject decides the course, never the body.
        //
        // A null-coalescing assignment let the caller supply both
        // independently, so the authorization ran against a course the caller
        // chose while the stored tree was loaded from a subject they chose
        // separately: an instructor refused a foreign lesson's rule by its own
        // route read the whole of it here, reasons, dates and per-leaf
        // descriptions included. The body's course id survives only for the
        // draft case, where there is no subject to derive one from.
        if ($subject !== null) {
            $subjectCourseId = $this->courseIdOf($subject);

            if ($subjectCourseId === null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw EntityNotFoundException::for(ucfirst($subject->type), $subject->id);
            }

            if ($courseId !== null && $courseId !== $subjectCourseId) {
                throw ValidationException::forField('course_id', 'The subject belongs to another course.');
            }

            $courseId = $subjectCourseId;
        }

        if ($courseId === null) {
            throw ValidationException::forField('course_id', 'A course is required when no subject is given.');
        }

        $this->courseAccess->require($principal, $courseId, Capability::TEACH);

        $tree = $draft !== null
            ? AvailabilityTreeCodec::decode($draft)
            : ($subject === null
                ? new AvailabilityTree(null)
                : ($this->rules->find($subject, AvailabilitySurface::ContentGating)->tree
                    ?? new AvailabilityTree(null)));

        // The named learner must be one the caller may already see: an
        // instructor of one course cannot evaluate a rule against a learner
        // they have no reason to know exists.
        $enrolment = $this->database->selectOne(
            Query::from('enrolments')->where('course_id', '=', $courseId)->where('user_id', '=', $userId),
        );

        if ($enrolment === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Enrolment', $userId);
        }

        $course = $this->database->selectOne(Query::from('courses')->where('id', '=', $courseId));
        $now = $asOf ?? $this->clock->now();

        $context = AvailabilityContext::create(
            Principal::user($userId),
            AvailabilitySurface::ContentGating,
            $now,
            $enrolment,
            $course,
            $subject,
        );

        $decisions = $this->composer->evaluateSet(['preview' => new SubjectTree($tree, $subject)], $context);
        $outcome = ($decisions['preview'] ?? AvailabilityDecision::open())->outcome;

        return [
            'user_id' => $userId,
            'as_of' => $now->format(\DateTimeInterface::ATOM),
            'outcome' => $outcome->toAuthorArray(),
            'leaves' => $this->leafRows($tree, $context),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leafRows(AvailabilityTree $tree, AvailabilityContext $context): array
    {
        $rows = [];
        $description = DescriptionContext::forAuthor($this->locale->current());

        foreach ($tree->leaves() as $path => $leaf) {
            $available = $this->conditions->has($leaf->type);

            if (!$available) {
                $rows[] = [
                    'path' => $path,
                    'type' => $leaf->type,
                    'negated' => false,
                    'passed' => false,
                    'available' => false,
                    'outcome' => \Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome::blocked(
                        AvailabilityComposer::REASON_PROVIDER_MISSING,
                        ['provider' => $leaf->type],
                    )->toAuthorArray(),
                    'description' => ['key' => AvailabilityComposer::REASON_PROVIDER_MISSING, 'parameters' => []],
                ];
                continue;
            }

            $condition = $this->conditions->get($leaf->type);
            $leafOutcome = $condition->evaluate($leaf->payload, false, $context);
            $reason = $condition->describe($leaf->payload, false, $description);

            $rows[] = [
                'path' => $path,
                'type' => $leaf->type,
                'negated' => false,
                'passed' => $leafOutcome->isAvailable(),
                'available' => true,
                'outcome' => $leafOutcome->toAuthorArray(),
                'description' => $reason->toArray(),
            ];
        }

        return $rows;
    }

    private function courseIdOf(GateSubject $subject): ?int
    {
        if ($subject->type === GateSubject::TYPE_COURSE) {
            return $subject->id;
        }

        $table = match ($subject->type) {
            GateSubject::TYPE_MODULE => 'modules',
            GateSubject::TYPE_LESSON => 'lessons',
            GateSubject::TYPE_QUIZ => 'quizzes',
            default => null,
        };

        if ($table === null) {
            return null;
        }

        $row = $this->database->selectOne(
            Query::from($table)->select(['course_id'])->where('id', '=', $subject->id),
        );

        $courseId = $row['course_id'] ?? null;

        return \is_numeric($courseId) ? (int) $courseId : null;
    }
}
