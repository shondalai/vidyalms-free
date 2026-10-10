<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Ai\AiGroundingIndexer;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * Nothing an importer wrote is ever tagged trusted_instruction.
 *
 * The trust level is a REAL column rather than an inference from the subject
 * type precisely so this assertion has something to read. The hostile-input
 * inventory this design starts from begins with "course content authored by
 * anyone with instructor rights" and continues into everything an importer
 * wrote, and the importers are the invited path an attacker already has into a
 * prompt.
 *
 * trusted_instruction is reserved for the task's own prompt, which the core
 * never renders and never stores: it is composed as a PromptSegment at call time
 * by the bridge's own mapper. There is deliberately no path from authored
 * content to that level, and both halves of that claim are tested here: the
 * behavioural one over rows the indexer actually wrote, and the structural one
 * over the only class that writes them.
 */
final class AiGroundingTrustTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-06-01 09:00:00');
    }

    public function testEveryChunkTheIndexerWritesIsUntrusted(): void
    {
        $indexer = new AiGroundingIndexer($this->fixture->env->database, $this->fixture->env->clock);
        $result = $indexer->reindexCourse($this->fixture->courseId);

        self::assertGreaterThan(0, $result['lessons']);
        self::assertGreaterThan(0, $result['chunks'], 'The fixture course has authored text to index.');

        $trusted = $this->fixture->env->database->count(
            Query::from('ai_grounding_chunks')->where('trust', '=', 'trusted_instruction'),
        );

        self::assertSame(0, $trusted, 'Authored content reached the instruction channel.');
        self::assertSame(
            $result['chunks'],
            $this->fixture->env->database->count(
                Query::from('ai_grounding_chunks')->where('trust', '=', AiGroundingIndexer::TRUST_UNTRUSTED),
            ),
        );
    }

    /**
     * A citation stored in March still resolves in June, and re-indexing
     * unchanged text produces the identical key.
     */
    public function testReindexingUnchangedTextProducesTheIdenticalChunkKeys(): void
    {
        $indexer = new AiGroundingIndexer($this->fixture->env->database, $this->fixture->env->clock);

        $indexer->reindexCourse($this->fixture->courseId);
        $first = $this->keys();

        $indexer->reindexCourse($this->fixture->courseId);
        $second = $this->keys();

        self::assertNotSame([], $first);
        self::assertSame($first, $second, 'A re-index moved every citation the review screen had stored.');
    }

    /**
     * The structural half: the one class that writes the column has no branch
     * that could write another value.
     */
    public function testTheOnlyWriterOfTheTrustColumnCannotWriteTrustedInstruction(): void
    {
        $source = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Application/Ai/AiGroundingIndexer.php',
        );

        // The constant is the only value assigned to the column, and the string
        // "trusted_instruction" appears in the file only inside prose.
        self::assertStringContainsString("'trust' => self::TRUST_UNTRUSTED,", $source);
        self::assertSame(
            1,
            substr_count($source, "'trust' => "),
            'A second assignment to the trust column is a second answer to this question.',
        );
    }

    /**
     * A chunk is a chunk of characters, and it was a chunk of bytes.
     *
     * `str_split` counts bytes, so a Devanagari, Tamil, Han or Arabic body was
     * cut mid-codepoint: five of six pieces of a 2,400-character Devanagari
     * body were not valid UTF-8 at all. Each one went into the text column, was
     * hashed into `content_hash` and therefore into the chunk key, and was
     * rendered to a reviewer as a cited span.
     *
     * @dataProvider nonLatinBodies
     */
    public function testEveryChunkIsValidUtf8WhateverScriptTheSourceIsIn(string $script, string $body): void
    {
        $long = str_repeat($body, (int) ceil(3000 / max(1, mb_strlen($body, 'UTF-8'))));

        $this->fixture->env->database->update(
            'lesson_content',
            ['content' => (string) json_encode(['body' => $long], JSON_UNESCAPED_UNICODE)],
            ['lesson_id' => $this->firstLessonId()],
        );

        $indexer = new AiGroundingIndexer($this->fixture->env->database, $this->fixture->env->clock);
        $indexer->reindexCourse($this->fixture->courseId);

        $rows = $this->fixture->env->database->selectAll(
            Query::from('ai_grounding_chunks')->select(['text'])->orderBy('id', Query::SORT_ASC),
        );

        self::assertNotSame([], $rows);

        foreach ($rows as $index => $row) {
            self::assertTrue(
                mb_check_encoding((string) $row['text'], 'UTF-8'),
                $script . ' chunk ' . $index . ' is not valid UTF-8',
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nonLatinBodies(): array
    {
        return [
            'Devanagari' => ['Devanagari', 'शिक्षा एक निरंतर प्रक्रिया है। '],
            'Tamil' => ['Tamil', 'கல்வி என்பது ஒரு தொடர் செயல்முறை. '],
            'Han' => ['Han', '教育是一个持续的过程。'],
            'Arabic' => ['Arabic', 'التعليم عملية مستمرة. '],
        ];
    }

    private function firstLessonId(): int
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('lessons')->select(['id'])->orderBy('id', Query::SORT_ASC),
        );

        self::assertNotNull($row);

        return (int) $row['id'];
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $keys = array_map(
            static fn (array $row): string => (string) $row['chunk_key'],
            $this->fixture->env->database->selectAll(
                Query::from('ai_grounding_chunks')->select(['chunk_key'])->orderBy('id', Query::SORT_ASC),
            ),
        );

        sort($keys);

        return $keys;
    }
}
