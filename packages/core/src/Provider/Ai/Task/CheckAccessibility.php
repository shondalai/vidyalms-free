<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * The accessibility failures a machine can see in a draft body.
 *
 * Four of them, all structural, all decidable from the markup: an image with
 * no alternative text, a link whose text says nothing, a heading level
 * skipped, and a data table with no header row. Everything else an
 * accessibility review covers is a judgement about whether the alternative
 * text is any good, and this check does not claim to make it.
 *
 * The parser is a small set of expressions rather than a document parser,
 * because the core takes no dependency under src and a parser that answered
 * "accessible" for markup it silently failed to read would be worse than one
 * that names what it looked at.
 */
final class CheckAccessibility extends DeterministicTask implements ProducesFindingsOnly
{
    public const KEY = 'check.accessibility';

    /** Link text that carries no destination information at all. */
    private const EMPTY_LINK_TEXT = ['click here', 'here', 'read more', 'more', 'link', 'this'];

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 70;
    }

    public function placements(): array
    {
        return ['workbench_lesson_editor'];
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $findings = [];

        foreach (TaskInputs::drafts($request) as $index => $draft) {
            $questionId = CheckSchemaValidity::syntheticId($draft, $index);
            $body = implode("\n", TaskInputs::leaves($draft['payload'] ?? $draft['definition'] ?? null));

            foreach ($this->inspect($body, $questionId) as $finding) {
                $findings[] = $finding;
            }
        }

        return AiTaskResult::create([], $findings);
    }

    /**
     * @return list<PublicationFinding>
     */
    private function inspect(string $body, int $questionId): array
    {
        $findings = [];

        if (preg_match_all('/<img\b[^>]*>/i', $body, $images) !== false) {
            foreach ($images[0] as $image) {
                if (preg_match('/\balt\s*=\s*"[^"]+"/i', $image) !== 1) {
                    $findings[] = PublicationFinding::blocks(
                        'ai.check.accessibility.image_without_alt',
                        'ai.finding.image_has_no_alternative_text',
                        [],
                        $questionId,
                    );
                }
            }
        }

        if (preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $body, $links) !== false) {
            foreach ($links[1] as $text) {
                // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
                $normalised = TaskInputs::normalise(strip_tags($text));

                if ($normalised === '' || \in_array($normalised, self::EMPTY_LINK_TEXT, true)) {
                    $findings[] = PublicationFinding::warning(
                        'ai.check.accessibility.uninformative_link',
                        'ai.finding.link_text_says_nothing',
                        ['text' => $normalised],
                        $questionId,
                    );
                }
            }
        }

        $previous = 0;

        if (preg_match_all('/<h([1-6])\b/i', $body, $headings) !== false) {
            foreach ($headings[1] as $level) {
                $level = (int) $level;

                if ($previous > 0 && $level > $previous + 1) {
                    $findings[] = PublicationFinding::warning(
                        'ai.check.accessibility.heading_level_skipped',
                        'ai.finding.heading_level_skipped',
                        ['from' => $previous, 'to' => $level],
                        $questionId,
                    );
                }

                $previous = $level;
            }
        }

        if (preg_match_all('/<table\b.*?<\/table>/is', $body, $tables) !== false) {
            foreach ($tables[0] as $table) {
                if (preg_match('/<th\b/i', $table) !== 1) {
                    $findings[] = PublicationFinding::warning(
                        'ai.check.accessibility.table_without_header',
                        'ai.finding.table_has_no_header_row',
                        [],
                        $questionId,
                    );
                }
            }
        }

        return $findings;
    }
}
