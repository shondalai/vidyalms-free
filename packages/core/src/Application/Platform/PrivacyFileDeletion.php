<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;

/** A transactional outbox: losing a database row never loses a pending file deletion. */
final class PrivacyFileDeletion
{
    public function __construct(private readonly DatabasePort $database, private readonly ?StoragePort $storage)
    {
    }

    public function enqueue(string $path, int $userId): void
    {
        if ($path === '') {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->database->upsert('privacy_file_deletions', [
            'path' => $path, 'path_hash' => hash('sha256', $path), 'user_id' => $userId,
            'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ], ['path_hash'], ['path']);
    }

    /** @return list<string> */
    public function drain(?int $userId = null): array
    {
        $query = Query::from('privacy_file_deletions')->orderBy('updated_at', 'ASC')->limit(100);
        if ($userId !== null) {
            $query->where('user_id', '=', $userId);
        }
        $warnings = [];
        foreach ($this->database->selectAll($query) as $row) {
            try {
                if ($this->storage === null) {
                    throw new \RuntimeException('Private storage is unavailable.');
                }
                $path = (string) $row['path'];
                if ($this->storage->exists($path)) {
                    $this->storage->delete($path);
                }
                $this->database->delete('privacy_file_deletions', ['id' => (int) $row['id']]);
            } catch (\Throwable) {
                $this->database->update('privacy_file_deletions', [
                    'attempts' => (int) $row['attempts'] + 1, 'updated_at' => gmdate('Y-m-d H:i:s'),
                ], ['id' => (int) $row['id']]);
                $warnings[] = 'Private file cleanup is pending. Restore private storage and retry the erasure.';
            }
        }
        if ($this->database->count($userId === null ? Query::from('privacy_file_deletions') : Query::from('privacy_file_deletions')->where('user_id', '=', $userId)) > 0 && $warnings === []) {
            $warnings[] = 'Private file cleanup is pending; another batch will run shortly.';
        }
        return array_values(array_unique($warnings));
    }
}
