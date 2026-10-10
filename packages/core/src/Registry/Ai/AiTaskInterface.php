<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Tasks, not chat: generate a quiz from a lesson, outline a course, turn a
 * transcript into a reading lesson, draft feedback for the instructor to
 * edit.
 *
 * The free build ships deterministic tasks that call no model, so the seam is
 * exercised by free tests rather than only by the paid build; the model-backed
 * catalogue is Pro and is metered. The promise that a task never runs without
 * an explicit user action is enforced by the runner's policy gate and is not a
 * comment here, because a comment enforces nothing.
 *
 * The signature took a bare input array and a Principal and returned a bare
 * array: no context object, no declared input, no declared output, no cost
 * record and no way for a caller to know which model answered. It is
 * corrected once, here, while a repository-wide search finds no implementation
 * anywhere in core, pro-core, either adapter or either app. The moment a task
 * ships, the rule QuestionTypeInterface carries applies verbatim: these are
 * shipped bytes third parties are invited to implement, and nothing may ever
 * be added to them.
 *
 * Optional behaviour therefore arrives as a narrow capability interface probed
 * with instanceof, never as a new method here, and every capability states
 * what its absence means.
 */
interface AiTaskInterface extends ProviderInterface
{
    public function label(): string;

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult;
}
