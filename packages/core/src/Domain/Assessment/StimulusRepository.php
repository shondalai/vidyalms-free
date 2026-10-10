<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

interface StimulusRepository
{
    public function add(Stimulus $stimulus): Stimulus;

    public function byId(int $id): ?Stimulus;

    public function save(Stimulus $stimulus): void;

    /**
     * @return list<Stimulus>
     */
    public function forCourse(int $courseId): array;

    /**
     * How many questions point at each of these stimuli, keyed by stimulus
     * id. A stimulus nothing uses is absent.
     *
     * The count is what the authoring list shows and what the removal
     * refuses on, so it is one query for a page rather than one per row.
     *
     * @param list<int> $stimulusIds
     *
     * @return array<int, int>
     */
    public function questionCounts(array $stimulusIds): array;

    /**
     * Removes a stimulus no question points at. The caller checks the count
     * first; this is the write.
     */
    public function remove(int $id): void;
}
