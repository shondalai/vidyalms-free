<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Rubric;

/**
 * One rubric version as the grading surface sees it: enough to render the
 * grid, validate a selection and name the version on the grade row, and
 * nothing about how it is stored.
 *
 * A flat value object rather than the paid package's entities, because the
 * core has to be able to carry a rubric on the wire and record its version
 * on a grade row with the package that owns it uninstalled.
 *
 * @phpstan-type RubricLevelShape array{
 *     id: int,
 *     position: int,
 *     points: float,
 *     descriptor: string
 * }
 * @phpstan-type RubricCriterionShape array{
 *     id: int,
 *     position: int,
 *     description: string,
 *     weight: float,
 *     levels: list<RubricLevelShape>
 * }
 */
final class ResolvedRubric
{
    /** @var list<RubricCriterionShape> */
    public readonly array $criteria;

    /**
     * @param list<RubricCriterionShape> $criteria
     */
    public function __construct(
        public readonly int $rubricId,
        public readonly int $versionId,
        public readonly int $version,
        public readonly string $name,
        public readonly string $kind,
        array $criteria,
    ) {
        $this->criteria = array_values($criteria);
    }

    /**
     * @return list<int>
     */
    public function criterionIds(): array
    {
        return array_map(
            static fn (array $criterion): int => $criterion['id'],
            $this->criteria,
        );
    }

    /**
     * The wire shape, which is the contract's Rubric plus the version's own
     * criteria: a grader marking an answer needs the descriptors, not a
     * pointer at them.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->rubricId,
            'rubric_version_id' => $this->versionId,
            'version' => $this->version,
            'name' => $this->name,
            'kind' => $this->kind,
            'criteria' => $this->criteria,
        ];
    }
}
