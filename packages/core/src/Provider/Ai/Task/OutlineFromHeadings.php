<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskInput;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskOutput;
use Shondalai\VidyaLMS\Core\Registry\Ai\TargetsExtensionPoint;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * A course outline read off the headings somebody already wrote.
 *
 * It is the free half of the structure family, and it is genuinely useful
 * rather than a placeholder: a syllabus pasted out of a document already has
 * its shape in its heading levels, and turning that into modules and lessons
 * needs no model at all. What a model adds on the paid path is the part this
 * cannot do, which is inventing the outcomes and the evidence for them.
 *
 * It produces **drafts and never rows**, like everything else in the
 * catalogue. Nothing is published, nobody is enrolled, and no availability
 * rule is created.
 */
final class OutlineFromHeadings extends DeterministicTask implements
    DeclaresTaskInput,
    DeclaresTaskOutput,
    TargetsExtensionPoint
{
    public const KEY = 'outline.from_headings';

    public const DRAFT_KIND = 'course_outline';

    /** The option the source text arrives under. */
    public const OPTION_SOURCE = 'source';

    public function key(): string
    {
        return self::KEY;
    }

    public function group(): string
    {
        return self::GROUP_STRUCTURE;
    }

    public function order(): int
    {
        return 10;
    }

    public function placements(): array
    {
        return ['workbench_curriculum'];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [self::OPTION_SOURCE],
            'properties' => [
                self::OPTION_SOURCE => ['type' => 'string', 'minLength' => 1],
                'instruction' => ['type' => 'string'],
            ],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1],
                'summary' => ['type' => 'string'],
                'lessons' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title'],
                        'properties' => ['title' => ['type' => 'string', 'minLength' => 1]],
                    ],
                ],
            ],
        ];
    }

    public function draftKind(): string
    {
        return self::DRAFT_KIND;
    }

    public function targetExtensionPoint(): string
    {
        return 'curriculum';
    }

    public function targetProviderKey(): string
    {
        return 'module';
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $source = TaskInputs::text($request, self::OPTION_SOURCE);
        $drafts = [];
        $ordinal = 0;
        $current = null;
        $lessons = [];

        foreach (preg_split('/\R/u', $source) ?: [] as $line) {
            $heading = $this->heading($line);

            if ($heading === null) {
                continue;
            }

            [$level, $title] = $heading;

            if ($level <= 1) {
                if ($current !== null) {
                    $drafts[] = $this->module($current, $lessons, $ordinal++);
                }

                $current = $title;
                $lessons = [];

                continue;
            }

            if ($current === null) {
                // A lesson heading before any module heading belongs to a
                // module the source did not name, and inventing one silently
                // would put the reviewer's own structure somewhere they did
                // not put it.
                $current = $title;

                continue;
            }

            $lessons[] = $title;
        }

        if ($current !== null) {
            $drafts[] = $this->module($current, $lessons, $ordinal);
        }

        $warnings = $drafts === [] ? ['ai.warning.no_headings_found'] : [];

        return AiTaskResult::create($drafts, [], $warnings);
    }

    /**
     * @param list<string> $lessons
     */
    private function module(string $title, array $lessons, int $ordinal): AiDraftCandidate
    {
        return AiDraftCandidate::create(
            self::DRAFT_KIND,
            $this->targetExtensionPoint(),
            [
                'title' => $title,
                'lessons' => array_map(
                    static fn (string $lesson): array => ['title' => $lesson],
                    $lessons,
                ),
            ],
            $this->targetProviderKey(),
            [],
            $ordinal,
        );
    }

    /**
     * A heading, as either markdown hashes or an HTML heading tag, with its
     * level and its text.
     *
     * @return array{int, string}|null
     */
    private function heading(string $line): ?array
    {
        $trimmed = trim($line);

        if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $matches) === 1) {
            return [\strlen($matches[1]), trim($matches[2])];
        }

        if (preg_match('/^<h([1-6])\b[^>]*>(.*?)<\/h[1-6]>$/iu', $trimmed, $matches) === 1) {
            // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            $title = trim(strip_tags($matches[2]));

            return $title === '' ? null : [(int) $matches[1], $title];
        }

        return null;
    }
}
