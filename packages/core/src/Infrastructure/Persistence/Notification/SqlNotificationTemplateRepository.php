<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateLocale;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Published multipart messages and their non-destructive structured drafts.
 *
 * One row remains uniquely identified by template, locale and channel. Live
 * message parts are resolved only from published rows; draft saves therefore
 * never make a production send observe unfinished content. Draft revision is
 * a monotonic compare-and-swap boundary and is deliberately retained after a
 * discard or publish so an old editor tab can never become current again.
 */
final class SqlNotificationTemplateRepository
{
    public const TABLE = 'notification_templates';
    public const STATE_PUBLISHED = 'published';
    public const STATE_DRAFT = 'draft';

    public const DRAFT_ORIGIN_USER = 'user';
    public const DRAFT_ORIGIN_SYSTEM = 'system';

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DRAFT_CHANGES = 'draft_changes';
    public const STATUS_DRAFT_ONLY = 'draft_only';
    public const STATUS_DRAFT = 'draft';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const MAX_PAGE_SIZE = 100;
    private const MAX_UNSIGNED_INTEGER = 4_294_967_295;
    private const MESSAGE_PARTS = ['subject', 'body_text', 'body_html', 'body_short'];
    private const SOURCE_COLUMNS = ['published_source', 'draft_source'];

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @param list<string> $localeChain recipient and site locales in priority order
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $templateKey, string $channelKey, array $localeChain): ?array
    {
        $locales = NotificationTemplateLocale::chain($localeChain);
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('template_key', '=', $templateKey)
                ->where('channel_key', '=', $channelKey)
                ->where('state', '=', self::STATE_PUBLISHED)
                ->whereIn('locale', $locales)
                ->limit(\count($locales)),
        );
        $byLocale = [];

        foreach ($rows as $row) {
            if (isset($row['locale']) && \is_string($row['locale'])) {
                $byLocale[strtolower($row['locale'])] = $row;
            }
        }

        foreach ($locales as $locale) {
            $row = $byLocale[strtolower($locale)] ?? null;

            if ($row !== null) {
                return $this->hydrate($row);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $templateKey, string $locale, string $channelKey): ?array
    {
        $locale = NotificationTemplateLocale::canonicalize($locale);
        $row = $this->findRaw($templateKey, $locale, $channelKey);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Legacy whole-message write retained while the old admin route migrates
     * to the draft contract. An existing live overwrite advances body_version
     * with a CAS so a draft based on the previous version cannot publish over
     * it silently.
     *
     * @param array<string, mixed> $values
     */
    public function save(string $templateKey, string $locale, string $channelKey, array $values): int
    {
        $locale = NotificationTemplateLocale::canonicalize($locale);
        $values = $this->encodeSourceValues($values);
        // This compatibility path writes already-rendered live parts. It may
        // neither smuggle structured draft/source state into the studio nor
        // leave an older published source claiming to describe the new
        // parts. Only publishDraft() establishes published_source.
        unset(
            $values['published_source'],
            $values['draft_source'],
            $values['draft_revision'],
            $values['draft_base_version'],
            $values['draft_origin'],
            $values['draft_updated_by'],
            $values['draft_updated_at'],
        );
        $values['published_source'] = null;
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        return $this->database->transactional(function () use (
            $templateKey,
            $locale,
            $channelKey,
            $values,
            $now,
        ): int {
            $existing = $this->findRaw($templateKey, $locale, $channelKey);

            if ($existing === null) {
                return $this->database->insert(self::TABLE, array_merge($values, [
                    'template_key' => $templateKey,
                    'locale' => $locale,
                    'channel_key' => $channelKey,
                    'body_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }

            $id = (int) $existing['id'];
            $currentVersion = (int) ($existing['body_version'] ?? 1);
            $nextVersion = max(1, $currentVersion + 1);
            unset(
                $values['id'],
                $values['template_key'],
                $values['locale'],
                $values['channel_key'],
                $values['body_version'],
                $values['draft_revision'],
                $values['created_at'],
                $values['updated_at'],
            );

            $updated = $this->database->update(
                self::TABLE,
                array_merge($values, ['body_version' => $nextVersion, 'updated_at' => $now]),
                ['id' => $id, 'body_version' => $currentVersion],
            );

            if ($updated !== 1) {
                throw new ConflictException('The notification template changed while it was being saved.');
            }

            return $id;
        });
    }

    /**
     * Saves structured source without changing published parts or state.
     *
     * expectedRevision zero creates a missing identity. A later revision on a
     * missing row is a stale request, while a concurrent creator is contained
     * by the existing unique identity and reported as a conflict.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    public function saveDraft(
        string $templateKey,
        string $locale,
        string $channelKey,
        array $source,
        int $expectedRevision,
        ?int $updatedBy,
        string $origin = self::DRAFT_ORIGIN_USER,
    ): array {
        $this->requireRevision($expectedRevision);
        $this->requireActor($updatedBy);
        $this->requireDraftOrigin($origin);
        $locale = NotificationTemplateLocale::canonicalize($locale);
        $encodedSource = $this->encodeSource($source);
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $attemptedInsert = false;

        try {
            return $this->database->transactional(function () use (
                $templateKey,
                $locale,
                $channelKey,
                $encodedSource,
                $expectedRevision,
                $updatedBy,
                $origin,
                $now,
                &$attemptedInsert,
            ): array {
                $existing = $this->findRaw($templateKey, $locale, $channelKey);

                if ($existing === null) {
                    if ($expectedRevision !== 0) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        throw $this->revisionConflict($expectedRevision, null);
                    }

                    $attemptedInsert = true;
                    $id = $this->database->insert(self::TABLE, [
                        'template_key' => $templateKey,
                        'locale' => $locale,
                        'channel_key' => $channelKey,
                        'body_version' => 0,
                        'state' => self::STATE_DRAFT,
                        'draft_source' => $encodedSource,
                        'draft_revision' => 1,
                        'draft_base_version' => 0,
                        'draft_origin' => $origin,
                        'draft_updated_by' => $updatedBy,
                        'draft_updated_at' => $now,
                        'blueprint_version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    return $this->rowAfterWrite($id);
                }

                $existing = $this->assertRevision($existing, $expectedRevision);
                $revision = $this->nextRevision($expectedRevision);
                $hasDraft = ($existing['draft_source'] ?? null) !== null;
                $bodyVersion = (int) ($existing['body_version'] ?? 0);
                $baseVersion = $hasDraft
                    ? $this->requiredDraftBaseVersion($existing)
                    : $bodyVersion;
                $updated = $this->database->update(
                    self::TABLE,
                    [
                        'draft_source' => $encodedSource,
                        'draft_revision' => $revision,
                        'draft_base_version' => $baseVersion,
                        'draft_origin' => $origin,
                        'draft_updated_by' => $updatedBy,
                        'draft_updated_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'id' => (int) $existing['id'],
                        'draft_revision' => $expectedRevision,
                        'body_version' => $bodyVersion,
                    ],
                );

                if ($updated !== 1) {
                    throw $this->draftWriteConflict(
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        $expectedRevision,
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        $bodyVersion,
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        $this->findRaw($templateKey, $locale, $channelKey),
                    );
                }

                return $this->rowAfterWrite((int) $existing['id']);
            });
        } catch (ConflictException $conflict) {
            throw $conflict;
        } catch (\Throwable $failure) {
            // An insert may lose the identity's unique race. Re-read only
            // after the transaction has rolled back, when MySQL's repeatable
            // read snapshot can no longer hide the winning row.
            $winner = $attemptedInsert ? $this->findRaw($templateKey, $locale, $channelKey) : null;

            if ($winner !== null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw $this->revisionConflict($expectedRevision, $winner);
            }

            throw $failure;
        }
    }

    /**
     * Clears only the current draft and advances its revision tombstone.
     *
     * @return array<string, mixed>
     */
    public function discardDraft(
        string $templateKey,
        string $locale,
        string $channelKey,
        int $expectedRevision,
    ): array {
        $this->requireRevision($expectedRevision);
        $locale = NotificationTemplateLocale::canonicalize($locale);
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        return $this->database->transactional(function () use (
            $templateKey,
            $locale,
            $channelKey,
            $expectedRevision,
            $now,
        ): array {
            $existing = $this->findRaw($templateKey, $locale, $channelKey);
            $existing = $this->assertRevision($existing, $expectedRevision);

            if (($existing['draft_source'] ?? null) === null) {
                throw new ConflictException('The notification template has no draft to discard.');
            }

            $revision = $this->nextRevision($expectedRevision);
            $updated = $this->database->update(
                self::TABLE,
                [
                    'draft_source' => null,
                    'draft_revision' => $revision,
                    'draft_base_version' => null,
                    'draft_origin' => null,
                    'draft_updated_by' => null,
                    'draft_updated_at' => null,
                    'updated_at' => $now,
                ],
                ['id' => (int) $existing['id'], 'draft_revision' => $expectedRevision],
            );

            if ($updated !== 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw $this->revisionConflict($expectedRevision, $this->findRaw($templateKey, $locale, $channelKey));
            }

            return $this->rowAfterWrite((int) $existing['id']);
        });
    }

    /**
     * Atomically publishes the current draft's already-compiled message parts.
     *
     * @param array<string, mixed> $compiledParts
     *
     * @return array<string, mixed>
     */
    public function publishDraft(
        string $templateKey,
        string $locale,
        string $channelKey,
        int $expectedRevision,
        array $compiledParts,
        ?int $publishedBy,
        int $blueprintVersion = 1,
    ): array {
        $this->requireRevision($expectedRevision);
        $this->requireActor($publishedBy);
        $locale = NotificationTemplateLocale::canonicalize($locale);

        if ($blueprintVersion < 1) {
            throw new \InvalidArgumentException('Blueprint version must be positive.');
        }

        $parts = $this->compiledParts($compiledParts);
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        return $this->database->transactional(function () use (
            $templateKey,
            $locale,
            $channelKey,
            $expectedRevision,
            $parts,
            $publishedBy,
            $blueprintVersion,
            $now,
        ): array {
            $existing = $this->findRaw($templateKey, $locale, $channelKey);
            $existing = $this->assertRevision($existing, $expectedRevision);
            $draftSource = $existing['draft_source'] ?? null;

            if (!\is_string($draftSource) || $draftSource === '') {
                throw new ConflictException('The notification template has no draft to publish.');
            }

            $baseVersion = $this->requiredDraftBaseVersion($existing);
            $bodyVersion = (int) ($existing['body_version'] ?? 0);

            if ($baseVersion !== $bodyVersion) {
                throw ConflictException::with(
                    'The published notification template changed after this draft was started.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    ['draft_base_version' => $baseVersion, 'current_body_version' => $bodyVersion],
                );
            }

            $revision = $this->nextRevision($expectedRevision);
            $updated = $this->database->update(
                self::TABLE,
                array_merge($parts, [
                    'body_version' => $bodyVersion + 1,
                    'state' => self::STATE_PUBLISHED,
                    'published_source' => $draftSource,
                    'draft_source' => null,
                    'draft_revision' => $revision,
                    'draft_base_version' => null,
                    'draft_origin' => null,
                    'draft_updated_by' => null,
                    'draft_updated_at' => null,
                    'published_by' => $publishedBy,
                    'published_at' => $now,
                    'blueprint_version' => $blueprintVersion,
                    'updated_at' => $now,
                ]),
                [
                    'id' => (int) $existing['id'],
                    'draft_revision' => $expectedRevision,
                    'draft_base_version' => $baseVersion,
                    'body_version' => $bodyVersion,
                ],
            );

            if ($updated !== 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw $this->revisionConflict($expectedRevision, $this->findRaw($templateKey, $locale, $channelKey));
            }

            return $this->rowAfterWrite((int) $existing['id']);
        });
    }

    /**
     * Seeds a template row only when the key has none, so an upgrade never
     * overwrites an author's edit.
     *
     * @param array<string, mixed> $values
     */
    public function seed(string $templateKey, string $locale, string $channelKey, array $values): void
    {
        if ($this->find($templateKey, $locale, $channelKey) !== null) {
            return;
        }

        $this->save($templateKey, $locale, $channelKey, $values);
    }

    /**
     * Existing callers use the first four arguments; studio filters are
     * trailing until its application service replaces the legacy route.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function page(
        ?string $templateKey,
        ?string $channelKey,
        int $limit,
        int $offset,
        ?string $locale = null,
        ?string $search = null,
        ?string $status = null,
    ): array {
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException('Notification template page size must be between 1 and 100.');
        }

        if ($offset < 0) {
            throw new \InvalidArgumentException('Notification template offset cannot be negative.');
        }

        $statuses = [
            self::STATUS_PUBLISHED,
            self::STATUS_DRAFT_CHANGES,
            self::STATUS_DRAFT_ONLY,
            self::STATUS_DRAFT,
        ];

        if ($status !== null && !\in_array($status, $statuses, true)) {
            throw new \InvalidArgumentException('Unknown notification template status filter.');
        }

        $locale = $locale === null ? null : NotificationTemplateLocale::canonicalize($locale);
        $filter = static function (Query $query) use (
            $templateKey,
            $channelKey,
            $locale,
            $search,
            $status,
        ): Query {
            if ($templateKey !== null) {
                $query->where('template_key', '=', $templateKey);
            }

            if ($channelKey !== null) {
                $query->where('channel_key', '=', $channelKey);
            }

            if ($locale !== null) {
                $query->where('locale', '=', $locale);
            }

            if ($search !== null && trim($search) !== '') {
                $query->whereContains('template_key', trim($search));
            }

            if ($status === self::STATUS_PUBLISHED) {
                $query->where('state', '=', self::STATE_PUBLISHED)->whereNull('draft_source');
            } elseif ($status === self::STATUS_DRAFT_CHANGES) {
                $query->where('state', '=', self::STATE_PUBLISHED)->whereNotNull('draft_source');
            } elseif ($status === self::STATUS_DRAFT_ONLY) {
                $query->where('state', '=', self::STATE_DRAFT);
            } elseif ($status === self::STATUS_DRAFT) {
                $query->whereNotNull('draft_source');
            }

            return $query;
        };
        $rows = $this->database->selectAll(
            $filter(Query::from(self::TABLE))
                ->orderBy('template_key')
                ->orderBy('locale')
                ->orderBy('channel_key')
                ->limit($limit)
                ->offset($offset),
        );

        return [
            'items' => array_map($this->hydrate(...), $rows),
            'total' => $this->database->count($filter(Query::from(self::TABLE))),
        ];
    }

    /** @return array<string, mixed>|null */
    private function findRaw(string $templateKey, string $locale, string $channelKey): ?array
    {
        return $this->database->selectOne(
            Query::from(self::TABLE)
                ->where('template_key', '=', $templateKey)
                ->where('locale', '=', $locale)
                ->where('channel_key', '=', $channelKey),
        );
    }

    /** @return array<string, mixed> */
    private function rowAfterWrite(int $id): array
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        if ($row === null) {
            throw new \RuntimeException('The notification template write could not be read back.');
        }

        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>
     */
    private function assertRevision(?array $row, int $expectedRevision): array
    {
        if ($row === null || (int) ($row['draft_revision'] ?? 0) !== $expectedRevision) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw $this->revisionConflict($expectedRevision, $row);
        }

        return $row;
    }

    /** @param array<string, mixed>|null $row */
    private function revisionConflict(int $expectedRevision, ?array $row): ConflictException
    {
        return ConflictException::with(
            'The notification template draft changed after it was opened.',
            [
                'expected_revision' => $expectedRevision,
                'current_revision' => $row === null ? null : (int) ($row['draft_revision'] ?? 0),
            ],
        );
    }

    /** @param array<string, mixed>|null $row */
    private function draftWriteConflict(int $expectedRevision, int $expectedBodyVersion, ?array $row): ConflictException
    {
        $currentRevision = $row === null ? null : (int) ($row['draft_revision'] ?? 0);
        $currentBodyVersion = $row === null ? null : (int) ($row['body_version'] ?? 0);

        if ($currentRevision === $expectedRevision && $currentBodyVersion !== $expectedBodyVersion) {
            return ConflictException::with(
                'The published notification template changed while its draft was being saved.',
                [
                    'draft_base_version' => $expectedBodyVersion,
                    'current_body_version' => $currentBodyVersion,
                ],
            );
        }

        return $this->revisionConflict($expectedRevision, $row);
    }

    private function requireRevision(int $revision): void
    {
        if ($revision < 0 || $revision >= self::MAX_UNSIGNED_INTEGER) {
            throw new \InvalidArgumentException('Draft revision is outside the supported range.');
        }
    }

    private function nextRevision(int $revision): int
    {
        $this->requireRevision($revision);

        return $revision + 1;
    }

    private function requireActor(?int $actor): void
    {
        if ($actor !== null && $actor < 1) {
            throw new \InvalidArgumentException('A template actor must be a positive user id.');
        }
    }

    private function requireDraftOrigin(string $origin): void
    {
        if (!\in_array($origin, [self::DRAFT_ORIGIN_USER, self::DRAFT_ORIGIN_SYSTEM], true)) {
            throw new \InvalidArgumentException('Draft origin must be user or system.');
        }
    }

    /** @param array<string, mixed> $row */
    private function requiredDraftBaseVersion(array $row): int
    {
        $base = $row['draft_base_version'] ?? null;

        if (!\is_int($base) && !(\is_string($base) && preg_match('/^[0-9]+$/D', $base) === 1)) {
            throw new \RuntimeException('The notification template draft has no valid base version.');
        }

        return (int) $base;
    }

    /**
     * @param array<string, mixed> $parts
     *
     * @return array<string, string|null>
     */
    private function compiledParts(array $parts): array
    {
        if (\count($parts) !== \count(self::MESSAGE_PARTS)) {
            throw new \InvalidArgumentException('Compiled message parts must contain the exact four supported fields.');
        }

        $validated = [];

        foreach (self::MESSAGE_PARTS as $part) {
            if (!\array_key_exists($part, $parts)) {
                throw new \InvalidArgumentException('Compiled message parts must contain the exact four supported fields.');
            }

            $value = $parts[$part];

            if ($value !== null && !\is_string($value)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \InvalidArgumentException(sprintf('Compiled message part "%s" must be a string or null.', $part));
            }

            $validated[$part] = $value;
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function encodeSourceValues(array $values): array
    {
        foreach (self::SOURCE_COLUMNS as $column) {
            if (isset($values[$column]) && \is_array($values[$column])) {
                $values[$column] = $this->encodeSource($values[$column]);
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $source */
    private function encodeSource(array $source): string
    {
        return json_encode($source, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        foreach (self::SOURCE_COLUMNS as $column) {
            $value = $row[$column] ?? null;

            if ($value === null) {
                $row[$column] = null;

                continue;
            }

            if (\is_array($value)) {
                $row[$column] = $value;

                continue;
            }

            if (!\is_string($value) || $value === '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \RuntimeException(sprintf('Notification template %s is not valid JSON source.', $column));
            }

            /** @var mixed $decoded */
            $decoded = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($decoded)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \RuntimeException(sprintf('Notification template %s must decode to an object.', $column));
            }

            $row[$column] = $decoded;
        }

        return $row;
    }
}
