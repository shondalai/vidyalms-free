<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Automation;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;

/**
 * An action that cannot be rehearsed.
 *
 * SupportsDryRun being mandatory for the builder is a deliberate departure
 * from the products whose answer to the absence of a rehearsal is to advise a
 * staging site, so this exists to prove the refusal fires rather than to be
 * useful.
 */
final class NoRehearsalAction implements AutomationActionInterface, DeclaresEffectClass
{
    public const KEY = 'no_rehearsal';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.no_rehearsal';
    }

    public function paramsSchema(): array
    {
        return ['type' => 'object'];
    }

    public function effectClass(): AutomationEffectClass
    {
        return AutomationEffectClass::Records;
    }

    public function applicability(): array
    {
        return ['surfaces' => [AvailabilitySurface::Automation->value]];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        return ActionOutcome::executed();
    }
}
