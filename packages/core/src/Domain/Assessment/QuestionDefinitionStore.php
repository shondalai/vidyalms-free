<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * The immutable, content-addressed definition store a regrade grades
 * against. Entries are written once and never updated: writing the same
 * definition twice is a no-op, because the address is the content.
 */
interface QuestionDefinitionStore
{
    /**
     * Stores the definition if the store does not already hold it, and
     * returns its content address either way.
     */
    public function put(StoredQuestionDefinition $definition): string;

    public function byHash(string $hash): ?StoredQuestionDefinition;

    /**
     * @param list<string> $hashes
     *
     * @return array<string, StoredQuestionDefinition> keyed by hash
     */
    public function byHashes(array $hashes): array;
}
