<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityRule;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Reading, replacing and clearing the restriction tree on one gated row.
 *
 * A subject with no rule answers a null tree rather than a not-found,
 * because "this item has no restrictions" is an answer and the authoring
 * form has to render it.
 *
 * Only a course may carry an enrolment gate. The discriminator exists so one
 * course can hold both trees without them colliding on the unique index, and
 * so the save-time applicability refusal has something to check against.
 */
final class ManageAvailabilityRule
{
    public function __construct(
        private readonly SqlAvailabilityRuleRepository $rules,
        private readonly AvailabilityRuleValidator $validator,
        private readonly CourseAccess $courseAccess,
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $principal, GateSubject $subject, AvailabilitySurface $surface): array
    {
        $courseId = $this->requireCourse($principal, $subject, $surface);
        $rule = $this->rules->find($subject, $surface);

        return $this->present($subject, $surface, $rule, [], $courseId);
    }

    /**
     * @param array<string, mixed>|null $tree
     *
     * @return array<string, mixed>
     */
    public function put(
        Principal $principal,
        GateSubject $subject,
        AvailabilitySurface $surface,
        ?array $tree,
        ?string $defaultVisibility = null,
    ): array {
        $courseId = $this->requireCourse($principal, $subject, $surface);

        if ($defaultVisibility !== null && !\in_array($defaultVisibility, AvailabilityRule::visibilities(), true)) {
            throw ValidationException::forField('default_visibility', 'Unknown visibility.');
        }

        if ($tree === null || $tree === []) {
            $this->rules->delete($subject, $surface);

            return $this->present($subject, $surface, null, [], $courseId);
        }

        $decoded = AvailabilityTreeCodec::decode($tree);
        $report = $this->validator->validate($decoded, $surface, $subject, $courseId);

        $saved = $this->rules->save(
            new AvailabilityRule(
                null,
                $subject,
                $surface,
                $decoded,
                $defaultVisibility ?? AvailabilityRule::VISIBILITY_SHOWN,
                $principal->userId,
            ),
            $report->references,
        );

        return $this->present($subject, $surface, $saved, $report->warnings, $courseId);
    }

    public function delete(Principal $principal, GateSubject $subject, AvailabilitySurface $surface): void
    {
        $this->requireCourse($principal, $subject, $surface);
        $this->rules->delete($subject, $surface);
    }

    /**
     * The course a gated row belongs to, and the authorization on it.
     *
     * A subject type outside the declared set, or a row that does not exist,
     * is a not-found rather than a refusal, so the route never confirms the
     * existence of a row the caller may not see.
     */
    private function requireCourse(Principal $principal, GateSubject $subject, AvailabilitySurface $surface): int
    {
        if ($surface === AvailabilitySurface::EnrolmentGating && $subject->type !== GateSubject::TYPE_COURSE) {
            throw ValidationException::forField('surface', 'Only a course can carry an enrolment gate.');
        }

        $courseId = $this->courseIdOf($subject);

        if ($courseId === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for(ucfirst($subject->type), $subject->id);
        }

        $this->courseAccess->require($principal, $courseId, Capability::TEACH);

        return $courseId;
    }

    private function courseIdOf(GateSubject $subject): ?int
    {
        $row = match ($subject->type) {
            GateSubject::TYPE_COURSE => $this->database->selectOne(
                Query::from('courses')->select(['id'])->where('id', '=', $subject->id),
            ),
            GateSubject::TYPE_MODULE => $this->database->selectOne(
                Query::from('modules')->select(['course_id'])->where('id', '=', $subject->id),
            ),
            GateSubject::TYPE_LESSON => $this->database->selectOne(
                Query::from('lessons')->select(['course_id'])->where('id', '=', $subject->id),
            ),
            GateSubject::TYPE_QUIZ => $this->database->selectOne(
                Query::from('quizzes')->select(['course_id'])->where('id', '=', $subject->id),
            ),
            GateSubject::TYPE_ASSIGNMENT => $this->database->selectOne(
                Query::from('assignments', 'a')
                    ->select(['l.course_id'])
                    ->join('lessons', 'l', 'a.lesson_id', 'l.id')
                    ->where('a.id', '=', $subject->id),
            ),
            default => null,
        };

        if ($row === null) {
            return null;
        }

        $courseId = $subject->type === GateSubject::TYPE_COURSE ? ($row['id'] ?? null) : ($row['course_id'] ?? null);

        return \is_numeric($courseId) ? (int) $courseId : null;
    }

    /**
     * @param list<array{code: string, parameters: array<string, mixed>, path: string}> $warnings
     *
     * @return array<string, mixed>
     */
    private function present(
        GateSubject $subject,
        AvailabilitySurface $surface,
        ?AvailabilityRule $rule,
        array $warnings,
        int $courseId,
    ): array {
        return [
            'subject_type' => $subject->type,
            'subject_id' => $subject->id,
            'course_id' => $courseId,
            'surface' => $surface->value,
            'tree' => $rule === null ? null : AvailabilityTreeCodec::toArray($rule->tree),
            'rule_version' => $rule?->tree->version ?? AvailabilityTree::CURRENT_VERSION,
            'default_visibility' => $rule->defaultVisibility ?? AvailabilityRule::VISIBILITY_SHOWN,
            'updated_by' => $rule?->updatedBy,
            'updated_at' => $rule?->updatedAt?->format(\DateTimeInterface::ATOM),
            'warnings' => array_map(
                static fn (array $warning): array => [
                    'code' => $warning['code'],
                    'message' => $warning['code'],
                    'parameters' => $warning['parameters'],
                    'path' => $warning['path'],
                ],
                $warnings,
            ),
        ];
    }
}
