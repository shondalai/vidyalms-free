<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Turns a course's authored text into stable, citable chunks.
 *
 * **The chunker is core and the trust marking is its whole safety contribution.**
 * Every chunk it writes is `untrusted`, without exception and without a branch
 * that could take another value, because the hostile-input inventory this design
 * starts from begins with "course content authored by anyone with instructor
 * rights" and continues into everything an importer wrote. A CI test asserts that
 * nothing an importer wrote is ever tagged `trusted_instruction`, and the
 * assertion needs a column to read; this is the writer that column has, so the
 * assertion is about code that exists rather than about a convention.
 *
 * `trusted_instruction` is reserved for the task's own prompt, which the core
 * never renders and never stores: it is composed as a PromptSegment at call time
 * by the bridge's own mapper. There is deliberately no path from authored content
 * to that level.
 *
 * **chunk_key is a hash and not an autoincrement**, because citation stability
 * across a re-index is the whole point: a citation stored on a draft in March
 * still resolves in June. The key is
 * sha256(subject_type|subject_id|ordinal|content_hash), so re-indexing unchanged
 * text produces the identical key and re-indexing changed text produces a new one,
 * which is exactly the distinction a reviewer following a citation needs.
 *
 * Fixed-size chunks rather than semantic ones, deliberately: the sizes are what a
 * buffered call fits, and the map-reduce that follows is the injection
 * containment pattern, because a poisoned source then affects only its own output.
 *
 * **What "stable" actually means here, stated rather than implied.** The key is
 * stable across a re-index of unchanged text and across an append, and it is
 * stable across nothing else. The ordinal is a position in a fixed-size split,
 * so changing `CHUNK_CHARS`, or inserting a single character at the front of a
 * body, moves every boundary after it and re-keys every chunk: measured over
 * this class's own splitter, an unchanged re-index keeps five keys of five, an
 * append keeps four of five, a leading insertion keeps none, and a change of
 * chunk size keeps none. A citation stored in March therefore resolves in June
 * only if the source and the chunk size have both stayed where they were.
 *
 * Three things the design asks for are not built here and are named rather than
 * left as a silence: the chunk size is a constant and not a site setting, there
 * is no overlap between adjacent chunks, and only the `detail` level is ever
 * written, so the hierarchical summary layer above it does not exist. Each is
 * additive and none can arrive without re-keying the index it changes, which is
 * why none of them is a quiet change.
 */
final class AiGroundingIndexer
{
    private const CHUNKS = 'ai_grounding_chunks';
    private const LESSONS = 'lessons';
    private const LESSON_CONTENT = 'lesson_content';

    /** The only trust level authored content is ever written at. */
    public const TRUST_UNTRUSTED = 'untrusted';

    /** How many characters one detail chunk holds. */
    public const CHUNK_CHARS = 1200;

    /** The subject type a lesson chunk carries. */
    public const SUBJECT_LESSON = 'lesson';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Re-indexes one course and answers how many chunks it now holds.
     *
     * A re-index is a replace rather than an append: the previous chunks for each
     * lesson are removed first, so a lesson whose text shrank does not keep the
     * tail of its old self as a citable source. The keys of unchanged text are
     * unchanged, so a citation into that text still resolves.
     *
     * @return array{lessons: int, chunks: int}
     */
    public function reindexCourse(int $courseId): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $lessons = $this->database->selectAll(
            Query::from(self::LESSONS)
                ->select(['id', 'title', 'summary'])
                ->where('course_id', '=', $courseId)
                ->orderBy('id', Query::SORT_ASC),
        );

        $chunks = 0;

        foreach ($lessons as $lesson) {
            $lessonId = (int) $lesson['id'];
            $this->database->delete(self::CHUNKS, [
                'subject_type' => self::SUBJECT_LESSON,
                'subject_id' => $lessonId,
            ]);

            $text = $this->textOf($lesson, $lessonId);
            $ordinal = 0;

            foreach ($this->split($text) as $piece) {
                $contentHash = hash('sha256', $piece);

                $this->database->insert(self::CHUNKS, [
                    'subject_type' => self::SUBJECT_LESSON,
                    'subject_id' => $lessonId,
                    // Denormalised so a content_scope policy can bound retrieval
                    // to one course without joining through six subject types.
                    'course_id' => $courseId,
                    'ordinal' => $ordinal,
                    'content_hash' => $contentHash,
                    'chunk_key' => self::chunkKey(self::SUBJECT_LESSON, $lessonId, $ordinal, $contentHash),
                    'level' => 'detail',
                    'token_count' => (int) ceil(\strlen($piece) / 4),
                    'text' => $piece,
                    // Never anything else. There is no branch here and there is
                    // no parameter that could make one.
                    'trust' => self::TRUST_UNTRUSTED,
                    'indexed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                ++$ordinal;
                ++$chunks;
            }
        }

        return ['lessons' => \count($lessons), 'chunks' => $chunks];
    }

    /**
     * The stable key a citation is stored against.
     *
     * Lengths are folded into the material so that ("a", "bc") and ("ab", "c")
     * cannot collide, which a naive concatenation would allow and which would
     * make one lesson's citation resolve into another's text.
     */
    public static function chunkKey(string $subjectType, int $subjectId, int $ordinal, string $contentHash): string
    {
        return hash('sha256', implode('|', [
            (string) \strlen($subjectType),
            $subjectType,
            (string) $subjectId,
            (string) $ordinal,
            $contentHash,
        ]));
    }

    /**
     * @param array<string, mixed> $lesson
     */
    private function textOf(array $lesson, int $lessonId): string
    {
        $parts = [
            (string) ($lesson['title'] ?? ''),
            (string) ($lesson['summary'] ?? ''),
        ];

        $content = $this->database->selectOne(
            Query::from(self::LESSON_CONTENT)->select(['content'])->where('lesson_id', '=', $lessonId),
        );

        if ($content !== null && \is_string($content['content'] ?? null)) {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $content['content'], true);
            $parts[] = \is_array($decoded) ? $this->flatten($decoded) : (string) $content['content'];
        }

        return trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * Lesson content is provider-shaped JSON, so the indexer takes every string
     * leaf and nothing else.
     *
     * It reads the shape rather than knowing it, because a lesson type a package
     * added has a shape the core has never seen, and an indexer that understood
     * only the shipped types would silently stop grounding on the first custom
     * lesson.
     *
     * @param array<mixed> $document
     */
    private function flatten(array $document): string
    {
        $out = [];

        array_walk_recursive($document, static function (mixed $value) use (&$out): void {
            if (\is_string($value) && trim($value) !== '') {
                $out[] = $value;
            }
        });

        return implode("\n", $out);
    }

    /**
     * The text as fixed-size chunks, counted in characters and never in bytes.
     *
     * **`str_split` cuts a multi-byte codepoint in half.** It counts bytes, so
     * a Devanagari, Tamil, Han or Arabic body was split mid-character: a
     * six-kilobyte body produced six pieces of which five were not valid UTF-8
     * at all. Each of those went into `ai_grounding_chunks.text`, was hashed
     * into `content_hash` and therefore into the chunk key, and was rendered to
     * a reviewer as a cited span. `mb_str_split` counts characters, which is
     * also what `CHUNK_CHARS` says it is counting.
     *
     * The boundary moves for non-Latin text as a result, so an existing index
     * of such content re-chunks on its next pass and its citations move with
     * it. That is the correct direction: a citation into invalid UTF-8 was not
     * a citation anybody could follow.
     *
     * @return list<string>
     */
    private function split(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $pieces = mb_str_split($text, self::CHUNK_CHARS, 'UTF-8');

        return array_values(array_filter($pieces, static fn (string $piece): bool => trim($piece) !== ''));
    }
}
