<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CreditSchemeIdentityRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class SqlCreditSchemeIdentityRepository implements CreditSchemeIdentityRepository
{
    private const TABLE = 'credit_scheme_identities';
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function forUser(int $userId, string $scheme): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('user_id', '=', $userId)
                ->where('scheme', '=', $scheme)
                ->orderBy('field', Query::SORT_ASC),
        );

        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row['field']] = (string) $row['value'];
        }

        return $values;
    }

    public function save(int $userId, string $scheme, string $field, string $value): void
    {
        $now = $this->clock->now()->format(self::DATETIME);

        $this->database->upsert(
            self::TABLE,
            [
                'user_id' => $userId,
                'scheme' => $scheme,
                'field' => $field,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['user_id', 'scheme', 'field'],
            ['value', 'updated_at'],
        );
    }

    public function delete(int $userId, string $scheme, string $field): void
    {
        $this->database->delete(self::TABLE, ['user_id' => $userId, 'scheme' => $scheme, 'field' => $field]);
    }

    public function missingFor(int $userId, string $scheme, array $required): array
    {
        $held = $this->forUser($userId, $scheme);

        return array_values(array_filter(
            $required,
            static fn (string $field): bool => ($held[$field] ?? '') === '',
        ));
    }
}
