<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunReservation;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * A draft is invisible to learners BY CONSTRUCTION, and this is the test of it.
 *
 * A generated definition contains correct answers by construction, and the
 * product's strongest invariant is that quiz correctness never leaves the server
 * before an attempt is submitted. So a draft is not written into the questions
 * table at all: it lives in ai_drafts, reachable only under TEACH on the owning
 * course or MANAGE at site scope. The departure from a flag on a live row is
 * named in the design: a flag is one forgotten condition away from a leak.
 *
 * **The draft projection is deliberately answer-showing, and the test over it is
 * a different test.** The reviewer has to see the key, the distractors and the
 * rationale, so a projection passing the answer-hiding assertion would be a
 * review screen with the answers removed. The existing structural answer-hiding
 * walk stays exactly as it is, over the LIVE projection. The property wanted
 * here is that no learner-reachable route can ever project a draft, so this test
 * is route level and is enumerated from the route table.
 */
final class AiDraftReachabilityTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-06-01 09:00:00');
    }

    /**
     * Every served route whose path names a draft, called as a learner.
     *
     * Enumerated from the route table rather than from a hand-written list, so a
     * route added later is covered by this test on the day it is registered.
     */
    public function testNoRouteNamingADraftAnswersALearner(): void
    {
        $draftId = $this->seedDraft();
        $checked = 0;

        foreach ($this->fixture->kernel->apiKernel()->servedTemplates() as $pattern) {
            if (!str_contains($pattern, '/ai/drafts')) {
                continue;
            }

            ++$checked;
            $path = str_replace('{id}', (string) $draftId, $pattern);

            foreach (['GET', 'PATCH', 'POST'] as $method) {
                $response = $this->fixture->call($method, $path, $this->fixture->learner());

                self::assertNotSame(
                    200,
                    $response->status,
                    $method . ' ' . $pattern . ' answered a learner, and a draft holds correct answers.',
                );
                self::assertContains(
                    $response->status,
                    [400, 401, 403, 404, 405, 409, 422],
                    $method . ' ' . $pattern . ' must refuse rather than serve.',
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'The route table must actually carry draft routes to check.');
    }

    /**
     * The same routes refuse a teacher of a different course, so the gate is TEACH
     * on the OWNING course and not merely on any course.
     */
    public function testNoRouteNamingADraftAnswersATeacherOfAnotherCourse(): void
    {
        $draftId = $this->seedDraft();

        foreach ($this->fixture->kernel->apiKernel()->servedTemplates() as $pattern) {
            if (!str_contains($pattern, '/ai/drafts/')) {
                continue;
            }

            $response = $this->fixture->call(
                'GET',
                str_replace('{id}', (string) $draftId, $pattern),
                $this->fixture->outsider(),
            );

            self::assertNotSame(200, $response->status, $pattern . ' answered a teacher of another course.');
        }
    }

    /**
     * The learner's right-to-know view is the one AI surface a learner reaches,
     * and it answers what was SENT rather than what was drafted.
     */
    public function testTheLearnerActivityViewCarriesNoDraftPayload(): void
    {
        $this->seedDraft();

        $response = $this->fixture->call('GET', '/me/ai-activity', $this->fixture->learner());

        self::assertSame(200, $response->status);

        $encoded = json_encode($response->data ?? [], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(
            'Camellia sinensis',
            $encoded,
            'A draft payload reached a learner-facing surface.',
        );
        self::assertStringNotContainsString('payload', $encoded);
    }

    /**
     * Every class that names the drafts table reaches the AI access gate.
     *
     * The structural half of the same property: a new reader added to the
     * application layer cannot quietly project drafts without going through the
     * one object that answers "TEACH on the owning course, or MANAGE at site
     * scope".
     */
    public function testEveryApplicationClassNamingTheDraftsTableReachesTheAccessGate(): void
    {
        $root = \dirname(__DIR__, 3) . '/src/Application/Ai';
        $checked = 0;

        foreach ((array) scandir($root) as $entry) {
            if (!\is_string($entry) || !str_ends_with($entry, '.php')) {
                continue;
            }

            $source = (string) file_get_contents($root . '/' . $entry);

            if (!str_contains($source, 'DRAFTS') && !str_contains($source, 'ai_drafts')) {
                continue;
            }

            ++$checked;
            self::assertStringContainsString(
                'AiAccess',
                $source,
                $entry . ' reads the drafts table and must resolve the AI access gate.',
            );
        }

        self::assertGreaterThan(0, $checked);
    }

    private function seedDraft(): int
    {
        $now = new \DateTimeImmutable('2026-06-01 09:00:00');

        $runId = $this->fixture->kernel->aiRunStore->reserve(
            AiRunReservation::create(
                'run-reachability',
                'question.template_aig',
                hash('sha256', 'inputs'),
                $now->modify('+400 days'),
                1,
                null,
                null,
                null,
                'null',
                null,
                1,
                $this->fixture->teacher()->userId,
                AiRunReservation::ACTOR_PERSON,
                null,
                $this->fixture->learner()->userId,
                'quiz',
                $this->fixture->quizId,
                $this->fixture->courseId,
            ),
            [\Shondalai\VidyaLMS\Core\Domain\Ai\AiRunSubject::subject($this->fixture->learner()->userId)],
        );

        return $this->fixture->kernel->aiDraftStore->record(
            $runId,
            AiDraftCandidate::create(
                'question',
                'question_type',
                [
                    'prompt' => 'Which leaf makes tea?',
                    'options' => [
                        ['key' => 'a', 'label' => 'Camellia sinensis'],
                        ['key' => 'b', 'label' => 'Rosa canina'],
                    ],
                    'correct' => ['a'],
                ],
                'multiple_choice',
            ),
            $now->modify('+90 days'),
            $this->fixture->courseId,
        );
    }
}
