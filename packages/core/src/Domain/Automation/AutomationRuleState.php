<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * Whether a rule may fan out at all.
 *
 * Three values and not two, and the third is the whole reason this is an enum
 * rather than a boolean. A rule whose step 0 is notify and whose step 1 is a
 * Pro action loses one action when Pro is removed. The registry miss direction
 * says the rule goes inert, which silently stops a free notification the site
 * owner still expects; the FailurePolicy axis says step 1 is a step failure
 * governed by its own policy, which lets the free half keep running while an
 * access revocation silently never happens. Both are dangerous, in opposite
 * directions.
 *
 * So a registry miss on any action, retry policy or failure policy the rule
 * names is a **rule-level** condition: the rule is degraded, does not fan out,
 * and the rules screen names the missing key. The FailurePolicy axis never
 * sees a registry miss.
 *
 * Disabled is the default in the column, so a rule cannot fire before someone
 * armed it, exactly as notification_rules defaults.
 */
enum AutomationRuleState: string
{
    case Disabled = 'disabled';
    case Enabled = 'enabled';
    case Degraded = 'degraded';

    public function fansOut(): bool
    {
        return $this === self::Enabled;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
