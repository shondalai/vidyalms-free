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
 * A paid package's action, present while a rule is authored and absent
 * afterwards.
 *
 * It stands in for the Pro webhook on the harness, because the conformance
 * case the design names is "uninstall Pro and assert every rule carrying a Pro
 * action moves to degraded while rules using only free actions keep running",
 * and a harness cannot uninstall a package it never installed.
 */
final class PretendProAction implements AutomationActionInterface, DeclaresEffectClass, SupportsDryRun
{
    public const KEY = 'webhook_probe';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.webhook_probe';
    }

    public function paramsSchema(): array
    {
        return ['type' => 'object'];
    }

    public function effectClass(): AutomationEffectClass
    {
        return AutomationEffectClass::External;
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
