<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;

/**
 * Reading a request's authored options without eleven copies of the same
 * defensive cast.
 *
 * Every one of these answers a default rather than throwing, because a task
 * handed an option of the wrong shape has been handed a caller bug and the
 * honest response is a finding rather than an exception out of a provider.
 */
final class TaskInputs
{
    private function __construct()
    {
    }

    /**
     * The draft payloads a check runs over.
     *
     * A check accepts drafts inline rather than by id, because a check is a
     * pure function of what it is shown: handing it ids would give it a
     * reason to hold a database, which is the one thing the task context
     * refuses to carry.
     *
     * @return list<array<string, mixed>>
     */
    public static function drafts(AiTaskRequest $request): array
    {
        $drafts = $request->option('drafts');
        $result = [];

        if (!\is_array($drafts)) {
            return [];
        }

        foreach ($drafts as $draft) {
            if (\is_array($draft)) {
                /** @var array<string, mixed> $draft */
                $result[] = $draft;
            }
        }

        return $result;
    }

    public static function text(AiTaskRequest $request, string $option, string $default = ''): string
    {
        $value = $request->option($option);

        return \is_string($value) ? $value : $default;
    }

    public static function number(AiTaskRequest $request, string $option, float $default): float
    {
        $value = $request->option($option);

        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        return \is_numeric($value) ? (float) $value : $default;
    }

    public static function whole(AiTaskRequest $request, string $option, int $default): int
    {
        $value = $request->option($option);

        return \is_int($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public static function strings(AiTaskRequest $request, string $option): array
    {
        $value = $request->option($option);
        $result = [];

        if (!\is_array($value)) {
            return [];
        }

        foreach ($value as $item) {
            if (\is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * The comparison form for exact and normalised matching: case folded,
     * punctuation dropped, whitespace collapsed.
     *
     * It is the free build's whole duplicate story, and it says so on the
     * finding rather than pretending to be semantic.
     */
    public static function normalise(string $text): string
    {
        $lowered = mb_strtolower(trim($text));
        $stripped = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $lowered) ?? $lowered;
        $collapsed = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;

        return trim($collapsed);
    }

    /** The stable digest a normalised-exact comparison is made over. */
    public static function digest(string $text): string
    {
        return hash('sha256', self::normalise($text));
    }

    /**
     * Every string leaf in a nested payload, which is what a text-level check
     * actually reads. A check that looked only at the top level would miss
     * the option labels, which is where most of the flaws are.
     *
     * @param mixed $value
     *
     * @return list<string>
     */
    public static function leaves(mixed $value): array
    {
        if (\is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $leaves = [];

        foreach ($value as $item) {
            foreach (self::leaves($item) as $leaf) {
                $leaves[] = $leaf;
            }
        }

        return $leaves;
    }
}
