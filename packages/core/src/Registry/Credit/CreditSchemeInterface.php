<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Credit;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ValidationFinding;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One continuing-education credit regime.
 *
 * The table owns what a regulator query groups by (scheme, amount, unit,
 * field of study, awarding body, contact minutes, reporting date) and the
 * scheme owns its own parameters, which is what makes a second regime a
 * provider rather than a migration. IACET counts a CEU as ten sixty-minute
 * contact hours; NASBA counts fifty minutes as one CPE credit with increments
 * and rounding of its own; ACCME reports a month and day of birth and never a
 * year. Those differences are real, they are arithmetic, and they belong
 * here.
 *
 * Scheme-specific learner identifiers sit behind requiredLearnerFields()
 * rather than in the user model, so adding a scheme never touches it, and the
 * values live in credit_scheme_identities.
 *
 * The miss direction is published: an unresolved scheme means the credit row
 * is stored and rendered as a raw amount and unit, with no validation and no
 * export. A compliance figure that silently disappears is worse than one that
 * is visibly unvalidated.
 */
interface CreditSchemeInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The unit a credit under this scheme is denominated in: "hours", "ceu",
     * "cpe", "moc".
     */
    public function unit(): string;

    /**
     * Contact minutes to a credit amount under this scheme's own arithmetic,
     * as a decimal string because the value is reported to a regulator and a
     * float is not a number a regulator accepts.
     */
    public function amountFor(int $contactMinutes): string;

    /**
     * The learner fields this scheme needs before a credit can be reported: a
     * certifying board id, a partial date of birth. Empty for a scheme that
     * binds to no regulator.
     *
     * @return list<string>
     */
    public function requiredLearnerFields(): array;

    /**
     * How long a record under this scheme has to be retained, in years, which
     * is metadata a retention sweep reads rather than a number written into
     * one. Five for NASBA, seven for IACET.
     */
    public function retentionYears(): int;

    /**
     * @param array<string, mixed> $params
     *
     * @return list<ValidationFinding>
     */
    public function validate(array $params): array;

    /**
     * The version this scheme writes its params at, mirrored into
     * course_credits.params_version and certificate_credits.params_version so
     * a batch upgrade is an index scan rather than a JSON path query.
     */
    public function currentShape(): int;
}
