<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class NotificationTemplateStudioHttpTest extends TestCase
{
    private const KEY = 'vidyalms.mail.enrolled';
    private const PATH = '/manage/notifications/templates/vidyalms.mail.enrolled/fr/email';
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testPublishReplayAdvancesTheVersionExactlyOnce(): void
    {
        $fixture = $this->fixture;
        $manager = $fixture->manager();
        $detail = $fixture->call('GET', self::PATH, $manager);
        self::assertSame(200, $detail->status);
        self::assertNotNull($detail->data);
        self::assertNull($detail->data['id']);
        $body = ['source' => $detail->data['default_source'], 'expected_revision' => 0];
        $saved = $fixture->call('PUT', self::PATH . '/draft', $manager, $body, idempotencyKey: 'draft-one');
        $replay = $fixture->call('PUT', self::PATH . '/draft', $manager, $body, idempotencyKey: 'draft-one');
        self::assertSame(200, $saved->status);
        self::assertNotNull($saved->data);
        self::assertSame($saved->data, $replay->data);
        self::assertSame(1, $saved->data['draft_revision']);
        $publishBody = ['expected_revision' => $saved->data['draft_revision']];
        $published = $fixture->call('POST', self::PATH . '/publish', $manager, $publishBody, idempotencyKey: 'publish-one');
        $again = $fixture->call('POST', self::PATH . '/publish', $manager, $publishBody, idempotencyKey: 'publish-one');
        self::assertSame(200, $published->status);
        self::assertNotNull($published->data);
        self::assertSame($published->data, $again->data);
        self::assertSame(1, $published->data['body_version']);
        self::assertSame(2, $published->data['draft_revision']);
        self::assertNull($published->data['draft_source']);
        self::assertSame('true', $again->headers['Idempotent-Replayed']);
        $stale = $fixture->call('POST', self::PATH . '/publish', $manager, $publishBody, idempotencyKey: 'different-key');
        self::assertSame(409, $stale->status);
    }

    public function testReplayIsScopedToTheExactTranslation(): void
    {
        $fixture = $this->fixture;
        $detail = $fixture->call('GET', self::PATH, $fixture->manager());
        self::assertNotNull($detail->data);
        $body = ['source' => $detail->data['default_source'], 'expected_revision' => 0];
        $french = $fixture->call('PUT', self::PATH . '/draft', $fixture->manager(), $body, idempotencyKey: 'same-client-key');
        $german = $fixture->call(
            'PUT',
            str_replace('/fr/', '/de/', self::PATH) . '/draft',
            $fixture->manager(),
            $body,
            idempotencyKey: 'same-client-key',
        );
        self::assertSame(200, $french->status);
        self::assertSame(200, $german->status);
        self::assertNotNull($french->data);
        self::assertNotNull($german->data);
        self::assertSame('de', $german->data['locale']);
        self::assertNotSame($french->data['id'], $german->data['id']);
    }

    public function testDraftBaseVersionIsANullableJsonIntegerEvenWhenTheDatabaseReturnsNumericStrings(): void
    {
        $fixture = $this->fixture;
        $manager = $fixture->manager();
        $pdo = $fixture->env->database->pdo();
        $previous = $pdo->getAttribute(\PDO::ATTR_STRINGIFY_FETCHES);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        try {
            $initial = $fixture->call('GET', self::PATH, $manager);
            self::assertSame(200, $initial->status);
            self::assertNotNull($initial->data);
            self::assertNull($initial->data['draft_base_version']);
            $source = $initial->data['default_source'];
            $revision = 0;

            foreach ([0, 1, 2] as $baseVersion) {
                $draft = $fixture->call(
                    'PUT',
                    self::PATH . '/draft',
                    $manager,
                    ['source' => $source, 'expected_revision' => $revision],
                    idempotencyKey: 'string-db-draft-' . $baseVersion,
                );
                self::assertSame(200, $draft->status);
                self::assertNotNull($draft->data);

                // This reproduces the installed wpdb difference through a
                // real SQL fetch, not a mock already returning the desired
                // type. Zero must stay zero; absence must stay null.
                $raw = $fixture->env->database->selectOne(Query::from('notification_templates')
                    ->where('template_key', '=', self::KEY)->where('locale', '=', 'fr')->where('channel_key', '=', 'email'));
                self::assertNotNull($raw);
                self::assertSame((string) $baseVersion, $raw['draft_base_version']);
                self::assertSame($baseVersion, $draft->data['draft_base_version']);
                self::assertStringContainsString(
                    '"draft_base_version":' . $baseVersion . ',',
                    json_encode($draft->data, \JSON_THROW_ON_ERROR),
                );
                $reopened = $fixture->call('GET', self::PATH, $manager);
                self::assertSame(200, $reopened->status);
                self::assertNotNull($reopened->data);
                self::assertSame($baseVersion, $reopened->data['draft_base_version']);
                ++$revision;

                $finished = $baseVersion < 2
                    ? $fixture->call(
                        'POST',
                        self::PATH . '/publish',
                        $manager,
                        ['expected_revision' => $revision],
                        idempotencyKey: 'string-db-publish-' . $baseVersion,
                    )
                    : $fixture->call(
                        'DELETE',
                        self::PATH . '/draft',
                        $manager,
                        [],
                        ['expected_revision' => (string) $revision],
                        idempotencyKey: 'string-db-discard',
                    );
                self::assertSame(200, $finished->status);
                self::assertNotNull($finished->data);
                self::assertNull($finished->data['draft_base_version']);
                self::assertStringContainsString('"draft_base_version":null', json_encode($finished->data, \JSON_THROW_ON_ERROR));
                ++$revision;
            }
        } finally {
            $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, $previous);
        }
    }

    public function testEveryStudioReplayScopeFitsTheDatabaseForA64BitPrincipal(): void
    {
        $fixture = $this->fixture;
        $userId = \PHP_INT_MAX;
        $fixture->env->authorization->grant($userId, Capability::MANAGE);
        $fixture->env->users->add($userId, 'Large identifier manager', 'large-id@example.test');
        $manager = Principal::user($userId);
        $path = str_replace('/fr/', '/es-MX/', self::PATH);
        $detail = $fixture->call('GET', $path, $manager);
        self::assertNotNull($detail->data);
        $source = $detail->data['default_source'];

        $draft = $fixture->call(
            'PUT',
            $path . '/draft',
            $manager,
            ['source' => $source, 'expected_revision' => 0],
            idempotencyKey: 'large-id-draft-one',
        );
        self::assertSame(200, $draft->status);
        self::assertNotNull($draft->data);
        $published = $fixture->call(
            'POST',
            $path . '/publish',
            $manager,
            ['expected_revision' => $draft->data['draft_revision']],
            idempotencyKey: 'large-id-publish',
        );
        self::assertSame(200, $published->status);
        self::assertNotNull($published->data);
        $reopened = $fixture->call(
            'PUT',
            $path . '/draft',
            $manager,
            ['source' => $source, 'expected_revision' => $published->data['draft_revision']],
            idempotencyKey: 'large-id-draft-two',
        );
        self::assertSame(200, $reopened->status);
        self::assertNotNull($reopened->data);
        self::assertSame(200, $fixture->call(
            'DELETE',
            $path . '/draft',
            $manager,
            query: ['expected_revision' => (string) $reopened->data['draft_revision']],
            idempotencyKey: 'large-id-discard',
        )->status);
        self::assertSame(200, $fixture->call(
            'POST',
            $path . '/test-send',
            $manager,
            ['source' => $source],
            idempotencyKey: 'large-id-test',
        )->status);

        $keys = [
            'large-id-draft-one',
            'large-id-publish',
            'large-id-draft-two',
            'large-id-discard',
            'large-id-test',
        ];
        $rows = $fixture->env->database->selectAll(
            Query::from('idempotency_keys')->whereIn('idem_key', $keys),
        );
        self::assertCount(5, $rows);
        foreach ($rows as $row) {
            self::assertLessThanOrEqual(
                100,
                \strlen((string) $row['scope']),
                'The internal route scope plus a 19-digit principal must fit idempotency_keys.scope.',
            );
        }

        $identity = hash('sha256', self::KEY . '|es-MX|email');
        $scopes = array_column($rows, 'scope', 'idem_key');
        self::assertSame('nt.draft.put:' . $identity . ':' . $userId, $scopes['large-id-draft-one']);
        self::assertSame('nt.publish.post:' . $identity . ':' . $userId, $scopes['large-id-publish']);
        self::assertSame('nt.draft.delete:' . $identity . ':' . $userId, $scopes['large-id-discard']);
        self::assertSame('nt.test:' . $identity . ':' . $userId, $scopes['large-id-test']);
    }

    public function testDiscardAndStaleEditCannotRestoreDiscardedWork(): void
    {
        $fixture = $this->fixture;
        $detail = $fixture->call('GET', self::PATH, $fixture->manager());
        self::assertNotNull($detail->data);
        $body = ['source' => $detail->data['default_source'], 'expected_revision' => 0];
        $fixture->call('PUT', self::PATH . '/draft', $fixture->manager(), $body);
        $discard = $fixture->call('DELETE', self::PATH . '/draft', $fixture->manager(), query: ['expected_revision' => '1']);
        self::assertSame(200, $discard->status);
        self::assertNotNull($discard->data);
        self::assertSame('unpublished', $discard->data['status']);
        self::assertSame(2, $discard->data['draft_revision']);
        $stale = $fixture->call('PUT', self::PATH . '/draft', $fixture->manager(), $body);
        self::assertSame(409, $stale->status);
        $reopened = $fixture->call('GET', self::PATH, $fixture->manager());
        self::assertNotNull($reopened->data);
        self::assertNull($reopened->data['draft_source']);
    }

    public function testLocaleCaseAliasesReplayDraftAndSelfTestExactlyOnce(): void
    {
        $fixture = $this->fixture;
        $path = str_replace('/fr/', '/fr-ca/', self::PATH);
        $canonical = str_replace('/fr/', '/fr-CA/', self::PATH);
        $detail = $fixture->call('GET', $path, $fixture->manager());
        self::assertNotNull($detail->data);
        $source = $detail->data['default_source'];
        $body = ['source' => $source, 'expected_revision' => 0];
        $first = $fixture->call('PUT', $path . '/draft', $fixture->manager(), $body, idempotencyKey: 'case-draft');
        $again = $fixture->call('PUT', $canonical . '/draft', $fixture->manager(), $body, idempotencyKey: 'case-draft');
        self::assertSame(200, $first->status);
        self::assertSame($first->data, $again->data);
        self::assertSame('true', $again->headers['Idempotent-Replayed']);
        $sent = $fixture->call(
            'POST',
            $path . '/test-send',
            $fixture->manager(),
            ['source' => $source],
            idempotencyKey: 'case-send',
        );
        $replayed = $fixture->call(
            'POST',
            $canonical . '/test-send',
            $fixture->manager(),
            ['source' => $source],
            idempotencyKey: 'case-send',
        );
        self::assertSame(200, $sent->status);
        self::assertSame($sent->data, $replayed->data);
        self::assertSame('true', $replayed->headers['Idempotent-Replayed']);
        self::assertCount(1, $fixture->env->mailer->sent);
    }

    public function testAllStudioRoutesRefuseGuestsAndTeachers(): void
    {
        foreach ([$this->fixture->teacher(), Principal::guest()] as $principal) {
            foreach (
                [
                ['GET', ''], ['PUT', '/draft'], ['DELETE', '/draft'], ['POST', '/preview'], ['POST', '/publish'],
                ['POST', '/test-send'],
                ] as [$method, $suffix]
            ) {
                $response = $this->fixture->call($method, self::PATH . $suffix, $principal);
                self::assertSame($principal->isGuest() ? 401 : 403, $response->status, $method . $suffix);
            }
        }
    }

    public function testMalformedIdentityAndUnknownInputProduceFieldErrorsWithoutWrites(): void
    {
        $fixture = $this->fixture;
        $manager = $fixture->manager();
        self::assertSame(404, $fixture->call('GET', str_replace(self::KEY, 'not.registered', self::PATH), $manager)->status);
        self::assertSame(422, $fixture->call('GET', str_replace('/fr/', '/invalid_locale/', self::PATH), $manager)->status);
        self::assertSame(422, $fixture->call('GET', str_replace('/email', '/missing-channel', self::PATH), $manager)->status);
        self::assertSame(422, $fixture->call('POST', self::PATH . '/publish', $manager, ['expected_revision' => '0'])->status);
        self::assertSame(422, $fixture->call('DELETE', self::PATH . '/draft', $manager)->status);
        $detail = $fixture->call('GET', self::PATH, $manager);
        self::assertNotNull($detail->data);
        $invalid = $fixture->call('POST', self::PATH . '/preview', $manager, [
            'source' => $detail->data['default_source'], 'recipient_email' => 'someone@example.test',
        ]);
        self::assertSame(422, $invalid->status);
        self::assertNull($fixture->kernel->notificationTemplates->find(self::KEY, 'fr', 'email'));
    }

    public function testUnsavedPreviewDoesNotTouchRowsOrQueue(): void
    {
        $fixture = $this->fixture;
        $detail = $fixture->call('GET', self::PATH, $fixture->manager());
        self::assertNotNull($detail->data);
        $templates = $fixture->env->database->selectAll(Query::from('notification_templates'));
        $queue = $fixture->env->database->selectAll(Query::from('notification_queue'));
        $preview = $fixture->call('POST', self::PATH . '/preview', $fixture->manager(), ['source' => $detail->data['default_source']]);
        self::assertSame(200, $preview->status);
        self::assertNotNull($preview->data);
        self::assertTrue($preview->data['sample_data']);
        self::assertSame([], $preview->data['unresolved_tokens']);
        self::assertSame($templates, $fixture->env->database->selectAll(Query::from('notification_templates')));
        self::assertSame($queue, $fixture->env->database->selectAll(Query::from('notification_queue')));
    }

    public function testSelfTestRequiresReplayKeyAndRefusesRecipientOverrides(): void
    {
        $fixture = $this->fixture;
        $detail = $fixture->call('GET', self::PATH, $fixture->manager());
        self::assertNotNull($detail->data);
        $body = ['source' => $detail->data['default_source']];
        $noKey = $fixture->call('POST', self::PATH . '/test-send', $fixture->manager(), $body);
        self::assertSame(422, $noKey->status);
        $override = $fixture->call(
            'POST',
            self::PATH . '/test-send',
            $fixture->manager(),
            $body + ['recipient_email' => 'somebody@example.test'],
            idempotencyKey: 'test-override',
        );
        self::assertSame(422, $override->status);
        self::assertSame([], $fixture->env->mailer->sent);
    }

    public function testSelfTestReplaySendsOnceAndLeavesTheProductionQueueAlone(): void
    {
        $fixture = $this->fixture;
        $detail = $fixture->call('GET', self::PATH, $fixture->manager());
        self::assertNotNull($detail->data);
        $body = ['source' => $detail->data['default_source']];
        $before = $fixture->env->database->selectAll(Query::from('notification_queue'));
        $first = $fixture->call('POST', self::PATH . '/test-send', $fixture->manager(), $body, idempotencyKey: 'test-replay');
        $again = $fixture->call('POST', self::PATH . '/test-send', $fixture->manager(), $body, idempotencyKey: 'test-replay');
        self::assertSame(200, $first->status);
        self::assertNotNull($first->data);
        self::assertSame('sent', $first->data['status']);
        self::assertSame('mo@example.test', $first->data['recipient_email']);
        self::assertSame($first->data, $again->data);
        self::assertCount(1, $fixture->env->mailer->sent);
        self::assertSame($before, $fixture->env->database->selectAll(Query::from('notification_queue')));
    }

    public function testUncertainTransportFailureIsReplayedWithoutRetryingTheSend(): void
    {
        $fixture = $this->fixture;
        $fixture->env->mailer->failWith = new \RuntimeException('Timeout after hand-off');
        $detail = $fixture->call('GET', self::PATH, $fixture->manager());
        self::assertNotNull($detail->data);
        $body = ['source' => $detail->data['default_source']];
        $first = $fixture->call('POST', self::PATH . '/test-send', $fixture->manager(), $body, idempotencyKey: 'failed-test');
        self::assertSame(200, $first->status);
        self::assertNotNull($first->data);
        self::assertSame('failed', $first->data['status']);
        $fixture->env->mailer->failWith = null;
        $again = $fixture->call('POST', self::PATH . '/test-send', $fixture->manager(), $body, idempotencyKey: 'failed-test');
        self::assertSame($first->data, $again->data);
        self::assertSame([], $fixture->env->mailer->sent);
        self::assertSame('true', $again->headers['Idempotent-Replayed']);
    }

    public function testTheUnsafeWholeMessageWriteRouteIsRetired(): void
    {
        $response = $this->fixture->call('PUT', '/manage/notifications/templates', $this->fixture->manager(), [
            'template_key' => self::KEY, 'locale' => 'en-GB', 'channel_key' => 'email',
            'body_html' => '<script>bad()</script>', 'state' => 'published',
        ]);
        self::assertSame(405, $response->status);
    }
}
