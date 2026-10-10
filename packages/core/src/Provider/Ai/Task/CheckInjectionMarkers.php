<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiGroundingMode;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresGrounding;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * Markers of an attempt to talk to the model rather than to the reader.
 *
 * **It runs in both directions, and that was the correction.** It accepted
 * draft ids only, and a draft exists only after a model has answered, so the
 * one detector aimed at prompt injection read only what came back and never
 * the lesson body, the imported item, the transcript or the submission about
 * to be sent. It now accepts either drafts or the resolved chunk text, and
 * runs over the grounding set before a call and over the draft after it.
 *
 * **It stays advisory in both directions**, and the reason is stated rather
 * than implied: a gate over course content would let an attacker deny an
 * instructor their own tooling by planting a marker in a page. So it warns,
 * every time, and the containment that actually holds is structural: the
 * trust marking, the delimiters and the fact that untrusted content can never
 * be promoted.
 */
final class CheckInjectionMarkers extends DeterministicTask implements ProducesFindingsOnly, RequiresGrounding
{
    public const KEY = 'check.injection_markers';

    /** The option resolved chunk text arrives under when this runs before a call. */
    public const OPTION_CHUNKS = 'chunks';

    /**
     * Phrases whose only purpose in a body of course material is to address a
     * model. None of them is proof and all of them are worth a person seeing.
     */
    private const MARKERS = [
        'ignore the previous',
        'ignore previous',
        'ignore all previous',
        'disregard the above',
        'disregard previous',
        'system prompt',
        'you are now',
        'act as',
        'new instructions',
        'override the instructions',
        'reveal the answer',
        'print the answer',
        'do not follow',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 80;
    }

    public function placements(): array
    {
        return ['workbench_lesson_editor', 'workbench_question_bank', 'import_review'];
    }

    public function groundingMode(): AiGroundingMode
    {
        return AiGroundingMode::Optional;
    }

    public function citationUnit(): string
    {
        return 'chunk';
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $findings = [];

        foreach (TaskInputs::strings($request, self::OPTION_CHUNKS) as $position => $chunk) {
            foreach ($this->markersIn($chunk) as $marker) {
                $findings[] = PublicationFinding::warning(
                    'ai.check.injection_markers.in_source',
                    'ai.finding.source_addresses_the_model',
                    ['marker' => $marker, 'position' => $position + 1],
                );
            }
        }

        foreach (TaskInputs::drafts($request) as $index => $draft) {
            $questionId = CheckSchemaValidity::syntheticId($draft, $index);
            $text = implode(' ', TaskInputs::leaves($draft['payload'] ?? $draft['definition'] ?? null));

            foreach ($this->markersIn($text) as $marker) {
                $findings[] = PublicationFinding::warning(
                    'ai.check.injection_markers.in_draft',
                    'ai.finding.draft_addresses_the_model',
                    ['marker' => $marker],
                    $questionId,
                );
            }
        }

        return AiTaskResult::create([], $findings);
    }

    /**
     * @return list<string>
     */
    private function markersIn(string $text): array
    {
        $haystack = TaskInputs::normalise($text);
        $found = [];

        foreach (self::MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                $found[] = $marker;
            }
        }

        return $found;
    }
}
