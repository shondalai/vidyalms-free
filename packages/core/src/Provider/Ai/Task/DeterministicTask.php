<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiContentType;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRiskTier;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresContentType;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresRiskTier;
use Shondalai\VidyaLMS\Core\Registry\Ai\TaskMetadataInterface;

/**
 * What the eleven free tasks have in common, which is everything except the
 * work.
 *
 * They are ordinary AiTaskInterface providers and not a seventh registry. A
 * check is a function from a draft to a list of findings, which is exactly a
 * task with a findings output and no extension point, so it registers in the
 * same place, appears in the same picker, is gated by the same policies and
 * produces provenance through the same path as anything a paid package
 * ships. Routing them anywhere else would have given the product two ways to
 * run the same shape of thing.
 *
 * Every one of them computes its answer from the payload in front of it. None
 * opens a socket, holds a credential or names a vendor, which is what makes
 * the free build's exercise of the seams a real one rather than a
 * description.
 */
abstract class DeterministicTask implements
    AiTaskInterface,
    DeclaresContentType,
    DeclaresRiskTier,
    TaskMetadataInterface
{
    public const GROUP_CHECKS = 'checks';
    public const GROUP_STRUCTURE = 'structure';
    public const GROUP_ASSESSMENT = 'assessment';
    public const GROUP_REVIEW = 'review';

    public function label(): string
    {
        return 'ai.task.' . str_replace('.', '_', $this->key()) . '.label';
    }

    /**
     * Plain text unless a task says otherwise, which is the same answer the
     * absent capability publishes. Every one of the eleven means it: none of
     * them emits markup.
     */
    public function contentType(): AiContentType
    {
        return AiContentType::PlainText;
    }

    /**
     * Low, and only because these are deterministic. A check that computes
     * the same answer from the same input every time carries a light review
     * panel honestly; a model-backed task declaring Low would not.
     */
    public function riskTier(): AiRiskTier
    {
        return AiRiskTier::Low;
    }

    public function group(): string
    {
        return self::GROUP_CHECKS;
    }

    public function order(): int
    {
        return 100;
    }
}
