<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresIdempotencyDiscriminator;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;

/**
 * An action whose effect is a counter and nothing else.
 *
 * The nine free actions all re-read their own state before acting, so every
 * one of them masks a double execution: the second pass finds the tag already
 * set and suppresses. A third-party action registered on this seam does not,
 * which is why the exactly-once guarantee has to be measured with something
 * that cannot mask it.
 */
final class CountingAction implements
    AutomationActionInterface,
    DeclaresEffectClass,
    DeclaresIdempotencyDiscriminator,
    SupportsDryRun
{
    public const KEY = 'counting_probe';

    /** The string the core appends to the canonical digest, never substitutes. */
    public const DISCRIMINATOR = 'shard-7';

    public int $effects = 0;

    /** How deep the transaction nesting was when the effect ran. */
    public int $depthAtEffect = -1;

    public function __construct(private readonly ?TransactionWitnessDatabase $witness = null)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * A finer grain than the canonical digest, declared so the queue row and
     * the ledger claim can be shown to agree on what one effect is.
     */
    public function idempotencyDiscriminator(AutomationActionContext $context): string
    {
        return self::DISCRIMINATOR;
    }

    public function label(): string
    {
        return 'test.counting_probe';
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
        return AutomationEffectClass::Records;
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return ['would_count' => $context->actsForUserId()];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        if ($context->dryRun) {
            return ActionOutcome::executed([], $this->previewFor($context));
        }

        ++$this->effects;
        $this->depthAtEffect = $this->witness?->depth() ?? -1;

        return ActionOutcome::executed([], ['effects' => $this->effects]);
    }
}
