<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Stimulus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StimulusRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseStimulusRepository implements StimulusRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function add(Stimulus $stimulus): Stimulus
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $id = $this->database->insert('stimuli', [
            ...$this->toRow($stimulus),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $stimulus->withId($id);
    }

    public function byId(int $id): ?Stimulus
    {
        $row = $this->database->selectOne(Query::from('stimuli')->where('id', '=', $id));

        return $row === null ? null : $this->fromRow($row);
    }

    public function save(Stimulus $stimulus): void
    {
        if ($stimulus->id === null) {
            throw new \LogicException('Cannot save a stimulus that has not been added.');
        }

        $this->database->update('stimuli', [
            ...$this->toRow($stimulus),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $stimulus->id]);
    }

    public function forCourse(int $courseId): array
    {
        $rows = $this->database->selectAll(
            Query::from('stimuli')->where('course_id', '=', $courseId)->orderBy('id'),
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function questionCounts(array $stimulusIds): array
    {
        if ($stimulusIds === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('questions')
                ->select(['stimulus_id'])
                ->whereIn('stimulus_id', $stimulusIds),
        );

        $counts = [];

        foreach ($rows as $row) {
            $id = $row['stimulus_id'] ?? null;

            if ($id === null) {
                continue;
            }

            $counts[(int) $id] = ($counts[(int) $id] ?? 0) + 1;
        }

        return $counts;
    }

    public function remove(int $id): void
    {
        $this->database->delete('stimuli', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Stimulus $stimulus): array
    {
        return [
            'course_id' => $stimulus->courseId,
            'title' => $stimulus->title,
            'body' => $stimulus->body === null ? null : \Shondalai\VidyaLMS\Core\Domain\Shared\HtmlPolicy::safe($stimulus->body),
            'media' => $stimulus->media === null ? null : json_encode($stimulus->media, JSON_THROW_ON_ERROR),
            'alt_text' => $stimulus->altText,
            'long_description' => $stimulus->longDescription,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): Stimulus
    {
        $media = $row['media'] ?? null;
        $decoded = \is_string($media) && $media !== '' ? json_decode($media, true) : null;
        $object = null;

        if (\is_array($decoded)) {
            $object = [];

            /** @var mixed $item */
            foreach ($decoded as $name => $item) {
                $object[(string) $name] = $item;
            }
        }

        return new Stimulus(
            (int) $row['id'],
            (int) $row['course_id'],
            (string) $row['title'],
            $row['body'] === null ? null : \Shondalai\VidyaLMS\Core\Domain\Shared\HtmlPolicy::safe((string) $row['body']),
            $object,
            $row['alt_text'] === null ? null : (string) $row['alt_text'],
            $row['long_description'] === null ? null : (string) $row['long_description'],
        );
    }
}
