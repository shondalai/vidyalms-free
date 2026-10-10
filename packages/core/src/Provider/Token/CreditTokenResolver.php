<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Token;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;
use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\ContributesIssuanceParameters;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * The continuing-education credit this issuance carries.
 *
 * It reads the values the issuance froze rather than the credit rows, because
 * a certificate renders from its snapshot and never from live data: a credit
 * row corrected in 2028 must not change what a 2026 certificate says it
 * awarded.
 *
 * The five singular placeholders answer the first credit row and keep
 * answering it, because templates authored before this family grew a list
 * name them. A completion really can carry credit under two schemes, so the
 * whole set travels beside them as one structured token rather than as a
 * numbered family: a flat family drops the eleventh row, and a certificate
 * that silently states one of two schemes is a compliance defect on the
 * document a state board reviews.
 */
final class CreditTokenResolver implements TokenResolverInterface, ContributesIssuanceParameters
{
    public const PREFIX = 'credit';

    public const TOKEN_ROWS = 'credit.rows';

    /** The parameter key the structured token is contributed under. */
    public const PARAM_ROWS = 'credit_rows';

    public function key(): string
    {
        return self::PREFIX;
    }

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function label(): string
    {
        return 'vidyalms.token.credit';
    }

    public function tokens(): array
    {
        return [
            'credit.amount',
            'credit.unit',
            'credit.scheme',
            'credit.field_of_study',
            'credit.contact_hours',
            self::TOKEN_ROWS,
        ];
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        $value = match ($token) {
            'credit.amount' => $context->string('credit_amount'),
            'credit.unit' => $context->string('credit_unit'),
            'credit.scheme' => $context->string('credit_scheme'),
            'credit.field_of_study' => $context->string('credit_field_of_study'),
            'credit.contact_hours' => $this->contactHours($context),
            self::TOKEN_ROWS => $context->string(self::PARAM_ROWS),
            default => null,
        };

        return $value === '' ? null : $value;
    }

    /**
     * The family's own inputs, so it reads back exactly what it wrote rather
     * than what some other file happened to assemble.
     *
     * @return array<string, mixed>
     */
    public function parametersFor(IssuanceParameterContext $context): array
    {
        $rows = self::rowsOf($context->credits);

        if ($rows === []) {
            return [];
        }

        $first = $rows[0];

        return array_filter([
            'credit_amount' => $first['amount'],
            'credit_unit' => $first['unit'],
            'credit_scheme' => $first['scheme'],
            'credit_field_of_study' => $first['field_of_study'],
            'credit_contact_minutes' => $first['contact_minutes'],
            self::PARAM_ROWS => self::encode($rows),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<array{scheme: string, amount: string, unit: string, field_of_study: string, contact_minutes: string}>
     */
    public static function decodeRows(?string $encoded): array
    {
        if ($encoded === null || $encoded === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($encoded, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $scheme = self::text($row['scheme'] ?? null);
            $amount = self::text($row['amount'] ?? null);

            if ($scheme === '' || $amount === '') {
                continue;
            }

            $rows[] = [
                'scheme' => $scheme,
                'amount' => $amount,
                'unit' => self::text($row['unit'] ?? null),
                'field_of_study' => self::text($row['field_of_study'] ?? null),
                'contact_minutes' => self::text($row['contact_minutes'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @param list<CertificateCredit> $credits
     *
     * @return list<array{scheme: string, amount: string, unit: string, field_of_study: string, contact_minutes: string}>
     */
    private static function rowsOf(array $credits): array
    {
        $rows = [];

        foreach ($credits as $credit) {
            $rows[] = [
                'scheme' => $credit->scheme,
                'amount' => $credit->amount,
                'unit' => $credit->unit,
                'field_of_study' => $credit->fieldOfStudy ?? '',
                'contact_minutes' => $credit->contactMinutes === null ? '' : (string) $credit->contactMinutes,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{scheme: string, amount: string, unit: string, field_of_study: string, contact_minutes: string}> $rows
     */
    private static function encode(array $rows): ?string
    {
        try {
            return json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function text(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        return is_numeric($value) ? (string) $value : '';
    }

    /**
     * Minutes are the stored fact and hours are what a certificate says, so
     * the conversion happens here rather than being written into a column
     * that would then disagree with the minutes beside it.
     */
    private function contactHours(TokenContext $context): ?string
    {
        $minutes = $context->string('credit_contact_minutes');

        if ($minutes === null || $minutes === '' || !is_numeric($minutes)) {
            return null;
        }

        $hours = ((float) $minutes) / 60;

        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }
}
