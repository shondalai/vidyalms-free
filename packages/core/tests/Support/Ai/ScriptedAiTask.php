<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\TargetsExtensionPoint;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * A task whose outcome the test chooses, so the pipeline's own behaviour is what
 * is under test rather than a shipped task's.
 *
 * It calls no model and reaches no network, which every stage B test does: the
 * whole free build runs over the null bridge and a test that reached a provider
 * would be testing somebody else's uptime.
 */
final class ScriptedAiTask implements AiTaskInterface, TargetsExtensionPoint
{
    /** @var list<AiDraftCandidate> */
    private array $drafts = [];

    private ?AiRefusal $refusal = null;

    /**
     * A candidate the test built itself, for the cases that are about the
     * candidate's own shape rather than about the pipeline's behaviour.
     */
    public function willDraftCandidate(AiDraftCandidate $candidate): self
    {
        $this->drafts[] = $candidate;

        return $this;
    }

    private ?\Throwable $throws = null;

    private CostRecord $cost;

    public int $calls = 0;

    /**
     * Something the test does WHILE the task is running, which is the only way
     * to reproduce an operator ending a job under a live process.
     *
     * @var (\Closure(): void)|null
     */
    private ?\Closure $onRun = null;

    public function whileRunning(\Closure $onRun): self
    {
        $this->onRun = $onRun;

        return $this;
    }

    public function __construct(
        private readonly string $key = 'test.scripted',
        private readonly string $extensionPoint = 'question_type',
        private readonly ?string $providerKey = 'multiple_choice',
    ) {
        $this->cost = CostRecord::none();
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Scripted test task';
    }

    public function targetExtensionPoint(): string
    {
        return $this->extensionPoint;
    }

    public function targetProviderKey(): ?string
    {
        return $this->providerKey;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function willDraft(array $payload, int $ordinal = 0, ?int $parentOrdinal = null): self
    {
        $this->drafts[] = AiDraftCandidate::create(
            'question',
            $this->extensionPoint,
            $payload,
            $this->providerKey,
            ['lesson:1#0'],
            $ordinal,
            $parentOrdinal,
        );

        return $this;
    }

    public function willRefuse(AiRefusal $refusal): self
    {
        $this->refusal = $refusal;

        return $this;
    }

    public function willThrow(\Throwable $throws): self
    {
        $this->throws = $throws;

        return $this;
    }

    /** What this task reports having spent, which a failing run still records. */
    public function willSpend(CostRecord $cost): self
    {
        $this->cost = $cost;

        return $this;
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        ++$this->calls;

        if ($this->onRun !== null) {
            ($this->onRun)();
        }

        if ($this->throws !== null) {
            throw $this->throws;
        }

        if ($this->refusal !== null) {
            return AiTaskResult::refused($this->refusal, $this->cost);
        }

        return AiTaskResult::create($this->drafts, [], [], $this->cost);
    }
}
