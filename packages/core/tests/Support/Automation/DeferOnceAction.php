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
 * An action that defers once and then executes, counting its own effects.
 *
 * It stands in for the free per-scope spend policy the design uses to describe
 * the deferral path: the ordinary path for a site whose budget is spent is
 * claim written, policy defers, claim released, and up to thirty days later
 * the row is reclaimed and finds no claim of its own. The two readings of the
 * published rule are opposite, so the case has to be exercised rather than
 * argued: short-circuiting to executed means the effect never happens and
 * nobody is told.
 */
final class DeferOnceAction implements AutomationActionInterface, DeclaresEffectClass, SupportsDryRun
{
    public const KEY = 'defer_once_probe';

    public int $effects = 0;

    private bool $deferred = false;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.defer_once_probe';
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

    public function previewFor(AutomationActionContext $context): array
    {
        return ['deferred' => $this->deferred];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        if (!$this->deferred) {
            $this->deferred = true;

            return ActionOutcome::deferred($context->now->modify('+1 hour'), 'budget_spent');
        }

        ++$this->effects;

        return ActionOutcome::executed([], ['effects' => $this->effects]);
    }
}
