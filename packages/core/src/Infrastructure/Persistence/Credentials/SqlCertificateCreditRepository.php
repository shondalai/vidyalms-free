<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

final class SqlCertificateCreditRepository implements CertificateCreditRepository
{
    private const TABLE = 'certificate_credits';
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function forCertificate(int $certificateId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('certificate_id', '=', $certificateId)
                ->orderBy('id', Query::SORT_ASC),
        );

        return array_map($this->map(...), $rows);
    }

    public function insert(int $certificateId, CertificateCredit $credit): int
    {
        $now = $this->clock->now()->format(self::DATETIME);

        return $this->database->insert(self::TABLE, [
            'certificate_id' => $certificateId,
            'scheme' => $credit->scheme,
            // A decimal string all the way to the column, because the value is
            // reported to a regulator and a float is not a number a regulator
            // accepts.
            'amount' => $credit->amount,
            'unit' => $credit->unit,
            'field_of_study' => $credit->fieldOfStudy,
            'awarding_body_id' => $credit->awardingBodyId,
            'contact_minutes' => $credit->contactMinutes,
            'params' => $credit->params === null
                ? null
                : json_encode(StoredShape::wrap($credit->params, $credit->paramsVersion), JSON_THROW_ON_ERROR),
            'params_version' => $credit->paramsVersion,
            'reported_at' => $credit->reportedAt?->format(self::DATETIME),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function insertAll(int $certificateId, array $credits): void
    {
        foreach ($credits as $credit) {
            $this->insert($certificateId, $credit);
        }
    }

    public function unreportedForScheme(string $scheme, int $limit): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('scheme', '=', $scheme)
                ->whereNull('reported_at')
                ->orderBy('id', Query::SORT_ASC)
                ->limit($limit),
        );

        $records = [];

        foreach ($rows as $row) {
            $records[] = ['certificate_id' => (int) $row['certificate_id'], 'credit' => $this->map($row)];
        }

        return $records;
    }

    public function markReported(int $creditId, \DateTimeImmutable $reportedAt): void
    {
        $this->database->update(self::TABLE, [
            'reported_at' => $reportedAt->format(self::DATETIME),
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $creditId]);
    }

    /**
     * A credit amount reads back as a fixed two-place decimal string on every
     * engine.
     *
     * The column is decimal and never float, because the value is reported to
     * a regulator. SQLite has no fixed-point type and hands "6" back for a
     * stored 6.00, MySQL hands back "6.00", and a compliance export that
     * formatted differently depending on which database a site runs is a
     * defect nobody would find until an auditor did.
     */
    private function decimal(mixed $value): string
    {
        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '0.00';
        }

        return '0.00';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): CertificateCredit
    {
        $params = null;
        $raw = $row['params'] ?? null;

        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (\is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $params = StoredShape::bodyOf($decoded);
            }
        }

        $reportedAt = $row['reported_at'] ?? null;

        return new CertificateCredit(
            (string) $row['scheme'],
            $this->decimal($row['amount'] ?? null),
            (string) $row['unit'],
            \is_string($row['field_of_study'] ?? null) && $row['field_of_study'] !== ''
                ? $row['field_of_study']
                : null,
            \is_string($row['awarding_body_id'] ?? null) && $row['awarding_body_id'] !== ''
                ? $row['awarding_body_id']
                : null,
            isset($row['contact_minutes']) ? (int) $row['contact_minutes'] : null,
            $params,
            (int) ($row['params_version'] ?? 1),
            \is_string($reportedAt) && $reportedAt !== ''
                ? new \DateTimeImmutable($reportedAt, new \DateTimeZone('UTC'))
                : null,
            (int) $row['id'],
            (int) $row['certificate_id'],
        );
    }
}
