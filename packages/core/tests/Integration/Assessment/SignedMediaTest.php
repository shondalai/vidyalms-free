<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\SignedMedia;
use Shondalai\VidyaLMS\Core\Tests\Support\MediaBearingType;

/**
 * No storage ref ever appears in a learner payload.
 *
 * The standing core invariant is that private files go through the Storage
 * port and the only URL the core ever emits for one is a signed, expiring
 * URL. The assessment path broke it in the free build: the stimulus media
 * column is declared as holding media refs and the raw document was emitted
 * straight to the learner. For Pro it was a hard blocker, because every
 * media-bearing type in the catalogue specifies a signed expiring media URL
 * in its projection and there was no interface, no context member and no
 * presenter hook through which a provider could obtain one.
 */
final class SignedMediaTest extends AssessmentIntegrationTestCase
{
    private const STIMULUS_REF = 'stimuli/12/passage-diagram.png';

    private int $quizId;

    protected function setUp(): void
    {
        parent::setUp();

        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;
    }

    public function testAMediaBearingTypeGetsASignedUrlAndNeverEmitsItsRef(): void
    {
        $this->questionTypes->register(new MediaBearingType());
        $this->env->storage->putPrivate(MediaBearingType::REF, 'not really a png');

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, MediaBearingType::KEY, [
            'prompt' => 'Click the left ventricle.',
            'media' => [SignedMedia::REF_KEY => MediaBearingType::REF],
        ], 1.0);
        self::assertNotNull($question->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);
        $definition = $view['questions'][0]['definition'];

        self::assertIsString($definition['media'][SignedMedia::URL_KEY] ?? null);
        self::assertStringContainsString('signed', (string) $definition['media'][SignedMedia::URL_KEY]);
        self::assertNoRefIn($view);
    }

    public function testStimulusMediaIsSignedRatherThanEmittedRaw(): void
    {
        $this->env->storage->putPrivate(self::STIMULUS_REF, 'not really a png');
        $stimulusId = $this->createStimulus();

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'single_choice', [
            'prompt' => 'Pick.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], 1.0);
        self::assertNotNull($question->id);
        $this->env->database->update('questions', ['stimulus_id' => $stimulusId], ['id' => $question->id]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);

        self::assertCount(1, $view['stimuli']);
        $media = $view['stimuli'][0]['media'];
        self::assertIsArray($media);
        self::assertIsString($media[SignedMedia::URL_KEY] ?? null);
        self::assertStringContainsString('signed', (string) $media[SignedMedia::URL_KEY]);
        self::assertNoRefIn($view);
    }

    /**
     * @param array<string, mixed> $view
     */
    private static function assertNoRefIn(array $view): void
    {
        $encoded = json_encode($view, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(
            SignedMedia::REF_KEY,
            $encoded,
            'a storage ref must never reach a learner payload',
        );
        self::assertStringNotContainsString(MediaBearingType::REF, $encoded);
        self::assertStringNotContainsString(self::STIMULUS_REF, $encoded);
    }

    private function createStimulus(): int
    {
        $now = $this->now();

        return $this->env->database->insert('stimuli', [
            'course_id' => $this->createCourse('stimulus-course'),
            'title' => 'The water cycle',
            'body' => 'A short passage.',
            'media' => json_encode([SignedMedia::REF_KEY => self::STIMULUS_REF], JSON_THROW_ON_ERROR),
            'alt_text' => 'A diagram of the water cycle',
            'long_description' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
