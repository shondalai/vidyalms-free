<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEvent;
use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEventRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * The integrity signals recorded against one attempt, oldest first, which is
 * the order a human reads them in.
 *
 * The screen that renders this says what these rows are: advisory, never
 * acted on, and as likely to be a magnifier as a second window. The use case
 * enforces the per-course rule and nothing else, because there is nothing
 * else to enforce: no signal has ever changed a mark.
 */
final class ListIntegrityEvents
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly QuizRepository $quizzes,
        private readonly IntegrityEventRepository $events,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @return array{items: list<IntegrityEvent>, total: int, page: int, per_page: int}
     */
    public function execute(Principal $principal, int $attemptId, int $page = 1, int $perPage = 25): array
    {
        $this->access->requireSignedIn($principal);

        $attempt = $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);

        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);

        $this->access->require($principal, $quiz->courseId, Capability::GRADE, Capability::VIEW_REPORTS);

        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        return [
            'items' => $this->events->forAttempt($attemptId, $perPage, ($page - 1) * $perPage),
            'total' => $this->events->countForAttempt($attemptId),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
