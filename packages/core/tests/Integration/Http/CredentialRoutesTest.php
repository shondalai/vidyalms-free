<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The credential half of the contract over the real routed kernel.
 */
final class CredentialRoutesTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = ManagementFixture::create();
    }

    public function testAnUnknownCredentialIsANotFoundRatherThanACalmEmptyAnswer(): void
    {
        $response = $this->fixture->call('GET', '/credentials/00000000-0000-4000-8000-999999999999', Principal::guest());

        // A uuid is not a short code somebody retypes, so nothing is probing
        // this key space by hand and a wallet dereferencing a stale id needs
        // to know it is stale.
        self::assertSame(404, $response->status);
    }

    public function testACredentialIsServedUnderContentNegotiation(): void
    {
        $uuid = $this->issuedCredentialUuid();

        $json = $this->fixture->api->handle(new ApiRequest(
            'GET',
            '/credentials/' . $uuid,
            Principal::guest(),
            headers: ['accept' => 'application/ld+json'],
        ));

        self::assertSame(200, $json->status);
        self::assertSame('application/ld+json', $json->headers['Content-Type']);
        self::assertSame('private, no-store', $json->headers['Cache-Control']);
        // Core middleware, not the handler: a security control written once
        // per adapter is a security control written differently twice.
        self::assertSame('noindex, nofollow, noarchive', $json->headers['X-Robots-Tag']);

        $png = $this->fixture->api->handle(new ApiRequest(
            'GET',
            '/credentials/' . $uuid,
            Principal::guest(),
            headers: ['accept' => 'image/png'],
        ));

        self::assertSame(200, $png->status);
        self::assertSame('image/png', $png->headers['Content-Type']);
    }

    public function testAFormatThisBuildCannotSignIsA406NamingWhatItCanServe(): void
    {
        $uuid = $this->issuedCredentialUuid();

        $response = $this->fixture->api->handle(new ApiRequest(
            'GET',
            '/credentials/' . $uuid,
            Principal::guest(),
            headers: ['accept' => 'application/vc+jwt'],
        ));

        // A wallet asking a free site for a signed credential is told the
        // site cannot sign, rather than handed an unsigned document that
        // fails its verification.
        self::assertSame(406, $response->status);
        self::assertNotNull($response->data);
        self::assertContains('application/ld+json', $response->data['servable']);
    }

    public function testAStatusListIs404UntilABuildPublishesOne(): void
    {
        $this->issuedCredentialUuid();

        $listUuid = (string) self::row($this->fixture->env->database->selectOne(
            Query::from('credential_status_lists')->where('purpose', '=', 'revocation'),
        ))['list_uuid'];

        $response = $this->fixture->call('GET', '/credentials/status/' . $listUuid, Principal::guest());

        // The free build allocates indexes without publishing. An all-zero
        // bitstring would tell every verifier that every credential on it is
        // valid, which would be a lie about revocations the site recorded.
        self::assertSame(404, $response->status);
    }

    public function testTheVerificationPayloadCarriesTheStatusAndBothBooleans(): void
    {
        $code = $this->issuedCertificate()->code;

        $response = $this->fixture->call('GET', '/certificates/' . $code . '/verify', Principal::guest());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('active', $response->data['status']);
        self::assertTrue($response->data['valid']);
        self::assertFalse($response->data['revoked']);
        self::assertFalse($response->data['expired']);
        self::assertArrayHasKey('credits', $response->data);
        self::assertArrayHasKey('issuer', $response->data);

        // Never an email, a user id, an enrolment id or the internal
        // certificate id.
        foreach (['learner_id', 'user_id', 'enrolment_id', 'id', 'email'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $response->data);
        }
    }

    public function testTheLearnerCertificateShapeIsTheOneTheContractDeclares(): void
    {
        $this->issuedCertificate();

        $response = $this->fixture->call('GET', '/me/certificates', Principal::user(ManagementFixture::LEARNER_ID));

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertCount(1, $response->data['items']);

        // Every property the contract marks required, in the contract's own
        // order. A field the contract does not declare is a field a client
        // cannot rely on, and one it declares and the server omits is worse.
        self::assertSame([
            'id',
            'course_id',
            'enrolment_id',
            'code',
            'code_display',
            'uuid',
            'status',
            'issued_at',
            'completed_at',
            'valid_from',
            'expires_at',
            'revoked_at',
            'supersedes_id',
            'superseded_by_id',
            'published',
            'credential_url',
            'credits',
        ], array_keys($response->data['items'][0]));

        self::assertSame('active', $response->data['items'][0]['status']);
        self::assertStringContainsString('-', $response->data['items'][0]['code_display']);
    }

    public function testSuspendAndReinstateAreBesideRevoke(): void
    {
        $certificate = $this->issuedCertificate();
        $manager = $this->fixture->manager();

        $suspended = $this->fixture->call(
            'POST',
            '/admin/certificates/' . $certificate->id . '/suspend',
            $manager,
            ['reason' => 'payment_dispute'],
        );
        self::assertSame(200, $suspended->status);
        self::assertNotNull($suspended->data);
        self::assertSame('suspended', $suspended->data['status']);

        $reinstated = $this->fixture->call(
            'POST',
            '/admin/certificates/' . $certificate->id . '/reinstate',
            $manager,
        );
        self::assertSame(200, $reinstated->status);
        self::assertNotNull($reinstated->data);
        self::assertSame('active', $reinstated->data['status']);
    }

    public function testAReissueAnswers201WithTheCarryForwardSetStated(): void
    {
        $certificate = $this->issuedCertificate();

        $response = $this->fixture->call(
            'POST',
            '/admin/certificates/' . $certificate->id . '/reissue',
            $this->fixture->manager(),
        );

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);
        self::assertSame($certificate->id, $response->data['supersedes_id']);
        self::assertArrayHasKey('carried_forward', $response->data);
        self::assertNotSame($certificate->code, $response->data['code']);
    }

    public function testABulkReissueAnswers202WithBothKeysAndThePredicate(): void
    {
        $this->issuedCertificate();
        $templateId = (int) self::row($this->fixture->env->database->selectOne(
            Query::from('certificate_templates'),
        ))['id'];

        $response = $this->fixture->call(
            'POST',
            '/manage/certificate-templates/' . $templateId . '/reissue',
            $this->fixture->manager(),
            ['revision' => 1],
            idempotencyKey: 'bulk-reissue',
        );

        self::assertSame(202, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('reissue:template:' . $templateId . ':1', $response->data['dedupe_key']);
        self::assertStringContainsString('reissue:certificate:{certificateId}:1', $response->data['certificate_dedupe_key_pattern']);
        self::assertStringContainsString("status = 'active'", $response->data['selection']);
    }

    public function testTheElementPaletteIsEnumeratedFromTheRegistry(): void
    {
        $this->issuedCertificate();
        $templateId = (int) self::row($this->fixture->env->database->selectOne(
            Query::from('certificate_templates'),
        ))['id'];

        $response = $this->fixture->call(
            'GET',
            '/manage/certificate-templates/' . $templateId . '/element-types',
            $this->fixture->manager(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertCount(18, $response->data['items']);

        $first = $response->data['items'][0];
        self::assertArrayHasKey('groups', $first);
        self::assertArrayHasKey('current_version', $first);
        self::assertArrayHasKey('supported_payload_versions', $first);
        self::assertArrayHasKey('available', $first);

        // The token vocabulary travels with the palette, because the
        // autocomplete and the save-time check read the same registry.
        self::assertNotSame([], $response->data['tokens']);

        foreach ($response->data['tokens'] as $source) {
            self::assertTrue($source['available']);
            self::assertIsArray($source['fields']);
            self::assertTrue(array_is_list($source['fields']));

            foreach ($source['fields'] as $field) {
                self::assertIsString($field['key']);
                self::assertIsString($field['label']);
                self::assertNotSame('user.email', $source['key'] . '.' . $field['key']);
            }
        }

        $byKey = array_column($response->data['items'], null, 'key');
        $textFields = array_column($byKey['text']['groups'][0]['fields'], null, 'key');
        self::assertSame('string', $textFields['text']['type']);
        self::assertSame('', $textFields['text']['default']);
        self::assertTrue(array_is_list($textFields['font']['fields']));
        $font = array_column($textFields['font']['fields'], null, 'key');
        self::assertSame(18, $font['size']['default']);
        self::assertSame(4, $font['size']['minimum']);
        $border = array_column($byKey['border']['groups'][0]['fields'], null, 'key');
        self::assertSame('enum', $border['style']['type']);
        self::assertSame('solid', $border['style']['options'][0]['key']);

        // The capability list is a declared map rather than a chain of
        // branches, so a capability added later reaches the palette without a
        // core edit. This one was added after the palette was written.
        $verification = null;

        foreach ($response->data['items'] as $item) {
            if ($item['key'] === 'verification_code') {
                $verification = $item;
            }
        }

        self::assertNotNull($verification);
        self::assertContains('provides_readable_verification', $verification['capabilities']);
    }

    public function testTheElementPaletteRefusesAGuest(): void
    {
        $this->issuedCertificate();
        $templateId = (int) self::row($this->fixture->env->database->selectOne(
            Query::from('certificate_templates'),
        ))['id'];

        // It reads repositories straight from the handler, so there is no use
        // case behind it to carry the check. Unguarded it published the whole
        // element catalogue, the token vocabulary and which paid packages are
        // installed to anybody, and its 404 made it an existence oracle for
        // template ids.
        $guest = $this->fixture->call(
            'GET',
            '/manage/certificate-templates/' . $templateId . '/element-types',
            Principal::guest(),
        );

        self::assertSame(401, $guest->status);

        $missing = $this->fixture->call(
            'GET',
            '/manage/certificate-templates/999999/element-types',
            Principal::guest(),
        );

        self::assertSame(401, $missing->status, 'Existence must not leak ahead of the check.');
    }

    public function testAnUnknownCodeVerifiesWithNoStatusRatherThanAsRevoked(): void
    {
        $response = $this->fixture->call('GET', '/certificates/NOTACODE1234/verify', Principal::guest());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertNull($response->data['status'], 'A mistyped code must not read as withdrawn.');
        self::assertSame('not_found', $response->data['reason']);
        self::assertFalse($response->data['revoked']);
    }

    public function testAWithdrawnCertificateIsNotHandedOutAgain(): void
    {
        $certificate = $this->issuedCertificate();
        $manager = $this->fixture->manager();

        $this->fixture->call(
            'POST',
            '/admin/certificates/' . $certificate->id . '/revoke',
            $manager,
            ['reason' => 'issued in error'],
        );

        $response = $this->fixture->call(
            'GET',
            '/me/certificates/' . $certificate->id . '/download',
            Principal::user(ManagementFixture::LEARNER_ID),
        );

        self::assertSame(409, $response->status);
        self::assertSame('revoked', $response->data['credential_status'] ?? null);
    }

    public function testCourseCreditsRoundTripThroughTheSchemeValidation(): void
    {
        $manager = $this->fixture->manager();
        $path = '/manage/courses/' . $this->fixture->courseId . '/credits';

        $refused = $this->fixture->call('PUT', $path, $manager, [
            'items' => [['scheme' => 'contact_hours', 'params' => []]],
        ]);
        self::assertSame(422, $refused->status);

        $saved = $this->fixture->call('PUT', $path, $manager, [
            'items' => [[
                'scheme' => 'contact_hours',
                'params' => ['contact_minutes' => 360, 'field_of_study' => 'Textiles'],
            ]],
        ]);

        self::assertSame(200, $saved->status);
        self::assertNotNull($saved->data);
        self::assertCount(1, $saved->data['items']);
        self::assertSame('6.00', $saved->data['items'][0]['derived']['amount']);
        self::assertSame('hours', $saved->data['items'][0]['derived']['unit']);
        self::assertTrue($saved->data['items'][0]['available']);

        $read = $this->fixture->call('GET', $path, $manager);
        self::assertSame(200, $read->status);
        self::assertNotNull($read->data);
        self::assertCount(1, $read->data['items']);
    }

    public function testTheCreditPreviewPreservesAnExplicitlyAuthoredAmount(): void
    {
        $path = '/manage/courses/' . $this->fixture->courseId . '/credits';
        $response = $this->fixture->call('PUT', $path, $this->fixture->manager(), [
            'items' => [['scheme' => 'contact_hours', 'params' => ['contact_minutes' => 60, 'amount' => '2.00']]],
        ]);
        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('2.00', $response->data['items'][0]['derived']['amount']);
    }

    public function testTheCreditSchemeListIsTheRegistrysAnswer(): void
    {
        $response = $this->fixture->call('GET', '/manage/credit-schemes', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['contact_hours'], array_column($response->data['items'], 'key'));
        self::assertSame([[]], array_column($response->data['items'], 'required_learner_fields'));
        $scheme = $response->data['items'][0];
        self::assertTrue($scheme['available']);
        self::assertSame('free', $scheme['edition']);
        self::assertSame('contact_minutes', $scheme['groups'][0]['fields'][0]['key']);
        self::assertSame('integer', $scheme['groups'][0]['fields'][0]['type']);
        self::assertSame(60, $scheme['groups'][0]['fields'][0]['default']);
    }

    public function testADownloadIs409UntilTheArtefactExists(): void
    {
        $certificate = $this->issuedCertificate();
        $learner = Principal::user(ManagementFixture::LEARNER_ID);

        $response = $this->fixture->call(
            'GET',
            '/me/certificates/' . $certificate->id . '/download',
            $learner,
        );

        // The certificate exists and the artefact does not: telling a learner
        // their certificate does not exist while its render waits in a queue
        // is the wrong answer to the right question.
        self::assertSame(409, $response->status);
        self::assertSame('not_rendered', $response->data['artefact_status'] ?? null);

        $this->fixture->env->database->update(
            'certificates',
            ['artefact_path' => 'certificates/1.html', 'artefact_checksum' => 'abc'],
            ['id' => $certificate->id],
        );

        $missing = $this->fixture->call('GET', '/me/certificates/' . $certificate->id . '/download', $learner);
        self::assertSame(409, $missing->status);
        self::assertSame('missing', $missing->data['artefact_status'] ?? null);
        $this->fixture->env->storage->putPrivate('certificates/1.html', '<html><body>Certificate</body></html>');

        $ready = $this->fixture->call('GET', '/me/certificates/' . $certificate->id . '/download', $learner);
        self::assertSame(200, $ready->status);
        self::assertNotNull($ready->data);
        self::assertStringContainsString('certificates/1.html', $ready->data['url']);
        self::assertSame('text/html', $ready->data['media_type']);
        self::assertSame('1.html', $ready->data['filename']);
        self::assertSame('2026-01-15 10:05:00', $ready->data['expires_at']);
        self::assertSame(300, $ready->data['expires_in']);
        self::assertSame('abc', $ready->data['checksum']);
        self::assertSame('private, no-store', $ready->headers['Cache-Control']);
    }

    public function testDownloadMetadataUsesTheStoredArtifactAndNeverTheCurrentRenderer(): void
    {
        $certificate = $this->issuedCertificate();
        $route = '/me/certificates/' . $certificate->id . '/download';
        $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'json' => 'application/json', 'bin' => 'application/octet-stream'];
        foreach ($types as $extension => $type) {
            $path = 'certificates/' . $certificate->id . '.' . $extension;
            $this->fixture->env->storage->putPrivate($path, $extension === 'pdf' ? '%PDF-1.7' : 'test artifact');
            $this->fixture->env->database->update('certificates', [
                'artefact_path' => $path, 'artefact_checksum' => null, 'renderer_key' => 'html',
            ], ['id' => $certificate->id]);
            $response = $this->fixture->call('GET', $route, $this->fixture->learner());
            self::assertSame(200, $response->status);
            self::assertIsArray($response->data);
            self::assertSame($type, $response->data['media_type']);
            self::assertSame(basename($path), $response->data['filename']);
            self::assertNull($response->data['checksum']);
            self::assertArrayNotHasKey('artefact_path', $response->data);
            self::assertSame(200, $this->fixture->call('GET', $route, $this->fixture->manager())->status);
            self::assertSame(404, $this->fixture->call('GET', $route, $this->fixture->teacher())->status);
            self::assertSame(401, $this->fixture->call('GET', $route, Principal::guest())->status);
        }
        foreach (['../secrets.txt', 'https://example.test/1.pdf', 'certificates/../secret.pdf', "certificates/name\r\n.pdf"] as $path) {
            $this->fixture->env->database->update('certificates', ['artefact_path' => $path], ['id' => $certificate->id]);
            $response = $this->fixture->call('GET', $route, $this->fixture->learner());
            self::assertSame(409, $response->status);
            self::assertSame('invalid_path', $response->data['artefact_status'] ?? null);
            self::assertArrayNotHasKey('url', $response->data ?? []);
        }
        $this->fixture->env->database->update('certificates', [
            'status' => 'superseded', 'superseded_by_id' => $certificate->id + 1,
        ], ['id' => $certificate->id]);
        $replaced = $this->fixture->call('GET', $route, $this->fixture->learner());
        self::assertSame(409, $replaced->status);
        self::assertSame('superseded', $replaced->data['credential_status'] ?? null);
        self::assertArrayNotHasKey('artefact_status', $replaced->data ?? []);
    }

    public function testADownloadIsNotVisibleToAStranger(): void
    {
        $certificate = $this->issuedCertificate();

        $response = $this->fixture->call(
            'GET',
            '/me/certificates/' . $certificate->id . '/download',
            Principal::user(9999),
        );

        // Not found rather than forbidden: whether somebody else's
        // certificate exists is not a fact this route discloses.
        self::assertSame(404, $response->status);
    }

    public function testAnAdministratorCanNameTheIssuerThroughTheSettingsRoute(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/admin/settings',
            $this->fixture->manager(),
            ['issuer' => ['name' => 'Northlight', 'url' => 'https://northlight.example', 'stray' => 'ignored']],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);

        $issuer = $response->data['issuer'];
        self::assertIsArray($issuer);
        self::assertSame('Northlight', $issuer['name']);
        self::assertSame('https://northlight.example', $issuer['resolved']['id']);
        self::assertArrayNotHasKey('stray', $issuer);
    }

    public function testTheSettingsWriteRefusesACallerWithoutManage(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/admin/settings',
            Principal::guest(),
            ['issuer' => ['name' => 'Northlight']],
        );

        self::assertContains($response->status, [401, 403]);
    }

    public function testTheAdminCertificateRecordIsFlatAsTheContractDeclares(): void
    {
        $certificate = $this->issuedCertificate();

        // A reported credit row, which is the member that carried a second
        // datetime format and is invisible until a paid export stamps it.
        $this->fixture->env->database->insert('certificate_credits', [
            'certificate_id' => $certificate->id,
            'scheme' => 'contact_hours',
            'amount' => '6.00',
            'unit' => 'hours',
            'contact_minutes' => 360,
            'reported_at' => '2026-03-01 08:00:00',
            'params_version' => 1,
            'created_at' => '2026-01-15 10:00:00',
            'updated_at' => '2026-01-15 10:00:00',
        ]);

        $response = $this->fixture->call(
            'GET',
            '/admin/certificates/' . $certificate->id,
            $this->fixture->manager(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);

        // Nested under provenance, artefact and credential these type-checked
        // on both sides and matched on neither, so the record dialog read
        // undefined for every one of them and reported "no snapshot" for a
        // certificate that demonstrably had one.
        $required = [
            'snapshot_present',
            'renderer_key',
            'engine_version',
            'artefact_available',
            'artefact_checksum',
            'rendered_at',
            'credential_format',
            'proof_type',
            'status_entries',
        ];

        foreach ($required as $member) {
            self::assertArrayHasKey($member, $response->data, $member . ' is required by CertificateDetail');
        }

        self::assertArrayNotHasKey('provenance', $response->data);
        self::assertArrayNotHasKey('artefact', $response->data);
        self::assertArrayNotHasKey('credential', $response->data);
        self::assertTrue($response->data['snapshot_present']);
        self::assertFalse($response->data['artefact_available']);

        // One response, one datetime format. The credit rows carried RFC 3339
        // while every other date on the same object carried the contract's
        // own format, and both sides type-checked because the SDK types a
        // date as a string, so only a live call finds it.
        foreach (['issued_at', 'completed_at', 'expires_at', 'valid_from', 'revoked_at', 'rendered_at'] as $member) {
            self::assertMatchesContractDateTime($response->data[$member] ?? null, $member);
        }

        $credits = $response->data['credits'] ?? [];
        self::assertIsArray($credits);
        self::assertNotSame([], $credits, 'the fixture course carries credit, so the child rows are on the record');

        foreach ($credits as $credit) {
            self::assertIsArray($credit);
            self::assertMatchesContractDateTime($credit['reported_at'] ?? null, 'credits[].reported_at');
        }
    }

    private static function assertMatchesContractDateTime(mixed $value, string $member): void
    {
        if ($value === null) {
            return;
        }

        self::assertIsString($value, $member);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value, $member);
    }

    public function testAStatusEntryIsTheShapeTheContractDeclares(): void
    {
        $certificate = $this->issuedCertificate();

        $response = $this->fixture->call(
            'GET',
            '/admin/certificates/' . $certificate->id,
            $this->fixture->manager(),
        );

        self::assertNotNull($response->data);
        $entries = $response->data['status_entries'];
        self::assertIsArray($entries);
        self::assertNotSame([], $entries);

        foreach ($entries as $entry) {
            self::assertSame(
                ['purpose', 'list_id', 'list_index', 'status_list_url'],
                array_keys($entry),
            );
        }
    }

    public function testThePublicVerificationPayloadCarriesRfc3339Instants(): void
    {
        $code = $this->issuedCertificate()->code;

        $response = $this->fixture->call('GET', '/certificates/' . $code . '/verify', Principal::guest());

        self::assertNotNull($response->data);

        // This is the one endpoint a relying party parses without our SDK,
        // and the credential document about the same subject already uses
        // RFC 3339, so one credential was described two ways.
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) $response->data['issued_at'],
        );
    }

    public function testTheVerificationIssuerIsAnObjectOrNullAndNeverAnEmptyList(): void
    {
        $code = $this->issuedCertificate()->code;

        $response = $this->fixture->call('GET', '/certificates/' . $code . '/verify', Principal::guest());

        self::assertNotNull($response->data);
        $issuer = $response->data['issuer'];

        // json_encode writes [] for an empty PHP array, so an unconfigured
        // site answered "issuer": [] and contradicted its own schema, which
        // declares an object with name and url required, or null.
        self::assertTrue($issuer === null || \is_array($issuer));

        if (\is_array($issuer)) {
            self::assertSame(['name', 'url'], array_keys($issuer));
            self::assertNotSame('', $issuer['name']);
        }
    }

    private function issuedCertificate(): \Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate
    {
        $enrolmentId = (int) self::row($this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('user_id', '=', ManagementFixture::LEARNER_ID)
                ->where('course_id', '=', $this->fixture->courseId),
        ))['id'];

        $this->fixture->kernel->issueCertificate->execute(
            $enrolmentId,
            ManagementFixture::LEARNER_ID,
            $this->fixture->courseId,
        );

        $certificate = $this->fixture->kernel->certificateRepository->findActiveByEnrolmentId($enrolmentId);

        self::assertNotNull($certificate);

        return $certificate;
    }

    public function testHostedAssertionPinsTheFormatAndReflectsSuspensionReinstatementAndRevocation(): void
    {
        $certificate = $this->issuedCertificate();
        $request = new ApiRequest(
            'GET',
            '/credentials/' . $certificate->uuid,
            Principal::guest(),
            query: ['format' => 'openbadges2'],
            headers: ['accept' => 'application/ld+json, application/json;q=0.9, */*;q=0.8']
        );
        $active = $this->fixture->api->handle($request);
        self::assertSame(200, $active->status);
        self::assertSame('application/json', $active->headers['Content-Type']);
        $body = json_decode($active->binaryBytes(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Assertion', $body['type']);
        self::assertStringEndsWith('?format=openbadges2', $body['id']);

        $this->fixture->kernel->suspendCertificate->execute($this->fixture->manager(), $certificate->id, 'Private investigation');
        $suspended = $this->fixture->api->handle($request);
        self::assertSame(409, $suspended->status);
        self::assertNotNull($suspended->data);
        self::assertSame('suspended', $suspended->data['credential_status']);
        self::assertArrayNotHasKey('revoked', $suspended->data);
        $this->fixture->kernel->suspendCertificate->reinstate($this->fixture->manager(), $certificate->id);
        self::assertSame(200, $this->fixture->api->handle($request)->status);

        $this->fixture->kernel->revokeCertificate->execute($this->fixture->manager(), $certificate->id, 'Private personnel detail');
        $revoked = $this->fixture->api->handle($request);
        self::assertSame(200, $revoked->status);
        $body = json_decode($revoked->binaryBytes(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['revoked']);
        self::assertArrayNotHasKey('revocationReason', $body);
        self::assertStringNotContainsString('Private personnel detail', $revoked->binaryBytes());
    }

    public function testSiteAndHolderPublicationPreferencesControlAnAlreadyIssuedCertificate(): void
    {
        $certificate = $this->issuedCertificate();
        $verify = '/certificates/' . $certificate->code . '/verify';
        self::assertNull($this->fixture->call('GET', $verify, Principal::guest())->data['learner_name'] ?? null);
        $enabled = $this->fixture->call('PUT', '/admin/settings', $this->fixture->manager(), ['publish_learner_name' => true]);
        self::assertSame(200, $enabled->status);
        self::assertTrue($enabled->data['publish_learner_name'] ?? false);
        self::assertSame('Lena Learner', $this->fixture->call('GET', $verify, Principal::guest())->data['learner_name'] ?? null);
        $preference = '/me/credentials/publication';
        self::assertSame(401, $this->fixture->call('PUT', $preference, Principal::guest(), ['publish_learner_name' => false])->status);
        self::assertSame(400, $this->fixture->call('PUT', $preference, Principal::user(ManagementFixture::LEARNER_ID), [
            'publish_learner_name' => false, 'user_id' => ManagementFixture::MANAGER_ID,
        ])->status);
        $hidden = $this->fixture->call('PUT', $preference, Principal::user(ManagementFixture::LEARNER_ID), [
            'publish_learner_name' => false,
        ]);
        self::assertSame(200, $hidden->status);
        self::assertNull($this->fixture->call('GET', $verify, Principal::guest())->data['learner_name'] ?? null);
        $this->fixture->call('PUT', $preference, Principal::user(ManagementFixture::LEARNER_ID), ['publish_learner_name' => true]);
        $disabled = $this->fixture->call('PUT', '/admin/settings', $this->fixture->manager(), ['publish_learner_name' => false]);
        self::assertSame(200, $disabled->status);
        self::assertNull($this->fixture->call('GET', $verify, Principal::guest())->data['learner_name'] ?? null);
    }

    public function testUnsignedFrozenCredentialHasNoDanglingStatusListsAndCannotOutliveItsStatus(): void
    {
        $certificate = $this->issuedCertificate();
        $path = '/credentials/' . $certificate->uuid;
        $active = $this->fixture->call('GET', $path, Principal::guest());
        self::assertSame(200, $active->status);
        $body = json_decode($active->binaryBytes(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('credentialStatus', $body);
        foreach (['suspended', 'revoked', 'superseded'] as $status) {
            $this->fixture->env->database->update('certificates', ['status' => $status], ['id' => $certificate->id]);
            $response = $this->fixture->call('GET', $path, Principal::guest());
            self::assertSame(409, $response->status);
            self::assertNotNull($response->data);
            self::assertSame($status, $response->data['credential_status']);
            self::assertSame('private, no-store', $response->headers['Cache-Control']);
        }
    }

    private function issuedCredentialUuid(): string
    {
        $uuid = $this->issuedCertificate()->uuid;
        self::assertNotNull($uuid);

        return $uuid;
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>
     */
    private static function row(?array $row): array
    {
        if ($row === null) {
            self::fail('Expected a database row, found none.');
        }

        return $row;
    }
}
