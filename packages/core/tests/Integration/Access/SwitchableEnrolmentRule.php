<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleInterface;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleRegistry;

/**
 * A site owner's switch in front of the "open" enrolment rule, shaped exactly
 * like the two adapters' SiteSelfEnrolmentRule: it takes the key over with
 * replace(), keeps the rule it displaced, and delegates to it whenever the
 * switch is on. Off, it refuses, which is how the core expects a capability to
 * be turned off: a provider that says no, never a provider that was removed.
 */
final class SwitchableEnrolmentRule implements EnrolmentRuleInterface
{
    public bool $enabled = true;

    private EnrolmentRuleInterface $displaced;

    private function __construct()
    {
    }

    public static function install(EnrolmentRuleRegistry $rules): self
    {
        $rule = new self();
        $rule->displaced = $rules->replace($rule);

        return $rule;
    }

    public function key(): string
    {
        return 'open';
    }

    public function label(): string
    {
        return $this->displaced->label();
    }

    /**
     * @param array<string, mixed> $course
     */
    public function allowsSelfEnrolment(Principal $principal, array $course): bool
    {
        return $this->enabled && $this->displaced->allowsSelfEnrolment($principal, $course);
    }
}
