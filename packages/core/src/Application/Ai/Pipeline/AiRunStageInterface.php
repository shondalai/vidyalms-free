<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

/**
 * One named step of AiRunPipeline.
 *
 * The pipeline exists because nine responsibilities were being assigned in prose
 * to a class nobody had named, and two entry paths need all nine. Leaving it
 * unnamed produces either a duplicated implementation, in which case the
 * redactor-only-path invariant holds on one path and not the other, or an
 * unnamed shared class that is a manager with the label removed.
 *
 * So it is an ordered list of named stages fixed at construction, each with
 * its own test. It is not injectable by a package, and what a package does
 * instead is register into one of the eleven seams the stages ask.
 * A stage never returns a value: it mutates the run in flight, and it halts the
 * pipeline by refusing or deferring on it. That keeps the ordering readable in
 * one place and stops a stage from inventing its own control flow.
 */
interface AiRunStageInterface
{
    /**
     * The stage's name, which is the name the design publishes and the name the
     * job log uses. It is a method rather than a class-name derivation so a
     * decorating stage can keep the name of what it decorates.
     */
    public function name(): string;

    public function handle(AiPipelineRun $run): void;

    /**
     * Whether this stage runs even after the run has been halted.
     *
     * Two shipped stages answer true, and each is the ledger.
     *
     * Settle, because a refused run, a deferred run and a cancelled run all
     * still have to reach the ledger: a run row is written for every run
     * including the refused and the previewed ones, which is what makes the
     * learner's right-to-know view and the refusal counts buildable at all.
     *
     * RecordAttempt, because a task that refused and a task that threw both
     * made an attempt, and those are exactly the rows an outage post-mortem
     * looks for.
     *
     * Everything else is skipped once a stage has halted the run, which is what
     * makes "a policy denial spends nothing" true rather than hoped for.
     */
    public function runsAfterHalt(): bool;
}
