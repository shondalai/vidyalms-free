<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\Presenter\AiPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The review queue and the three decisions that are not acceptance.
 *
 * Acceptance is somewhere else on purpose: `AcceptAiDraft` is the only writer
 * of an artefact in the whole design and owns the transaction, the provenance
 * row and the human-principal check. Editing, rejecting and discarding write
 * only to the draft, so they live here with the queue they belong to.
 *
 * Four properties make the human step a control rather than a formality, and
 * three of them are enforced here. Rejection is the default state, so nothing
 * moves to accepted without an act. The reviewer is shown the findings with
 * every row rather than a clean surface, which is why the list carries them.
 * The named reviewer is recorded on the first edit rather than only at the
 * decision. The fourth, the accept-unedited rate, is measured in the usage
 * report from the edit distance this class is careful to preserve: an edit
 * lands in `edited_payload` and never overwrites `payload`, because
 * overwriting erases the measurement.
 *
 * No learner read path reaches any of this. Every method requires teach on the
 * owning course, or manage.
 */
final class AiDraftReview
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    /** The states from which an edit or a decision is still possible. */
    private const OPEN_STATES = ['requested', 'generated', 'checked', 'in_review'];

    public function __construct(
        private readonly SqlAiReadModel $reads,
        private readonly AiAccess $access,
        private readonly Registries $registries,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly ?ProviderPackages $packages = null,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function list(Principal $principal, array $filters, int $page, int $perPage): array
    {
        $courseId = \is_int($filters['course_id'] ?? null) ? $filters['course_id'] : null;
        $this->access->requireAuthoring($principal, $courseId);

        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);
        $result = $this->reads->draftPage($filters, $perPage, ($page - 1) * $perPage);

        $items = [];

        foreach ($result['items'] as $row) {
            $items[] = $this->present($row, $this->reads->findings((int) $row['id']), null);
        }

        return AiPresenter::page($items, $result['total'], $page, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $principal, int $id): array
    {
        $row = $this->require($principal, $id);
        $run = $this->reads->run((int) ($row['run_id'] ?? 0));

        return $this->present(
            $row,
            $this->reads->findings($id),
            $run === null ? null : AiPresenter::run($run, $this->reads->attempts((int) $run['id'])),
        );
    }

    /**
     * The reviewer's edit. It moves the draft to in_review and stamps the
     * named reviewer, because oversight that records no name is oversight
     * nobody can audit.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(Principal $principal, int $id, array $body): array
    {
        $row = $this->require($principal, $id);
        $this->requireOpen($row, 'edited');

        $edited = $body['edited_payload'] ?? null;

        if (!\is_array($edited)) {
            throw ValidationException::forField('edited_payload', 'must be the edited draft document');
        }

        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $reviewStartedAt = $row['review_started_at'] ?? null;

        $this->database->update(
            SqlAiReadModel::DRAFTS,
            [
                'edited_payload' => json_encode(StoredShape::wrap($edited, 1), \JSON_THROW_ON_ERROR),
                'edited_payload_version' => (int) ($row['payload_version'] ?? 1),
                'state' => 'in_review',
                'reviewer_user_id' => $principal->userId,
                'review_started_at' => \is_string($reviewStartedAt) && $reviewStartedAt !== '' ? $reviewStartedAt : $now,
                'updated_at' => $now,
            ],
            ['id' => $id],
        );

        $this->acknowledge($body['acknowledge_findings'] ?? null, $id, $principal);

        return $this->get($principal, $id);
    }

    /**
     * Send the draft back. It returns to `generated` and keeps the reason
     * code, which survives the payload purge because the corpus that tunes the
     * critic does not need the learner's words.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function reject(Principal $principal, int $id, array $body): array
    {
        $row = $this->require($principal, $id);
        $this->requireOpen($row, 'rejected');

        $code = $body['reason_code'] ?? null;

        if (!\is_string($code) || trim($code) === '') {
            throw ValidationException::forField('reason_code', 'a rejection records why, so the code is required');
        }

        $reason = \is_string($body['reason'] ?? null) && $body['reason'] !== '' ? $body['reason'] : null;
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->update(
            SqlAiReadModel::DRAFTS,
            [
                'state' => 'generated',
                'reviewer_user_id' => $principal->userId,
                'decided_at' => $now,
                'reject_reason_code' => trim($code),
                'reject_reason' => $reason,
                'updated_at' => $now,
            ],
            ['id' => $id],
        );

        return $this->get($principal, $id);
    }

    /**
     * Discard the draft and keep the run, because a discarded run still cost
     * money and the audit must show it. Nothing is written to any domain
     * table, so there is nothing else to undo.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function discard(Principal $principal, int $id, array $body): array
    {
        $row = $this->require($principal, $id);
        $this->requireOpen($row, 'discarded');

        $code = \is_string($body['reason_code'] ?? null) && $body['reason_code'] !== ''
            ? $body['reason_code']
            : 'discarded_by_reviewer';
        $reason = \is_string($body['reason'] ?? null) && $body['reason'] !== '' ? $body['reason'] : null;
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->update(
            SqlAiReadModel::DRAFTS,
            [
                'state' => 'discarded',
                'reviewer_user_id' => $principal->userId,
                'decided_at' => $now,
                'reject_reason_code' => $code,
                'reject_reason' => $reason,
                'updated_at' => $now,
            ],
            ['id' => $id],
        );

        return $this->get($principal, $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function require(Principal $principal, int $id): array
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->draft($id) ?? throw EntityNotFoundException::for('AiDraft', $id);
        $courseId = $row['course_id'] ?? null;
        $this->access->requireAuthoring($principal, \is_numeric($courseId) ? (int) $courseId : null);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requireOpen(array $row, string $act): void
    {
        $state = (string) ($row['state'] ?? '');

        if (!\in_array($state, self::OPEN_STATES, true)) {
            throw ConflictException::with(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('A draft in state %s cannot be %s.', $state, $act),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                ['reason' => 'ai.draft.decided', 'state' => $state],
            );
        }
    }

    private function acknowledge(mixed $ids, int $draftId, Principal $principal): void
    {
        if (!\is_array($ids)) {
            return;
        }

        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        foreach ($ids as $findingId) {
            if (!\is_int($findingId) || $findingId <= 0) {
                continue;
            }

            $this->database->update(
                SqlAiReadModel::FINDINGS,
                ['acknowledged' => 1, 'acknowledged_by' => $principal->userId, 'updated_at' => $now],
                ['id' => $findingId, 'draft_id' => $draftId],
            );
        }
    }

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $findings
     * @param array<string, mixed>|null  $run
     *
     * @return array<string, mixed>
     */
    private function present(array $row, array $findings, ?array $run): array
    {
        $kind = (string) ($row['draft_kind'] ?? '');
        $available = $kind !== '' && $this->registries->aiDraftAcceptors->has($kind);

        return AiPresenter::draft(
            $row,
            $findings,
            $run,
            $available,
            $available ? $this->packages?->packageOf('AiDraftAcceptor', $kind) : null,
        );
    }

    public static function defaultPerPage(): int
    {
        return self::DEFAULT_PER_PAGE;
    }
}
