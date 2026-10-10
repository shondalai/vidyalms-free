<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The task seam, with the registration-time refusals the design asks for.
 *
 * Three rules, and all three are enforced here rather than at run time,
 * because every one of them describes a task that was misdeclared when it was
 * written and would otherwise fail on somebody's screen months later.
 *
 * **A task declaring a fact nothing supplies is refused.** A fact is a
 * registered provider, which is what lets a vendor ship a nineteenth without
 * a core edit, and it is also what makes an undeclared fact key a save-time
 * refusal rather than a run-time surprise. The check is the same shape a
 * condition leaf used outside its declared applicability already gets.
 *
 * **A task declaring findings only may not also target an extension point.**
 * A check that could write is a check that will eventually be wired to
 * auto-approve. The rule is asserted over the declared property and never
 * over a key prefix: a vendor quality check named after their own namespace
 * escaped a prefix test entirely, and a vendor content task whose key happens
 * to begin `check.` was wrongly constrained by one, on a key that may never
 * change once shipped.
 *
 * **A task whose declared output schema binds a scored or gated field is
 * refused.** With that rule written only into a screen spec, a vendor task
 * declaring a suggested mark plus a vendor field-fill placement passed every
 * structural test the design had, because a field-filling helper declares no
 * extension point and the tests looked only at extension points.
 *
 * @extends AbstractProviderRegistry<AiTaskInterface>
 */
final class AiTaskRegistry extends AbstractProviderRegistry
{
    /**
     * The fact registry this one validates declarations against.
     *
     * It is bound after construction rather than injected, because Registries
     * default-constructs every registry it holds and a constructor argument
     * here would make the seating order of two registries load bearing. Until
     * it is bound the fact rule cannot be evaluated and is not pretended to
     * be: an unbound registry admits a task whose facts nothing has been
     * asked about yet, and the boot that registers the free tasks binds this
     * first.
     */
    private ?AiFactProviderRegistry $facts = null;

    /**
     * @var callable(array<string, mixed>): list<string>|null
     */
    private $reservedFieldScan = null;

    protected function providerInterface(): string
    {
        return AiTaskInterface::class;
    }

    /**
     * Bind the fact registry every later registration is checked against.
     *
     * Called once at boot, before the free tasks register, so a third-party
     * task registering later in the same boot gets the same check the shipped
     * ones do.
     */
    public function validateFactsAgainst(AiFactProviderRegistry $facts): void
    {
        $this->facts = $facts;
    }

    /**
     * Bind the scan that decides whether an output schema binds a reserved
     * field.
     *
     * It arrives as a callable rather than as a class reference because the
     * reserved list is published by a free provider and a registry in the
     * seam namespace may not reach into the provider tree. The boot wires the
     * two together, and an unbound scan means the rule is not evaluated
     * rather than silently passing something it never looked at.
     *
     * @param callable(array<string, mixed>): list<string> $scan
     */
    public function refuseReservedFieldsWith(callable $scan): void
    {
        $this->reservedFieldScan = $scan;
    }

    protected function guardProvider(ProviderInterface $provider): void
    {
        if (!$provider instanceof AiTaskInterface) {
            return;
        }

        $this->guardFacts($provider);
        $this->guardFindingsOnly($provider);
        $this->guardOutputSchema($provider);
    }

    private function guardFacts(AiTaskInterface $task): void
    {
        if ($this->facts === null || !$task instanceof RequiresFacts) {
            return;
        }

        foreach ($task->requiredFacts() as $fact) {
            if (!$this->facts->has($fact)) {
                throw new \InvalidArgumentException(sprintf(
                    'The task "%s" declares the fact "%s", which no AiFactProvider supplies. '
                    . 'A fact is a registered provider, so this is a missing registration and not a '
                    . 'reason to hand the task a database.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $task->key(),
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $fact,
                ));
            }
        }
    }

    private function guardFindingsOnly(AiTaskInterface $task): void
    {
        if (!$task instanceof ProducesFindingsOnly) {
            return;
        }

        if ($task instanceof TargetsExtensionPoint) {
            throw new \InvalidArgumentException(sprintf(
                'The task "%s" declares ProducesFindingsOnly and TargetsExtensionPoint. A check that can '
                . 'write is a check that will eventually be wired to approve.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $task->key(),
            ));
        }

        if (!$task instanceof DeclaresTaskOutput) {
            return;
        }

        $kind = $task->draftKind();

        if ($kind !== null && $kind !== AiDraftKinds::FINDINGS) {
            throw new \InvalidArgumentException(sprintf(
                'The task "%s" declares ProducesFindingsOnly and a draft kind of "%s". A findings-only '
                . 'task produces findings, which is the kind "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $task->key(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $kind,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                AiDraftKinds::FINDINGS,
            ));
        }
    }

    private function guardOutputSchema(AiTaskInterface $task): void
    {
        if ($this->reservedFieldScan === null || !$task instanceof DeclaresTaskOutput) {
            return;
        }

        $bound = ($this->reservedFieldScan)($task->outputSchema());

        if ($bound === []) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'The task "%s" declares an output schema binding the reserved field or fields "%s". No AI '
            . 'output may bind a scored or gated field, and the rule is a contract rather than a screen '
            . 'note precisely so a field-filling helper cannot walk around it.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $task->key(),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            implode(', ', $bound),
        ));
    }
}
