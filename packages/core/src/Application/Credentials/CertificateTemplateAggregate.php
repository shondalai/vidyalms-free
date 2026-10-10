<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * The rules every write inside the template aggregate obeys, in one place.
 *
 * Three levels are addressed by three different ids and only the root carries
 * the revision and the lock, so a page patch and an element patch both have
 * to walk back to the template before they may decide anything. Each of the
 * ten mutation use cases doing that walk itself are ten chances to forget the
 * lock on one of them, and the one that forgets is the one that lets an
 * author change what an already-issued credential means.
 *
 * The revision is the aggregate's concurrency token as well as the reissue
 * key. Every write inside the aggregate bumps it, so a designer that loaded
 * the template before somebody else moved an element is told rather than
 * silently overwriting them, and a correction across a cohort keys on a
 * number that actually changed. Every mutation requires the revision the
 * author read; a missing or stale precondition is never accepted on their
 * behalf.
 */
final class CertificateTemplateAggregate
{
    public const MAX_STORED_REVISION = 2_147_483_647;

    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly ClockPort $clock,
        /** Every aggregate write is transactional, including root creation. */
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function write(callable $operation): mixed
    {
        return $this->database->transactional($operation);
    }

    /**
     * Claims the aggregate root and performs one mutation under that row
     * lock. The callback receives the root and complete child graph reloaded
     * after the claim, plus the revision this transaction will commit.
     *
     * @template T
     *
     * @param callable(array<string, mixed>, list<CertificateTemplatePage>, int): T $operation
     *
     * @return CertificateTemplateMutationResult<T>
     */
    public function mutate(int $templateId, int $expectedRevision, callable $operation): CertificateTemplateMutationResult
    {
        if ($expectedRevision < 1 || $expectedRevision >= self::MAX_STORED_REVISION) {
            throw ValidationException::forField(
                'expected_revision',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('Must be between 1 and %d.', CertificateTemplateFields::MAX_EXPECTED_REVISION),
            );
        }

        return $this->database->transactional(function () use (
            $templateId,
            $expectedRevision,
            $operation,
        ): CertificateTemplateMutationResult {
            $observed = $this->templates->findById($templateId);

            if ($observed === null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw EntityNotFoundException::for('Certificate template', $templateId);
            }

            if (
                (int) ($observed['revision'] ?? 1) !== $expectedRevision
                || !$this->templates->claimRevision($templateId, $expectedRevision, $this->clock->now())
            ) {
                // This may be nested inside the atomic idempotency transaction.
                // Do not issue a diagnostic read here: under MySQL repeatable
                // read it would still belong to the outer snapshot and could
                // report the revision the losing request originally saw.
                throw ConflictException::with(
                    'This template has changed since it was read.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    ['expected_revision' => $expectedRevision],
                );
            }

            $template = $this->templates->findById($templateId);

            if ($template === null || (int) ($template['revision'] ?? 0) !== $expectedRevision + 1) {
                throw new \RuntimeException('The claimed certificate template could not be reloaded.');
            }

            $revision = $expectedRevision + 1;
            $this->templates->update($templateId, ['has_unpublished_changes' => 1]);
            $template['has_unpublished_changes'] = 1;
            $pages = $this->templates->pagesOf($templateId);

            // Adopt a legacy live graph under the claimed root lock BEFORE
            // the first edit. Later issuance must never observe that edit.
            if (($observed['state'] ?? null) === 'published' && empty($observed['published_snapshot'])) {
                $snapshot = TemplateSnapshot::freezeTemplate($observed, $pages);
                $adoption = [
                    'published_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'published_summary' => json_encode([
                        'name' => $observed['name'], 'renderer' => $observed['renderer'],
                        'format' => $observed['format'], 'revision' => $expectedRevision,
                    ], JSON_THROW_ON_ERROR),
                    'published_name' => $observed['name'],
                    'published_revision' => $expectedRevision,
                    'published_at' => $observed['updated_at'] ?? $this->clock->now()->format('Y-m-d H:i:s'),
                ];
                $this->templates->update($templateId, $adoption);
                $template = array_merge($template, $adoption);
            }

            $value = $operation($template, $pages, $revision);

            return new CertificateTemplateMutationResult($value, $revision);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function require(int $templateId): array
    {
        $row = $this->templates->findById($templateId);

        if ($row === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate template', $templateId);
        }

        return $row;
    }

    public function requirePage(int $pageId): CertificateTemplatePage
    {
        $page = $this->templates->findPage($pageId);

        if ($page === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate template page', $pageId);
        }

        return $page;
    }

    public function requireElement(int $elementId): CertificateTemplateElement
    {
        $element = $this->templates->findElement($elementId);

        if ($element === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate template element', $elementId);
        }

        return $element;
    }

    /**
     * The criteria lock, enforced at the server and named in the refusal.
     *
     * Moodle's badge lock rule: once a credential has been awarded under a
     * criteria set, that set and its expiry are frozen and a change is a new
     * revision, not an edit. The refusal names the frozen fields and the
     * moment they froze, so a screen can say which control it disabled and
     * why rather than reporting a bare conflict.
     *
     * @param array<string, mixed> $template
     * @param list<string>         $changing the fields this write would alter
     */
    public function refuseLockedChange(array $template, array $changing): void
    {
        $lockedAt = $template['locked_at'] ?? null;

        if (!\is_string($lockedAt) || $lockedAt === '') {
            return;
        }

        $frozen = array_values(array_intersect($changing, CertificateTemplateFields::LOCKED_FIELDS));

        if ($frozen === []) {
            return;
        }

        throw ConflictException::with(
            'This template is locked: a certificate has been issued from it.',
            [
                'lock' => 'certificate_template.criteria_locked',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'locked_at' => $lockedAt,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'locked_fields' => CertificateTemplateFields::LOCKED_FIELDS,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'refused_fields' => $frozen,
            ],
        );
    }

    /** @param list<CertificateTemplatePage> $pages */
    public function claimedPage(array $pages, int $pageId): CertificateTemplatePage
    {
        foreach ($pages as $page) {
            if ($page->id === $pageId) {
                return $page;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw EntityNotFoundException::for('Certificate template page', $pageId);
    }

    /** @param list<CertificateTemplatePage> $pages */
    public function claimedElement(array $pages, int $elementId): CertificateTemplateElement
    {
        foreach ($pages as $page) {
            foreach ($page->elements as $element) {
                if ($element->id === $elementId) {
                    return $element;
                }
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw EntityNotFoundException::for('Certificate template element', $elementId);
    }
}
