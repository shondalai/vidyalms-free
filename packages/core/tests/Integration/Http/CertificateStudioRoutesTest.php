<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateStudio;
use Shondalai\VidyaLMS\Core\Application\Credentials\TemplateSnapshot;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Tests\Support\CountingDatabase;

final class CertificateStudioRoutesTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testBlueprintCatalogueIsAuthorizedDerivedAndClonesAnEditableGraph(): void
    {
        self::assertSame(401, $this->fixture->call('GET', '/manage/certificate-blueprints', Principal::guest())->status);
        self::assertSame(403, $this->fixture->call('GET', '/manage/certificate-blueprints', $this->fixture->teacher())->status);
        $catalogue = $this->call('GET', '/manage/certificate-blueprints');
        self::assertSame(200, $catalogue->status);
        self::assertCount(9, self::data($catalogue)['items']);
        self::assertStringContainsString('vy-certificate-page', self::data($catalogue)['items'][0]['preview_html']);
        $draft = $this->blueprint();
        self::assertSame('draft', $draft['state']);
        self::assertFalse($draft['is_default']);
        self::assertNull($draft['published_revision']);
        self::assertSame(1, $draft['revision']);
        self::assertCount(1, $draft['pages']);
        self::assertGreaterThanOrEqual(10, count($draft['pages'][0]['elements']));
        self::assertSame(self::data($catalogue)['items'][0]['source_digest'], $draft['provenance']['source_digest']);
    }

    public function testLibraryArtworkIsBatchedAndReflectsTheCurrentEditedSource(): void
    {
        $drafts = [];
        foreach (['prototype_classic', 'prototype_modern', 'prototype_executive'] as $slug) {
            $response = $this->call('POST', '/manage/certificate-blueprints/vidyalms.certificate.' . $slug . '/clone', [
                'name' => $slug, 'version' => 1,
            ]);
            self::assertSame(201, $response->status);
            $drafts[] = self::data($response);
        }
        $counting = new CountingDatabase($this->fixture->env->database);
        $repository = new SqlCertificateTemplateRepository($counting, $this->fixture->env->clock);
        $pages = $repository->firstPagesFor(array_column($drafts, 'id'));
        self::assertCount(3, $pages);
        self::assertSame(2, $counting->reads);
        self::assertSame([], $repository->firstPagesFor([]));
        self::assertSame(2, $counting->reads);

        $draft = $drafts[2];
        $items = self::data($this->call('GET', '/manage/certificate-templates', [], ['search' => 'prototype_executive']))['items'];
        self::assertCount(1, $items);
        self::assertStringContainsString('background-color: #202c3b', $items[0]['thumbnail']['html']);
        self::assertStringContainsString('Olivia Rhye', $items[0]['thumbnail']['html']);
        $background = $draft['pages'][0]['elements'][0];
        $response = $this->call('PATCH', '/manage/certificate-template-elements/' . $background['id'], [
            'expected_revision' => 1, 'data_version' => 1,
            'data' => ['colour' => '#112233', 'rule_width_pt' => null],
        ]);
        self::assertSame(200, $response->status);
        $items = self::data($this->call('GET', '/manage/certificate-templates', [], ['search' => 'prototype_executive']))['items'];
        self::assertStringContainsString('background-color: #112233', $items[0]['thumbnail']['html']);
        self::assertStringNotContainsString('background-color: #202c3b', $items[0]['thumbnail']['html']);
        self::assertSame(2, $items[0]['revision']);
    }

    public function testPublicationIsExplicitAtomicAndRetryStable(): void
    {
        $draft = $this->blueprint();
        $id = $draft['id'];
        self::assertSame(422, $this->call('PATCH', "/manage/certificate-templates/$id", [
            'expected_revision' => 1, 'state' => 'published',
        ])->status);
        $body = ['expected_revision' => 1];
        $published = $this->fixture->call(
            'POST',
            "/manage/certificate-templates/$id/publish",
            $this->fixture->manager(),
            $body,
            idempotencyKey: 'publish-once',
        );
        self::assertSame(200, $published->status, json_encode(self::data($published), JSON_THROW_ON_ERROR));
        self::assertSame(2, self::data($published)['published_revision']);
        self::assertFalse(self::data($published)['has_unpublished_changes']);
        $replay = $this->fixture->call(
            'POST',
            "/manage/certificate-templates/$id/publish",
            $this->fixture->manager(),
            $body,
            idempotencyKey: 'publish-once',
        );
        self::assertSame(self::data($published), self::data($replay));
        self::assertSame(409, $this->call('POST', "/manage/certificate-templates/$id/publish", $body)->status);
        $stored = $this->fixture->kernel->certificateTemplateRepository->findById($id);
        self::assertNotNull($stored);
        self::assertSame(2, (int) $stored['revision']);
    }

    public function testMissingKeysAndChangedInputsAndRevokedAccessCannotReplay(): void
    {
        $draft = $this->blueprint();
        $path = '/manage/certificate-templates/' . $draft['id'] . '/publish';
        self::assertSame(400, $this->fixture->call('POST', $path, $this->fixture->manager(), ['expected_revision' => 1])->status);
        self::assertSame(
            200,
            $this->fixture->call('POST', $path, $this->fixture->manager(), ['expected_revision' => 1], idempotencyKey: 'publish')->status,
        );
        // Changed input under a spent key is unprocessable, never a conflict.
        self::assertSame(
            422,
            $this->fixture->call('POST', $path, $this->fixture->manager(), ['expected_revision' => 2], idempotencyKey: 'publish')->status,
        );
        self::assertSame(
            403,
            $this->fixture->call('POST', $path, $this->fixture->teacher(), ['expected_revision' => 1], idempotencyKey: 'publish')->status,
        );
    }

    public function testDraftEditsDoNotReplaceThePublishedDesignAndCloningUsesExactPublishedRevision(): void
    {
        $draft = $this->blueprint();
        $id = $draft['id'];
        $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1]);
        $before = $this->fixture->kernel->certificateStudio->publishedForIssuance($id);
        $edit = $this->call('PATCH', "/manage/certificate-templates/$id", ['expected_revision' => 2, 'name' => 'Unpublished edit']);
        self::assertSame(200, $edit->status);
        self::assertTrue(self::data($edit)['has_unpublished_changes']);
        self::assertSame($before, $this->fixture->kernel->certificateStudio->publishedForIssuance($id));
        $clone = $this->call('POST', "/manage/certificate-templates/$id/clone", ['published_revision' => 2, 'name' => 'Copy']);
        self::assertSame(201, $clone->status, json_encode(self::data($clone), JSON_THROW_ON_ERROR));
        self::assertSame('draft', self::data($clone)['state']);
        self::assertNotSame($id, self::data($clone)['id']);
        self::assertNotSame($draft['pages'][0]['id'], self::data($clone)['pages'][0]['id']);
        self::assertSame(
            422,
            $this->call('PATCH', "/manage/certificate-templates/$id", ['expected_revision' => 3, 'is_default' => true])->status,
        );
        self::assertSame(
            409,
            $this->call('POST', "/manage/certificate-templates/$id/clone", ['published_revision' => 1, 'name' => 'Stale'])->status,
        );
    }

    public function testLegacyPublishedGraphIsAdoptedBeforeTheFirstEdit(): void
    {
        $id = $this->fixture->kernel->ensureDefaultTemplate->execute();
        $original = $this->fixture->kernel->certificateTemplateRepository->findById($id);
        self::assertNotNull($original);
        $response = $this->call('PATCH', "/manage/certificate-templates/$id", ['expected_revision' => 1, 'body' => 'Changed draft']);
        self::assertSame(200, $response->status);
        $stored = $this->fixture->kernel->certificateTemplateRepository->findById($id);
        self::assertNotNull($stored);
        $frozen = CertificateStudio::publishedBody($stored);
        self::assertSame($original['body'], $frozen['body']);
        self::assertSame(1, $frozen['revision']);
        self::assertSame('Changed draft', $stored['body']);
    }

    public function testDefaultSelectionUsesBothObservedIdentitiesAndOneUniqueSlot(): void
    {
        $old = $this->fixture->kernel->ensureDefaultTemplate->execute();
        $draft = $this->blueprint();
        $id = $draft['id'];
        $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1]);
        self::assertSame(409, $this->call('POST', "/manage/certificate-templates/$id/default", [
            'expected_revision' => 2, 'expected_default_id' => null, 'expected_default_revision' => null,
        ])->status);
        $selected = $this->call('POST', "/manage/certificate-templates/$id/default", [
            'expected_revision' => 2, 'expected_default_id' => $old, 'expected_default_revision' => 1,
        ]);
        self::assertSame(200, $selected->status, json_encode(self::data($selected), JSON_THROW_ON_ERROR));
        self::assertTrue(self::data($selected)['is_default']);
        self::assertFalse(self::data($selected)['has_unpublished_changes']);
        self::assertSame(
            1,
            $this->fixture->env->database->count(Query::from('certificate_templates')->where('active_default_slot', '=', 'site')),
        );
        $default = $this->fixture->kernel->certificateTemplateRepository->findPublishedDefault();
        self::assertNotNull($default);
        self::assertSame($id, $default['id']);
        self::assertSame(3, self::data($selected)['default_selection']['revision']);
    }

    public function testEmptyOrIncompatibleSourceCannotPublishAndRollsBackRevision(): void
    {
        $empty = $this->call('POST', '/manage/certificate-templates', ['name' => 'Empty', 'format' => 'elements']);
        $id = self::data($empty)['id'];
        self::assertSame(422, $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1])->status);
        self::assertSame(1, self::data($this->call('GET', "/manage/certificate-templates/$id"))['revision']);
        $draft = $this->blueprint();
        $id = $draft['id'];
        self::assertSame(
            200,
            $this->call('PATCH', "/manage/certificate-templates/$id", ['expected_revision' => 1, 'renderer' => 'html'])->status,
        );
        self::assertSame(422, $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 2])->status);
    }

    public function testCoursePolicyIsOwnedByTheCourseAuthorAndHasAtomicAbsentRowRevision(): void
    {
        $path = '/manage/courses/' . $this->fixture->courseId . '/certificate-policy';
        self::assertSame(401, $this->fixture->call('GET', $path, Principal::guest())->status);
        self::assertSame(403, $this->fixture->call('GET', $path, $this->fixture->learner())->status);
        self::assertSame(403, $this->fixture->call('GET', $path, $this->fixture->outsider())->status);
        self::assertSame(0, self::data($this->fixture->call('GET', $path, $this->fixture->teacher()))['revision']);
        $body = $this->policy(false);
        $saved = $this->fixture->call('PUT', $path, $this->fixture->teacher(), $body, idempotencyKey: 'policy');
        self::assertSame(200, $saved->status, json_encode(self::data($saved), JSON_THROW_ON_ERROR));
        self::assertSame(1, self::data($saved)['revision']);
        self::assertFalse(self::data($saved)['enabled']);
        self::assertSame(
            self::data($saved),
            $this->fixture->call('PUT', $path, $this->fixture->teacher(), $body, idempotencyKey: 'policy')->data,
        );
        self::assertSame(409, $this->fixture->call('PUT', $path, $this->fixture->teacher(), $body, idempotencyKey: 'policy-stale')->status);
        // A missing required header is 400, not 422: the body is fine.
        self::assertSame(400, $this->fixture->call('PUT', $path, $this->fixture->teacher(), $body)->status);
        $outcome = $this->fixture->kernel->issueCertificate->executeOutcome(998, ManagementFixture::LEARNER_ID, $this->fixture->courseId);
        self::assertSame('skipped', $outcome->status);
        self::assertSame('course_policy_disabled', $outcome->reason);
        self::assertNull($outcome->certificate);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('certificates')));
    }

    public function testAssignedPolicyFreezesPublishedSourceAndNeverFallsBackWhenArchived(): void
    {
        $draft = $this->blueprint();
        $id = $draft['id'];
        $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1]);
        $body = $this->policy(true, $id);
        $path = '/manage/courses/' . $this->fixture->courseId . '/certificate-policy';
        $saved = $this->call('PUT', $path, $body);
        self::assertSame(200, $saved->status, json_encode(self::data($saved), JSON_THROW_ON_ERROR));
        $outcome = $this->fixture->kernel->issueCertificate->executeOutcome(999, ManagementFixture::LEARNER_ID, $this->fixture->courseId);
        self::assertSame('issued', $outcome->status);
        $certificate = $outcome->requireCertificate();
        self::assertSame($id, $certificate->templateId);
        self::assertSame(2, $certificate->templateRevision);
        self::assertNotNull($certificate->templateSnapshot);
        self::assertSame(1, TemplateSnapshot::templateOf($certificate->templateSnapshot)['course_policy']['revision']);
        $disabled = $this->policy(false, $id);
        $disabled['expected_revision'] = 1;
        self::assertSame(200, $this->call('PUT', $path, $disabled)->status);
        self::assertSame(
            'existing',
            $this->fixture->kernel->issueCertificate->executeOutcome(999, ManagementFixture::LEARNER_ID, $this->fixture->courseId)->status,
        );
        $enabled = $this->policy(true, $id);
        $enabled['expected_revision'] = 2;
        $this->call('PUT', $path, $enabled);
        self::assertSame(200, $this->call('DELETE', "/manage/certificate-templates/$id", [], ['expected_revision' => '2'])->status);
        self::assertFalse(self::data($this->call('GET', $path))['resolved_template']['available']);
        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException::class);
        $this->fixture->kernel->issueCertificate->executeOutcome(1000, ManagementFixture::LEARNER_ID, $this->fixture->courseId);
    }

    public function testPickerIsBoundedAndDoesNotGrantStudioEditing(): void
    {
        $draft = $this->blueprint();
        $id = $draft['id'];
        $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1]);
        $path = '/manage/courses/' . $this->fixture->courseId . '/certificate-templates';
        $response = $this->fixture->call('GET', $path, $this->fixture->teacher(), query: ['per_page' => '1']);
        self::assertSame(200, $response->status);
        self::assertCount(1, self::data($response)['items']);
        self::assertArrayNotHasKey('body', self::data($response)['items'][0]);
        self::assertSame(422, $this->fixture->call('GET', $path, $this->fixture->teacher(), query: ['per_page' => '1000'])->status);
        self::assertSame(403, $this->fixture->call('GET', '/manage/certificate-templates/' . $id, $this->fixture->teacher())->status);
    }

    public function testAssignmentReadsPublishedMetadataAndNeverAdoptsOnRead(): void
    {
        $legacy = $this->fixture->kernel->ensureDefaultTemplate->execute();
        $path = '/manage/courses/' . $this->fixture->courseId . '/certificate-policy';
        $before = $this->fixture->kernel->certificateTemplateRepository->findById($legacy);
        self::assertSame(200, $this->call('GET', $path)->status);
        self::assertSame(200, $this->call('GET', '/manage/courses/' . $this->fixture->courseId . '/certificate-templates')->status);
        self::assertSame($before, $this->fixture->kernel->certificateTemplateRepository->findById($legacy));
        $draft = $this->blueprint();
        $id = $draft['id'];
        $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1]);
        $this->call('PUT', $path, $this->policy(true, $id));
        $this->call('PATCH', "/manage/certificate-templates/$id", [
            'expected_revision' => 2, 'name' => 'Draft-only name', 'renderer' => 'html',
        ]);
        $read = self::data($this->call('GET', $path));
        self::assertSame('Studio sample', $read['resolved_template']['name']);
        self::assertSame(2, $read['resolved_template']['published_revision']);
        self::assertTrue($read['resolved_template']['available']);
        $picker = self::data($this->call('GET', '/manage/courses/' . $this->fixture->courseId . '/certificate-templates'));
        $assigned = array_values(array_filter($picker['items'], static fn (array $item): bool => $item['id'] === $id));
        self::assertSame('elements', $assigned[0]['renderer']);
        self::assertSame('Studio sample', $assigned[0]['name']);
        self::assertTrue($assigned[0]['available']);
        $pickerPath = '/manage/courses/' . $this->fixture->courseId . '/certificate-templates';
        self::assertSame(1, self::data($this->call('GET', $pickerPath, [], ['search' => 'Studio sample']))['total']);
        self::assertSame(0, self::data($this->call('GET', $pickerPath, [], ['search' => 'Draft-only']))['total']);
        $stored = $this->fixture->kernel->certificateTemplateRepository->findById($id);
        self::assertNotNull($stored);
        $snapshot = CertificateStudio::snapshot($stored);
        $snapshot['renderer'] = 'removed.renderer';
        $this->fixture->env->database->update('certificate_templates', [
            'published_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'published_summary' => json_encode([
                'name' => $snapshot['name'], 'revision' => $snapshot['revision'],
                'renderer' => 'removed.renderer', 'format' => $snapshot['format'],
            ], JSON_THROW_ON_ERROR),
        ], ['id' => $id]);
        $unavailable = self::data($this->call('GET', $path));
        self::assertFalse($unavailable['resolved_template']['available']);
        self::assertSame('certificate_policy.renderer_unavailable', $unavailable['resolved_template']['diagnostic']);
    }

    public function testGallerySearchIsLiteralAndCountsAreGroupedAndBounded(): void
    {
        $draft = $this->blueprint();
        $id = $draft['id'];
        $this->call('PATCH', "/manage/certificate-templates/$id", [
            'expected_revision' => 1, 'name' => '100% success', 'category' => 'leadership',
        ]);
        $result = self::data($this->call('GET', '/manage/certificate-templates', [], [
            'search' => '%', 'category' => 'leadership', 'renderer' => 'elements', 'per_page' => '1',
        ]));
        self::assertSame(1, $result['total']);
        self::assertSame(1, $result['items'][0]['page_count']);
        self::assertSame(count($draft['pages'][0]['elements']), $result['items'][0]['element_count']);
        self::assertSame(0, self::data($this->call('GET', '/manage/certificate-templates', [], ['search' => '_']))['total']);
    }

    public function testCourseExpiryOverrideIsValidatedAndFrozen(): void
    {
        $draft = $this->blueprint();
        $id = $draft['id'];
        $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1]);
        $path = '/manage/courses/' . $this->fixture->courseId . '/certificate-policy';
        $body = $this->policy(true, $id);
        $body['expiry'] = ['mode' => 'days_after_issue', 'days' => null];
        self::assertSame(422, $this->call('PUT', $path, $body)->status);
        $body['expiry']['days'] = 30;
        self::assertSame(200, $this->call('PUT', $path, $body)->status);
        $issued = $this->fixture->kernel->issueCertificate
            ->executeOutcome(1100, ManagementFixture::LEARNER_ID, $this->fixture->courseId)->requireCertificate();
        self::assertSame($issued->issuedAt->modify('+30 days')->format('c'), $issued->expiresAt?->format('c'));
        self::assertNotNull($issued->templateSnapshot);
        $frozen = TemplateSnapshot::templateOf($issued->templateSnapshot);
        self::assertSame(30, $frozen['course_policy']['expiry']['days']);
        self::assertSame('site', $frozen['course_policy']['co_branding']);
    }

    public function testBulkReissueTargetsPublicationAndRequiresExactRetryInput(): void
    {
        $draft = $this->blueprint();
        $id = $draft['id'];
        $path = "/manage/certificate-templates/$id/reissue";
        self::assertSame(422, $this->call('POST', $path, ['revision' => 1])->status);
        $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1]);
        $this->call('PATCH', "/manage/certificate-templates/$id", ['expected_revision' => 2, 'name' => 'Unsaved publication']);
        self::assertSame(409, $this->call('POST', $path, ['revision' => 3])->status);
        self::assertSame(422, $this->call('POST', $path, ['revision' => 2, 'notify' => false])->status);
        $manager = $this->fixture->manager();
        self::assertSame(400, $this->fixture->call('POST', $path, $manager, ['revision' => 2])->status);
        $start = $this->fixture->call('POST', $path, $manager, ['revision' => 2], idempotencyKey: 'bulk-target');
        self::assertSame(202, $start->status);
        self::assertSame(2, self::data($start)['revision']);
        self::assertSame(self::data($start), self::data($this->fixture->call(
            'POST',
            $path,
            $manager,
            ['revision' => 2],
            idempotencyKey: 'bulk-target',
        )));
        self::assertSame(422, $this->fixture->call('POST', $path, $manager, ['revision' => 1], idempotencyKey: 'bulk-target')->status);
        self::assertSame(403, $this->fixture->call(
            'POST',
            $path,
            $this->fixture->teacher(),
            ['revision' => 2],
            idempotencyKey: 'bulk-target',
        )->status);
        self::assertSame(3, self::data($this->call('GET', "/manage/certificate-templates/$id"))['revision']);
        self::assertSame(422, $this->call('DELETE', $path)->status);
        self::assertSame(204, $this->call('DELETE', $path, [], ['revision' => '2'])->status);
    }

    /** @return array<string, mixed> */
    private function blueprint(): array
    {
        $catalogue = $this->call('GET', '/manage/certificate-blueprints');
        $source = self::data($catalogue)['items'][0];
        $response = $this->call('POST', '/manage/certificate-blueprints/' . $source['key'] . '/clone', [
            'name' => 'Studio sample', 'version' => $source['version'],
        ]);
        self::assertSame(201, $response->status, json_encode(self::data($response), JSON_THROW_ON_ERROR));
        return self::data($response);
    }

    /** @return array<string, mixed> */
    private function policy(bool $enabled, ?int $templateId = null): array
    {
        return ['expected_revision' => 0, 'enabled' => $enabled, 'template_id' => $templateId,
            'issue_rule' => 'course_completed', 'expiry' => ['mode' => 'inherit_template'], 'co_branding' => 'site'];
    }

    /** @return array<string, mixed> */
    private static function data(ApiResponse $response): array
    {
        self::assertNotNull($response->data);
        return $response->data;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function call(string $method, string $path, array $body = [], array $query = []): ApiResponse
    {
        return $this->fixture->call($method, $path, $this->fixture->manager(), $body, $query, 'studio-' . ++$this->sequence);
    }
}
