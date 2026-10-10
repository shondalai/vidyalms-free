<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ReportingMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoredReportingInterface;

/**
 * A type that declares whatever xAPI interaction token a test hands it, and
 * optionally a second token for an unscored placement.
 *
 * Ten tokens exist and the free build's six types use four of them, so the
 * emitter's lookup would otherwise be provable only on a site with the paid
 * package installed. A seam only a paid package exercises is a seam nobody
 * tests, which is the reason every registry lives in the free build.
 */
final class TokenDeclaringType implements
    QuestionTypeInterface,
    ReportingMetadataInterface,
    ScoredReportingInterface
{
    use DefaultDefinitionVersion;

    public function __construct(
        private readonly string $key,
        private readonly string $token,
        private readonly ?string $unscoredToken = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->key;
    }

    public function group(): string
    {
        return ReportingMetadataInterface::GROUP_SELECTION;
    }

    public function order(): int
    {
        return 900;
    }

    public function xapiInteractionType(): string
    {
        return $this->token;
    }

    public function xapiInteractionTypeWhen(bool $scored): string
    {
        return $scored ? $this->token : ($this->unscoredToken ?? $this->token);
    }

    public function qtiElement(): ?string
    {
        return null;
    }

    public function validateDefinition(array $definition): void
    {
    }

    public function presentForLearner(array $definition): array
    {
        return ['prompt' => $definition['prompt'] ?? ''];
    }

    public function grade(array $definition, mixed $answer): GradeResult
    {
        return $answer === null ? GradeResult::incorrect() : GradeResult::correct();
    }
}
