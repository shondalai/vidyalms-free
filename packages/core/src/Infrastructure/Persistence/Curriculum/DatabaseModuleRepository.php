<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseModuleRepository implements ModuleRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function byId(int $id): ?Module
    {
        $row = $this->database->selectOne(Query::from('modules')->where('id', '=', $id));

        return $row === null ? null : $this->map($row);
    }

    public function byCourse(int $courseId): array
    {
        $rows = $this->database->selectAll(
            Query::from('modules')
                ->where('course_id', '=', $courseId)
                ->orderBy('position')
                ->orderBy('id'),
        );

        return array_map($this->map(...), $rows);
    }

    public function idsByCourse(int $courseId): array
    {
        $rows = $this->database->selectAll(
            Query::from('modules')
                ->select(['id'])
                ->where('course_id', '=', $courseId)
                ->orderBy('position')
                ->orderBy('id'),
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function nextPosition(int $courseId): int
    {
        $row = $this->database->selectOne(
            Query::from('modules')
                ->select(['position'])
                ->where('course_id', '=', $courseId)
                ->orderBy('position', Query::SORT_DESC),
        );

        return $row === null ? 1 : ((int) $row['position']) + 1;
    }

    public function add(Module $module): int
    {
        $now = $this->now();

        return $this->database->insert('modules', $this->toRow($module) + [
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(Module $module): void
    {
        $this->database->update(
            'modules',
            $this->toRow($module) + ['updated_at' => $this->now()],
            ['id' => $module->id],
        );
    }

    public function updatePositions(array $positions): void
    {
        $now = $this->now();

        $this->database->transactional(function () use ($positions, $now): void {
            foreach ($positions as $moduleId => $position) {
                $this->database->update(
                    'modules',
                    ['position' => $position, 'updated_at' => $now],
                    ['id' => $moduleId],
                );
            }
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Module
    {
        return new Module(
            id: (int) $row['id'],
            courseId: (int) $row['course_id'],
            title: (string) $row['title'],
            summary: $row['summary'] === null ? null : (string) $row['summary'],
            position: (int) $row['position'],
            state: (string) $row['state'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Module $module): array
    {
        return [
            'course_id' => $module->courseId,
            'title' => $module->title,
            'summary' => $module->summary,
            'position' => $module->position,
            'state' => $module->state,
        ];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
