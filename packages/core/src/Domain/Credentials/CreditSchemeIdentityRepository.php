<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * The certifying board id or partial date of birth a scheme's declared
 * learner fields name.
 *
 * It exists so adding a scheme never touches the user model: ACCME reports a
 * first and last name, a month and day of birth (never the year) and a
 * certifying board id, and none of those belongs in a CMS user record this
 * product does not own.
 */
interface CreditSchemeIdentityRepository
{
    /**
     * @return array<string, string> field to value
     */
    public function forUser(int $userId, string $scheme): array;

    public function save(int $userId, string $scheme, string $field, string $value): void;

    public function delete(int $userId, string $scheme, string $field): void;

    /**
     * The fields a scheme declared that this learner has not supplied, which
     * is what a compliance export refuses on rather than exporting a row a
     * regulator will reject.
     *
     * @param list<string> $required
     *
     * @return list<string>
     */
    public function missingFor(int $userId, string $scheme, array $required): array;
}
