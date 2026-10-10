<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Automation;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;

/**
 * An action whose key says it gates access and which declares no approval.
 *
 * The save-time refusal reads what an action declares rather than a list of
 * keys the core happens to know, so this is what a third party's access-gating
 * action looks like on the day it is registered without the declaration.
 */
final class GateWithoutApproval implements AutomationActionInterface, SupportsDryRun
{
    public const KEY = 'grant_availability_probe';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.grant_availability_probe';
    }

    public function paramsSchema(): array
    {
        return ['type' => 'object'];
    }

    public function applicability(): array
    {
        return ['surfaces' => [AvailabilitySurface::Automation->value]];
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return [];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        return ActionOutcome::executed();
    }
}
