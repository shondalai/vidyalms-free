<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Expiry;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryContext;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryPolicyInterface;

/**
 * The three expiry shapes the stored vocabulary names, as three providers.
 *
 * These are the free build's own: expiry_mode holds never, fixed or
 * days_after_issue, the issue path resolves the key it finds, and the answer
 * is frozen into the snapshot with everything else. Registering them rather
 * than branching on them is what makes a fourth shape a registration, and it
 * is what stops the issue path and a later sweep disagreeing about the date a
 * holder's own certificate prints.
 *
 * A paid package adds the shapes a real regime needs beyond these three (the
 * end of a reporting year, an anniversary) into this same registry, and the
 * same free call site resolves them.
 */
final class ExpiryPolicies
{
    public const NEVER = 'never';
    public const FIXED = 'fixed';
    public const DAYS_AFTER_ISSUE = 'days_after_issue';

    private function __construct()
    {
    }

    /**
     * @return list<ExpiryPolicyInterface>
     */
    public static function all(TranslatorPort $translator): array
    {
        return [
            // The column's own default. It answers null and is registered
            // anyway, because a template naming "never" must resolve to a
            // provider rather than to a miss that happens to mean the same
            // thing: the two are only the same until somebody changes what a
            // miss means.
            new CallableExpiryPolicy(
                self::NEVER,
                'vidyalms.expiry_policy.never',
                $translator,
                static fn (ExpiryContext $context): ?\DateTimeImmutable => null,
                static fn (ExpiryContext $context): bool => true,
            ),
            // A date the author typed, used as it stands. It is the shape a
            // course with a syllabus review date needs, and the only one
            // whose answer does not move with the issue.
            new CallableExpiryPolicy(
                self::FIXED,
                'vidyalms.expiry_policy.fixed',
                $translator,
                static fn (ExpiryContext $context): ?\DateTimeImmutable => $context->fixedAt,
                static fn (ExpiryContext $context): bool => $context->fixedAt !== null,
            ),
            // N days after validity begins. Counted from valid_from and not
            // from the issue, because a credential issued three days after
            // the completion it certifies would otherwise be in force three
            // days longer than the one issued the same day.
            new CallableExpiryPolicy(
                self::DAYS_AFTER_ISSUE,
                'vidyalms.expiry_policy.days_after_issue',
                $translator,
                static function (ExpiryContext $context): ?\DateTimeImmutable {
                    $days = $context->days;

                    return $days === null || $days <= 0
                        ? null
                        : $context->effectiveFrom()->add(new \DateInterval('P' . $days . 'D'));
                },
                static fn (ExpiryContext $context): bool => $context->days !== null && $context->days > 0,
            ),
        ];
    }
}
