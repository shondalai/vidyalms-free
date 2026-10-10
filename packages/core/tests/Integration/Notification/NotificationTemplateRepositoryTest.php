<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\SqliteDatabase;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class NotificationTemplateRepositoryTest extends TestCase
{
    private TestPorts $env;
    private SqlNotificationTemplateRepository $templates;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->templates = new SqlNotificationTemplateRepository($this->env->database, $this->env->clock);
    }

    public function testDraftSaveAndDiscardLeaveThePublishedMessageLiveAndKeepAMonotonicTombstone(): void
    {
        $this->publishLegacy('course.enrolled', 'en-GB', 'Original subject', 'Original body');

        $draft = $this->templates->saveDraft(
            'course.enrolled',
            'en-GB',
            'email',
            ['heading' => 'Unfinished'],
            0,
            42,
        );

        self::assertSame(1, $draft['draft_revision']);
        self::assertSame(1, $draft['draft_base_version']);
        self::assertSame(['heading' => 'Unfinished'], $draft['draft_source']);
        self::assertSame('Original body', $this->resolved('course.enrolled')['body_text']);

        $staleSave = $this->captureConflict(
            fn (): array => $this->templates->saveDraft(
                'course.enrolled',
                'en-GB',
                'email',
                ['heading' => 'Stale overwrite'],
                0,
                42,
            ),
        );
        self::assertSame(1, $staleSave->extensions['current_revision']);
        self::assertSame(['heading' => 'Unfinished'], $this->found('course.enrolled')['draft_source']);

        $discarded = $this->templates->discardDraft('course.enrolled', 'en-GB', 'email', 1);

        self::assertSame(2, $discarded['draft_revision']);
        self::assertNull($discarded['draft_source']);
        self::assertNull($discarded['draft_base_version']);
        self::assertSame(1, $discarded['body_version']);
        self::assertSame('Original body', $this->resolved('course.enrolled')['body_text']);

        $conflict = $this->captureConflict(
            fn (): array => $this->templates->discardDraft('course.enrolled', 'en-GB', 'email', 1),
        );
        self::assertSame(2, $conflict->extensions['current_revision']);

        $nextDraft = $this->templates->saveDraft(
            'course.enrolled',
            'en-GB',
            'email',
            ['heading' => 'Replacement'],
            2,
            42,
        );
        self::assertSame(3, $nextDraft['draft_revision']);
        self::assertSame(1, $nextDraft['draft_base_version']);
    }

    public function testFailedPublishAndReplayCannotChangeOrRepublishTheLiveMessage(): void
    {
        $this->publishLegacy('course.completed', 'en-GB', 'Before', 'Published before');
        $source = ['heading' => 'Congratulations', 'paragraphs' => ['Finished']];
        $this->templates->saveDraft('course.completed', 'en-GB', 'email', $source, 0, 7);

        try {
            $this->templates->publishDraft(
                'course.completed',
                'en-GB',
                'email',
                1,
                ['subject' => 'Incomplete compiled result'],
                7,
            );
            self::fail('An incomplete compiled message was published.');
        } catch (\InvalidArgumentException) {
            self::assertSame('Published before', $this->resolved('course.completed')['body_text']);
            self::assertSame($source, $this->found('course.completed')['draft_source']);
        }

        $published = $this->templates->publishDraft(
            'course.completed',
            'en-GB',
            'email',
            1,
            $this->parts('After', 'Published after'),
            7,
            4,
        );

        self::assertSame(2, $published['body_version']);
        self::assertSame(2, $published['draft_revision']);
        self::assertSame($source, $published['published_source']);
        self::assertNull($published['draft_source']);
        self::assertSame(7, $published['published_by']);
        self::assertSame('2026-01-15 10:00:00', $published['published_at']);
        self::assertSame(4, $published['blueprint_version']);
        self::assertSame('Published after', $this->resolved('course.completed')['body_text']);

        $this->captureConflict(
            fn (): array => $this->templates->publishDraft(
                'course.completed',
                'en-GB',
                'email',
                1,
                $this->parts('Again', 'Must not publish twice'),
                7,
            ),
        );
        self::assertSame(2, $this->found('course.completed')['body_version']);
        self::assertSame('Published after', $this->resolved('course.completed')['body_text']);
    }

    public function testLegacyLiveOverwriteAdvancesVersionAndInvalidatesTheDraftBase(): void
    {
        $this->publishLegacy('assignment.graded', 'en-GB', 'First', 'First body');
        $this->templates->saveDraft(
            'assignment.graded',
            'en-GB',
            'email',
            ['heading' => 'Structured live version'],
            0,
            5,
        );
        $this->templates->publishDraft(
            'assignment.graded',
            'en-GB',
            'email',
            1,
            $this->parts('Structured', 'Structured body'),
            5,
        );
        $this->templates->saveDraft(
            'assignment.graded',
            'en-GB',
            'email',
            ['heading' => 'Draft based on structured live version'],
            2,
            5,
        );

        self::assertNotNull($this->found('assignment.graded')['published_source']);

        $this->publishLegacy('assignment.graded', 'en-GB', 'External edit', 'External body');
        $current = $this->found('assignment.graded');
        self::assertSame(3, $current['body_version']);
        self::assertSame(2, $current['draft_base_version']);
        self::assertNull($current['published_source']);
        self::assertSame(['heading' => 'Draft based on structured live version'], $current['draft_source']);

        $conflict = $this->captureConflict(
            fn (): array => $this->templates->publishDraft(
                'assignment.graded',
                'en-GB',
                'email',
                3,
                $this->parts('Draft', 'Draft body'),
                5,
            ),
        );

        self::assertSame(2, $conflict->extensions['draft_base_version']);
        self::assertSame(3, $conflict->extensions['current_body_version']);
        self::assertSame('External body', $this->resolved('assignment.graded')['body_text']);
        self::assertNotNull($this->found('assignment.graded')['draft_source']);
    }

    public function testDraftOnlyIdentityStartsAtZeroPublishesAtOneAndMissingRevisionConflicts(): void
    {
        $missing = $this->captureConflict(
            fn (): array => $this->templates->saveDraft(
                'translation.new',
                'fr-CA',
                'email',
                ['heading' => 'Bonjour'],
                3,
                null,
                SqlNotificationTemplateRepository::DRAFT_ORIGIN_SYSTEM,
            ),
        );
        self::assertNull($missing->extensions['current_revision']);

        $draft = $this->templates->saveDraft(
            'translation.new',
            'fr-CA',
            'email',
            ['heading' => 'Bonjour'],
            0,
            null,
            SqlNotificationTemplateRepository::DRAFT_ORIGIN_SYSTEM,
        );

        self::assertSame(SqlNotificationTemplateRepository::STATE_DRAFT, $draft['state']);
        self::assertSame(0, $draft['body_version']);
        self::assertSame(1, $draft['draft_revision']);
        self::assertNull($this->templates->resolve('translation.new', 'email', ['fr-CA']));

        $published = $this->templates->publishDraft(
            'translation.new',
            'fr-CA',
            'email',
            1,
            $this->parts('Bienvenue', 'Bonjour'),
            null,
        );
        self::assertSame(SqlNotificationTemplateRepository::STATE_PUBLISHED, $published['state']);
        self::assertSame(1, $published['body_version']);
        self::assertSame(2, $published['draft_revision']);
        self::assertSame('Bonjour', $this->resolveFor('translation.new', ['fr-CA'])['body_text']);
    }

    public function testLocaleResolutionExpandsParentsUsesCanonicalDefaultAndNeverChoosesAnArbitraryRow(): void
    {
        $this->publishLegacy('digest.ready', 'de-DE', 'German', 'German');
        $this->publishLegacy('digest.ready', 'en-GB', 'Canonical', 'Canonical');
        $this->publishLegacy('digest.ready', 'fr-FR', 'Site exact', 'Site exact');
        $this->publishLegacy('digest.ready', 'zh', 'Language parent', 'Language parent');
        $this->publishLegacy('digest.ready', 'zh-Hant-TW', 'Recipient exact', 'Recipient exact');

        self::assertSame(
            'zh-Hant-TW',
            $this->resolveFor('digest.ready', ['zh-Hant-TW', 'fr-FR'])['locale'],
        );

        $exact = $this->found('digest.ready', 'zh-Hant-TW');
        $this->env->database->delete(SqlNotificationTemplateRepository::TABLE, ['id' => (int) $exact['id']]);

        self::assertSame(
            'zh',
            $this->resolveFor('digest.ready', ['zh-Hant-TW', 'fr-FR'])['locale'],
        );
        self::assertSame(
            'fr-FR',
            $this->resolveFor('digest.ready', ['es-MX', 'fr-FR'])['locale'],
        );
        self::assertSame('en-GB', $this->resolveFor('digest.ready', [])['locale']);

        $canonical = $this->found('digest.ready');
        $this->env->database->delete(SqlNotificationTemplateRepository::TABLE, ['id' => (int) $canonical['id']]);
        self::assertNull($this->templates->resolve('digest.ready', 'email', ['es-MX']));
    }

    public function testLocaleIdentityIsCanonicalAcrossWritesFindFiltersAndResolution(): void
    {
        $this->publishLegacy('case.ready', 'FR-ca', 'French Canadian', 'French Canadian');
        $this->publishLegacy('case.ready', 'fr', 'French', 'French');

        $exact = $this->templates->find('case.ready', 'fR-CA', 'email');
        self::assertNotNull($exact);
        self::assertSame('fr-CA', $exact['locale']);
        self::assertSame(
            ['case.ready'],
            $this->keys($this->templates->page(null, 'email', 10, 0, 'FR-ca')),
        );
        self::assertSame('fr-CA', $this->resolveFor('case.ready', ['fR-cA'])['locale']);

        $this->env->database->delete(SqlNotificationTemplateRepository::TABLE, ['id' => (int) $exact['id']]);
        self::assertSame('fr', $this->resolveFor('case.ready', ['FR-ca'])['locale']);
    }

    public function testListFiltersAndPaginationAreExactBoundedAndStable(): void
    {
        $this->publishLegacy('alpha.live', 'en-GB', 'Alpha', 'Alpha');
        $this->publishLegacy('beta.changed', 'en-GB', 'Beta', 'Beta');
        $this->templates->saveDraft('beta.changed', 'en-GB', 'email', ['heading' => 'Beta draft'], 0, 1);
        $this->templates->saveDraft('gamma.only', 'fr-FR', 'email', ['heading' => 'Gamma'], 0, 1);
        $this->templates->saveDraft('literal%_match', 'en-GB', 'sms', ['short' => 'Literal'], 0, 1);
        $this->templates->saveDraft('tombstone.only', 'en-GB', 'email', ['heading' => 'Temporary'], 0, 1);
        $this->templates->discardDraft('tombstone.only', 'en-GB', 'email', 1);

        self::assertSame(['alpha.live'], $this->keys($this->templates->page(
            null,
            null,
            10,
            0,
            null,
            null,
            SqlNotificationTemplateRepository::STATUS_PUBLISHED,
        )));
        self::assertSame(['beta.changed'], $this->keys($this->templates->page(
            null,
            null,
            10,
            0,
            null,
            null,
            SqlNotificationTemplateRepository::STATUS_DRAFT_CHANGES,
        )));
        self::assertSame(['gamma.only', 'literal%_match', 'tombstone.only'], $this->keys($this->templates->page(
            null,
            null,
            10,
            0,
            null,
            null,
            SqlNotificationTemplateRepository::STATUS_DRAFT_ONLY,
        )));
        self::assertSame(['beta.changed', 'gamma.only', 'literal%_match'], $this->keys($this->templates->page(
            null,
            null,
            10,
            0,
            null,
            null,
            SqlNotificationTemplateRepository::STATUS_DRAFT,
        )));

        $literal = $this->templates->page(null, null, 10, 0, null, '%_', null);
        self::assertSame(1, $literal['total']);
        self::assertSame(['literal%_match'], $this->keys($literal));

        $french = $this->templates->page(null, 'email', 10, 0, 'fr-FR');
        self::assertSame(1, $french['total']);
        self::assertSame(['gamma.only'], $this->keys($french));

        $firstPage = $this->templates->page(null, null, 2, 0);
        $secondPage = $this->templates->page(null, null, 2, 2);
        self::assertSame(5, $firstPage['total']);
        self::assertSame(['alpha.live', 'beta.changed'], $this->keys($firstPage));
        self::assertSame(['gamma.only', 'literal%_match'], $this->keys($secondPage));

        $this->expectException(\InvalidArgumentException::class);
        $this->templates->page(null, null, 101, 0);
    }

    public function testConcurrentNewIdentityLoserReturnsConflictWithoutChangingWinner(): void
    {
        $winner = $this->templates->saveDraft('race.same', 'en-GB', 'email', ['heading' => 'Winner'], 0, 9);
        $database = new class ($this->env->database) implements DatabasePort {
            private bool $hideFirstTemplateRead = true;

            public function __construct(private readonly SqliteDatabase $inner)
            {
            }

            public function selectAll(Query $query): array
            {
                return $this->inner->selectAll($query);
            }

            public function selectOne(Query $query): ?array
            {
                if ($this->hideFirstTemplateRead && $query->table() === SqlNotificationTemplateRepository::TABLE) {
                    $this->hideFirstTemplateRead = false;

                    return null;
                }

                return $this->inner->selectOne($query);
            }

            public function count(Query $query): int
            {
                return $this->inner->count($query);
            }

            public function insert(string $table, array $row): int
            {
                return $this->inner->insert($table, $row);
            }

            public function update(string $table, array $values, array $where): int
            {
                return $this->inner->update($table, $values, $where);
            }

            public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
            {
                $this->inner->upsert($table, $row, $uniqueBy, $updateColumns);
            }

            public function delete(string $table, array $where): int
            {
                return $this->inner->delete($table, $where);
            }

            public function transactional(callable $operation): mixed
            {
                return $this->inner->transactional($operation);
            }
        };
        $loser = new SqlNotificationTemplateRepository($database, $this->env->clock);
        $conflict = $this->captureConflict(
            fn (): array => $loser->saveDraft('race.same', 'en-GB', 'email', ['heading' => 'Loser'], 0, 10),
        );

        self::assertSame(1, $conflict->extensions['current_revision']);
        self::assertSame(['heading' => 'Winner'], $this->found('race.same')['draft_source']);
        self::assertSame($winner['id'], $this->found('race.same')['id']);
    }

    public function testDraftSaveCasRejectsALiveVersionAdvanceAtTheInterleavingWrite(): void
    {
        $this->publishLegacy('race.live', 'en-GB', 'Version one', 'Version one');
        $inner = $this->env->database;
        $live = $this->templates;
        $database = new class ($inner, static function () use ($live): void {
            $live->save('race.live', 'en-GB', 'email', [
                'subject' => 'Version two',
                'body_text' => 'Version two',
                'state' => SqlNotificationTemplateRepository::STATE_PUBLISHED,
            ]);
        }) implements DatabasePort {
            private bool $raced = false;

            public function __construct(
                private readonly SqliteDatabase $inner,
                private readonly \Closure $advanceLive,
            ) {
            }

            public function selectAll(Query $query): array
            {
                return $this->inner->selectAll($query);
            }

            public function selectOne(Query $query): ?array
            {
                return $this->inner->selectOne($query);
            }

            public function count(Query $query): int
            {
                return $this->inner->count($query);
            }

            public function insert(string $table, array $row): int
            {
                return $this->inner->insert($table, $row);
            }

            public function update(string $table, array $values, array $where): int
            {
                if (!$this->raced && $table === SqlNotificationTemplateRepository::TABLE && isset($values['draft_source'])) {
                    $this->raced = true;
                    ($this->advanceLive)();
                }

                return $this->inner->update($table, $values, $where);
            }

            public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
            {
                $this->inner->upsert($table, $row, $uniqueBy, $updateColumns);
            }

            public function delete(string $table, array $where): int
            {
                return $this->inner->delete($table, $where);
            }

            public function transactional(callable $operation): mixed
            {
                // Model two committed database transactions: the injected
                // live write must not roll back with the losing draft write.
                return $operation();
            }
        };
        $racing = new SqlNotificationTemplateRepository($database, $this->env->clock);
        $conflict = $this->captureConflict(fn (): array => $racing->saveDraft(
            'race.live',
            'en-GB',
            'email',
            ['heading' => 'Draft from version one'],
            0,
            5,
        ));

        self::assertSame(1, $conflict->extensions['draft_base_version']);
        self::assertSame(2, $conflict->extensions['current_body_version']);
        $winner = $this->found('race.live');
        self::assertSame(2, $winner['body_version']);
        self::assertSame('Version two', $winner['body_text']);
        self::assertNull($winner['draft_source']);
    }

    private function publishLegacy(string $key, string $locale, string $subject, string $body): void
    {
        $this->templates->save($key, $locale, 'email', $this->parts($subject, $body) + [
            'state' => SqlNotificationTemplateRepository::STATE_PUBLISHED,
        ]);
    }

    /** @return array{subject: string, body_text: string, body_html: string, body_short: null} */
    private function parts(string $subject, string $body): array
    {
        return [
            'subject' => $subject,
            'body_text' => $body,
            'body_html' => '<p>' . $body . '</p>',
            'body_short' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function resolved(string $key): array
    {
        return $this->resolveFor($key, ['en-GB']);
    }

    /**
     * @param list<string> $localeChain
     *
     * @return array<string, mixed>
     */
    private function resolveFor(string $key, array $localeChain): array
    {
        $resolved = $this->templates->resolve($key, 'email', $localeChain);
        self::assertNotNull($resolved);

        return $resolved;
    }

    /** @return array<string, mixed> */
    private function found(string $key, string $locale = 'en-GB', string $channel = 'email'): array
    {
        $found = $this->templates->find($key, $locale, $channel);
        self::assertNotNull($found);

        return $found;
    }

    /**
     * @param callable(): mixed $operation
     */
    private function captureConflict(callable $operation): ConflictException
    {
        try {
            $operation();
        } catch (ConflictException $conflict) {
            return $conflict;
        }

        self::fail('Expected a conflict.');
    }

    /**
     * @param array{items: list<array<string, mixed>>, total: int} $page
     *
     * @return list<string>
     */
    private function keys(array $page): array
    {
        return array_map(static fn (array $row): string => (string) $row['template_key'], $page['items']);
    }
}
