<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Progress;

interface VideoPositionRepository
{
    public function find(int $userId, int $lessonId): ?VideoPosition;

    /**
     * Idempotent upsert on the user and lesson pair.
     */
    public function save(VideoPosition $position): void;
}
