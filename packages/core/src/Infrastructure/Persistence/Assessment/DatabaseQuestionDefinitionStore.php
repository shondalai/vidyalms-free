<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionDefinitionStore;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StoredQuestionDefinition;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The immutable definition store against the database port.
 *
 * Insert-only by construction: there is no update and no delete in this
 * class, because the address is the content and rewriting a row would change
 * what a graded attempt was graded from.
 */
final class DatabaseQuestionDefinitionStore implements QuestionDefinitionStore
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function put(StoredQuestionDefinition $definition): string
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // An upsert on the content address rather than a select then insert,
        // so two attempts starting at once cannot race into a duplicate.
        // Nothing but the timestamp is ever rewritten.
        $this->database->upsert(
            'question_definitions',
            [
                'definition_hash' => $definition->hash,
                'type' => $definition->type,
                'definition_version' => $definition->definitionVersion,
                'definition' => json_encode($definition->definition, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['definition_hash'],
            ['updated_at'],
        );

        return $definition->hash;
    }

    public function byHash(string $hash): ?StoredQuestionDefinition
    {
        $row = $this->database->selectOne(
            Query::from('question_definitions')->where('definition_hash', '=', $hash),
        );

        return $row === null ? null : $this->fromRow($row);
    }

    public function byHashes(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('question_definitions')->whereIn('definition_hash', array_values(array_unique($hashes))),
        );

        $stored = [];

        foreach ($rows as $row) {
            $definition = $this->fromRow($row);
            $stored[$definition->hash] = $definition;
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): StoredQuestionDefinition
    {
        $decoded = json_decode((string) $row['definition'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $definition */
        $definition = \is_array($decoded) ? $decoded : [];

        return new StoredQuestionDefinition(
            (string) $row['definition_hash'],
            (string) $row['type'],
            (int) $row['definition_version'],
            $definition,
        );
    }
}
