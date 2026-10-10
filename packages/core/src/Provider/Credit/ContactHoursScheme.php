<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Credit;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ValidationFinding;
use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeInterface;
use Shondalai\VidyaLMS\Core\Registry\Credit\DescribesCreditSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsFields;

/**
 * The free credit scheme: contact hours, recorded and reported to nobody.
 *
 * It exists so the seam is exercised by free tests rather than only by the
 * paid build. It binds to no regulator, needs no learner identifier and
 * carries no arithmetic beyond minutes to hours, which is exactly what makes
 * it safe to ship free: a site that records six contact hours has said
 * something true, and a site that needs CEUs or CPE credits buys the scheme
 * that knows the rounding rules.
 *
 * Its authored source is course_credits, and IssueCertificate turns those
 * rows into certificate_credits rows inside the certificate's own
 * transaction, because a credit row without its certificate is a compliance
 * defect under regimes that retain records for five and seven years.
 */
final class ContactHoursScheme implements CreditSchemeInterface, DescribesCreditSettings
{
    public const KEY = 'contact_hours';

    private const MINUTES_PER_HOUR = 60;

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.credit_scheme.contact_hours');
    }

    public function unit(): string
    {
        return 'hours';
    }

    /**
     * Minutes to hours, to two places, with no rounding rule of its own. The
     * schemes that round (NASBA rounds down where total minutes are not
     * divisible by fifty) say so in their own arithmetic; inventing one here
     * would make the free scheme quietly wrong for the regime it is standing
     * in for.
     */
    public function amountFor(int $contactMinutes): string
    {
        if ($contactMinutes <= 0) {
            return '0.00';
        }

        return number_format($contactMinutes / self::MINUTES_PER_HOUR, 2, '.', '');
    }

    public function requiredLearnerFields(): array
    {
        return [];
    }

    /**
     * Nothing regulates contact hours, so nothing dictates a retention
     * period. Three years is the site's own record keeping and is stated
     * rather than left null, because a null here reads as "keep for ever".
     */
    public function retentionYears(): int
    {
        return 3;
    }

    public function validate(array $params): array
    {
        $minutes = $params['contact_minutes'] ?? null;

        if ($minutes === null) {
            return [ValidationFinding::error(
                'contact_hours.contact_minutes_required',
                'vidyalms.credit_scheme.error.contact_minutes_required',
            )];
        }

        if (!\is_int($minutes) || $minutes <= 0) {
            return [ValidationFinding::error(
                'contact_hours.contact_minutes_invalid',
                'vidyalms.credit_scheme.error.contact_minutes_invalid',
            )];
        }

        return [];
    }

    public function edition(): string
    {
        return 'free';
    }

    public function settingsGroup(SettingsDeclarationContext $context): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'fields' => [
                SettingsFields::integer(
                    'contact_minutes',
                    $this->translator->translate('vidyalms.credit_scheme.field.contact_minutes'),
                    60,
                    minimum: 1,
                ),
            ],
        ];
    }

    public function currentShape(): int
    {
        return 1;
    }
}
