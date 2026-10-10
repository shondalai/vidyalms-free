<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresDraftRetention;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis;

/**
 * A task whose draft holds a learner's own words, so it asks for a week rather
 * than the site's ninety days.
 *
 * It is the case the shipped constant could not express: a glossary suggestion
 * and a feedback draft derived from a named learner's submission are both
 * drafts and are not the same personal data.
 */
final class ShortRetentionTask implements AiTaskInterface, DeclaresDraftRetention
{
    public function key(): string
    {
        return 'test.short_retention';
    }

    public function label(): string
    {
        return 'ai.task.test_short_retention.label';
    }

    public function draftRetentionDays(string $draftKind): ?int
    {
        // A week for the kind whose payload holds a learner's own words, and
        // the site's own default for anything else, which is what null means.
        return $draftKind === 'question' ? 7 : null;
    }

    public function draftRetentionBasis(string $draftKind): string
    {
        return RetentionBasis::CONTRACT;
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        return AiTaskResult::create([
            AiDraftCandidate::create(
                'question',
                'question_type',
                ['prompt' => 'Describe an oolong.'],
                'short_answer',
            ),
        ]);
    }
}
