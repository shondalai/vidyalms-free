<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\RequiresApprovalBeforeEffect;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;

/**
 * An action that gates content and declares it, standing in for the first Pro
 * `grant_availability`.
 *
 * It counts its effects, because the two halves of the human-in-the-loop
 * guarantee are exactly "the effect does not run before a person releases it"
 * and "the effect does run once one has", and only a counter can tell those
 * apart from a step that never ran at all.
 */
final class GateWithApproval implements
    AutomationActionInterface,
    DeclaresEffectClass,
    RequiresApprovalBeforeEffect,
    SupportsDryRun
{
    public const KEY = 'acme.unlock_module';

    public int $effects = 0;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.acme.unlock_module';
    }

    public function paramsSchema(): array
    {
        return ['type' => 'object'];
    }

    public function applicability(): array
    {
        return ['subject_types' => ['user'], 'trigger_keys' => []];
    }

    public function effectClass(): AutomationEffectClass
    {
        return AutomationEffectClass::Access;
    }

    public function approvalReasonKey(): string
    {
        return 'test.acme.unlock_module.reason';
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return ['would_unlock' => $context->actsForUserId()];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        if ($context->dryRun) {
            return ActionOutcome::executed([], $this->previewFor($context));
        }

        ++$this->effects;

        return ActionOutcome::executed([], ['unlocked' => $context->actsForUserId()]);
    }
}
