<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The certificate template authoring surface over the real routed kernel.
 *
 * Fifteen routes across three levels of one aggregate, each with its
 * refusals: the guest, the teacher who is not a manager, the id that does not
 * exist, the stale revision, the criteria lock and the payload the element
 * provider will not accept. A route with no refusal test is a route whose
 * authorization nobody has proved.
 */
final class CertificateTemplateRoutesTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $requestSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = ManagementFixture::create();
    }

    public function testTheListIsRefusedToAGuestAndToATeacherWhoIsNotAManager(): void
    {
        self::assertSame(
            401,
            $this->call('GET', '/manage/certificate-templates', Principal::guest())->status,
        );
        self::assertSame(
            403,
            $this->call('GET', '/manage/certificate-templates', $this->fixture->teacher())->status,
        );
    }

    public function testTheSeededDefaultTemplateRoundTripsThroughTheListAndTheDetail(): void
    {
        $templateId = $this->fixture->kernel->ensureDefaultTemplate->execute();

        $list = $this->call('GET', '/manage/certificate-templates', $this->fixture->manager());

        self::assertSame(200, $list->status);
        self::assertNotNull($list->data);
        self::assertSame(1, $list->data['total']);
        self::assertSame(1, $list->data['page']);
        self::assertSame(20, $list->data['per_page']);

        // The declared summary fields, including its optional thumbnail.
        self::assertSame([
            'id',
            'template_key',
            'name',
            'renderer',
            'format',
            'achievement_type',
            'revision',
            'published_revision',
            'published_at',
            'has_unpublished_changes',
            'category',
            'provenance',
            'schema_version',
            'locale',
            'state',
            'is_default',
            'expiry_mode',
            'expiry_days',
            'expiry_at',
            'locked_at',
            'locked_fields',
            'page_count',
            'element_count',
            'thumbnail',
        ], array_keys($list->data['items'][0]));

        self::assertSame('vidyalms.default', $list->data['items'][0]['template_key']);
        self::assertTrue($list->data['items'][0]['is_default']);
        self::assertSame('published', $list->data['items'][0]['state']);
        self::assertSame([], $list->data['items'][0]['locked_fields']);

        $detail = $this->call(
            'GET',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
        );

        self::assertSame(200, $detail->status);
        self::assertNotNull($detail->data);
        self::assertIsString($detail->data['body']);
        self::assertStringContainsString('{learner_name}', $detail->data['body']);
        self::assertSame([], $detail->data['pages']);
        // The html format is a body plus tokens, so there are no elements to
        // check and no template-level finding to report.
        self::assertSame([], $detail->data['findings']);
    }

    public function testAnUnknownTemplateIsANotFound(): void
    {
        $response = $this->call('GET', '/manage/certificate-templates/4242', $this->fixture->manager());

        self::assertSame(404, $response->status);
    }

    public function testATemplateIsCreatedAsADraftAtRevisionOne(): void
    {
        $response = $this->call('POST', '/manage/certificate-templates', $this->fixture->manager(), [
            'name' => 'Attendance',
            'template_key' => 'acme.attendance',
            'body' => '<p>Awarded to {learner_name}</p>',
        ]);

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('draft', $response->data['state']);
        self::assertSame(1, $response->data['revision']);
        self::assertSame('html', $response->data['format']);
        self::assertSame('html', $response->data['renderer']);
        self::assertSame('CertificateOfCompletion', $response->data['achievement_type']);
        self::assertFalse($response->data['is_default']);
        // A body template holds no page, so the designer is never opened on
        // one it cannot draw into.
        self::assertSame(0, $response->data['page_count']);
    }

    public function testAnElementsTemplateIsCreatedWithOnePageBecauseADesignerCannotOpenOnNothing(): void
    {
        $created = $this->createElementsTemplate();

        self::assertSame(1, $created['page_count']);
        self::assertSame('elements', $created['renderer']);

        $pages = $this->call(
            'GET',
            '/manage/certificate-templates/' . $created['id'] . '/pages',
            $this->fixture->manager(),
        );

        self::assertSame(200, $pages->status);
        self::assertNotNull($pages->data);
        self::assertCount(1, $pages->data['items']);
        self::assertSame(297, $pages->data['items'][0]['width']);
        self::assertSame(210, $pages->data['items'][0]['height']);
        self::assertSame('landscape', $pages->data['items'][0]['orientation']);
        self::assertSame([], $pages->data['items'][0]['elements']);
    }

    public function testACreateNamesEveryProblemAtOnceRatherThanTheFirst(): void
    {
        $response = $this->call('POST', '/manage/certificate-templates', $this->fixture->manager(), [
            'renderer' => 'no-such-renderer',
            'state' => 'retired',
        ]);

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('name', $response->data['errors']);
        self::assertArrayHasKey('state', $response->data['errors']);
        // The refusal names what this build has, because the usual cause is a
        // package that failed to install.
        self::assertStringContainsString('html', $response->data['errors']['renderer']);
        self::assertStringContainsString('elements', $response->data['errors']['renderer']);
    }

    public function testAShippedTemplateKeyIsUniqueAcrossTheSite(): void
    {
        $this->fixture->kernel->ensureDefaultTemplate->execute();

        $response = $this->call('POST', '/manage/certificate-templates', $this->fixture->manager(), [
            'name' => 'Impostor',
            'template_key' => 'vidyalms.default',
        ]);

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('template_key', $response->data['errors']);
    }

    public function testATemplateIsUpdatedAndEveryWriteMovesTheRevision(): void
    {
        $templateId = $this->created('POST', '/manage/certificate-templates', ['name' => 'Attendance']);

        $response = $this->call(
            'PATCH',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
            ['name' => 'Attendance 2026', 'expected_revision' => 1],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('Attendance 2026', $response->data['name']);
        self::assertSame(2, $response->data['revision']);
    }

    public function testAStaleWriteRequiresAFreshReadInsteadOfReportingAPotentiallyStaleCurrentRevision(): void
    {
        $templateId = $this->created('POST', '/manage/certificate-templates', ['name' => 'Attendance']);

        $this->call(
            'PATCH',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
            ['name' => 'First save'],
        );

        $stale = $this->call(
            'PATCH',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
            ['name' => 'Second save', 'expected_revision' => 1],
        );

        self::assertSame(409, $stale->status);
        self::assertNotNull($stale->data);
        // A MySQL repeatable-read transaction cannot promise that a second
        // read inside the failed write observes the competing writer.
        self::assertArrayNotHasKey('current_revision', $stale->data);
        self::assertSame(1, $stale->data['expected_revision']);
        $fresh = $this->call('GET', '/manage/certificate-templates/' . $templateId, $this->fixture->manager());
        self::assertSame(200, $fresh->status);
        self::assertNotNull($fresh->data);
        self::assertSame(2, $fresh->data['revision']);
        self::assertSame('First save', $fresh->data['name']);
    }

    public function testTheCriteriaLockRefusesAnExpiryChangeOnceACertificateHasBeenIssued(): void
    {
        $templateId = $this->issuedFromTheDefaultTemplate();

        $detail = $this->call(
            'GET',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
        );

        self::assertSame(200, $detail->status);
        self::assertNotNull($detail->data);
        self::assertNotNull($detail->data['locked_at']);
        // The lock is a fact the read answers, so a screen can disable the
        // controls rather than discovering the refusal by attempting a save.
        self::assertSame(['expiry_mode', 'expiry_days', 'expiry_at'], $detail->data['locked_fields']);

        $refused = $this->call(
            'PATCH',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
            ['expiry_mode' => 'days_after_issue', 'expiry_days' => 365],
        );

        self::assertSame(409, $refused->status);
        self::assertNotNull($refused->data);
        self::assertSame('certificate_template.criteria_locked', $refused->data['lock']);
        self::assertSame(['expiry_mode', 'expiry_days'], $refused->data['refused_fields']);

        // A locked template is not a frozen template: everything the lock
        // does not name still saves, or a form that posts every control it
        // drew could never save a name again.
        $allowed = $this->call(
            'PATCH',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
            ['name' => 'Standard 2026', 'expiry_mode' => 'never'],
        );

        self::assertSame(200, $allowed->status);
        self::assertNotNull($allowed->data);
        self::assertSame('Standard 2026', $allowed->data['name']);
    }

    public function testAnExpiryPolicyThatCannotAnswerWithWhatWasGivenIsRefusedAtSaveTime(): void
    {
        $templateId = $this->created('POST', '/manage/certificate-templates', ['name' => 'Attendance']);

        $response = $this->call(
            'PATCH',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
            ['expiry_mode' => 'days_after_issue'],
        );

        // The policy says so while an author is looking at the form, rather
        // than answering null at issue when nobody is watching.
        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('expiry_mode', $response->data['errors']);
    }

    public function testATemplateIsArchivedRatherThanDestroyedAndTheDefaultIsRefused(): void
    {
        $defaultId = $this->fixture->kernel->ensureDefaultTemplate->execute();

        $refused = $this->call(
            'DELETE',
            '/manage/certificate-templates/' . $defaultId,
            $this->fixture->manager(),
        );

        self::assertSame(409, $refused->status);

        $templateId = $this->created('POST', '/manage/certificate-templates', ['name' => 'Attendance']);
        $archived = $this->call(
            'DELETE',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
        );

        self::assertSame(200, $archived->status);
        self::assertNotNull($archived->data);
        self::assertSame('archived', $archived->data['state']);

        // Nothing is deleted: certificates issued from it keep verifying and
        // their frozen snapshots keep rendering.
        self::assertNotNull($this->fixture->env->database->selectOne(
            Query::from('certificate_templates')->where('id', '=', $templateId),
        ));
    }

    public function testPagesAreAppendedAndReorderedAsAWholeList(): void
    {
        $template = $this->createElementsTemplate();
        $manager = $this->fixture->manager();
        $first = $this->pageIdsOf((int) $template['id'])[0];

        $second = $this->created(
            'POST',
            '/manage/certificate-templates/' . $template['id'] . '/pages',
            ['orientation' => 'portrait', 'width' => 210, 'height' => 297],
        );

        self::assertSame([$first, $second], $this->pageIdsOf((int) $template['id']));

        $reordered = $this->call(
            'PUT',
            '/manage/certificate-templates/' . $template['id'] . '/pages/order',
            $manager,
            ['ids' => [$second, $first]],
        );

        self::assertSame(200, $reordered->status);
        self::assertNotNull($reordered->data);
        self::assertSame(['template_revision', 'items'], array_keys($reordered->data));
        self::assertSame(3, $reordered->data['template_revision']);
        self::assertSame([$second, $first], array_column($reordered->data['items'], 'id'));
        self::assertSame([1, 2], array_column($reordered->data['items'], 'sequence'));
    }

    public function testAPartialReorderIsRefusedNamingWhatWasMissing(): void
    {
        $template = $this->createElementsTemplate();
        $this->created('POST', '/manage/certificate-templates/' . $template['id'] . '/pages', []);
        $ids = $this->pageIdsOf((int) $template['id']);

        $response = $this->call(
            'PUT',
            '/manage/certificate-templates/' . $template['id'] . '/pages/order',
            $this->fixture->manager(),
            ['ids' => [$ids[0]]],
        );

        // A partial reorder would silently drop a page from the render.
        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertStringContainsString((string) $ids[1], $response->data['errors']['ids']);
    }

    public function testAPageIsPatchedAndTheLastPageOfAnElementsTemplateCannotBeRemoved(): void
    {
        $template = $this->createElementsTemplate();
        $manager = $this->fixture->manager();
        $pageId = $this->pageIdsOf((int) $template['id'])[0];

        $patched = $this->call(
            'PATCH',
            '/manage/certificate-template-pages/' . $pageId,
            $manager,
            ['margin_left' => 25, 'background' => 'media/paper.png'],
        );

        self::assertSame(200, $patched->status);
        self::assertNotNull($patched->data);
        self::assertSame(['template_revision', 'item'], array_keys($patched->data));
        self::assertSame(2, $patched->data['template_revision']);
        self::assertSame(25, $patched->data['item']['margin_left']);
        self::assertSame('media/paper.png', $patched->data['item']['background']);
        // A field the patch did not name keeps what it had.
        self::assertSame(10, $patched->data['item']['margin_right']);

        $refused = $this->call('DELETE', '/manage/certificate-template-pages/' . $pageId, $manager);

        self::assertSame(409, $refused->status);

        $second = $this->created('POST', '/manage/certificate-templates/' . $template['id'] . '/pages', []);
        $deleted = $this->call('DELETE', '/manage/certificate-template-pages/' . $second, $manager);

        self::assertSame(200, $deleted->status);
        self::assertSame(['template_revision' => 4], $deleted->data);
        self::assertSame([$pageId], $this->pageIdsOf((int) $template['id']));
    }

    public function testRemovingAPageRemovesTheElementsOnIt(): void
    {
        $template = $this->createElementsTemplate();
        $manager = $this->fixture->manager();
        $second = $this->created('POST', '/manage/certificate-templates/' . $template['id'] . '/pages', []);
        $elementId = $this->created(
            'POST',
            '/manage/certificate-template-pages/' . $second . '/elements',
            ['element' => 'border'],
        );

        self::assertSame(200, $this->call(
            'DELETE',
            '/manage/certificate-template-pages/' . $second,
            $manager,
        )->status);

        // An element with no page has no paper to be drawn on and would be
        // unreachable rather than merely unused.
        self::assertNull($this->fixture->env->database->selectOne(
            Query::from('certificate_template_elements')->where('id', '=', $elementId),
        ));
    }

    public function testAnElementIsStoredWithItsProvidersDefaultPayloadAndItsVersion(): void
    {
        $template = $this->createElementsTemplate();
        $pageId = $this->pageIdsOf((int) $template['id'])[0];

        $response = $this->call(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            $this->fixture->manager(),
            ['element' => 'learner_name', 'posx' => 40, 'posy' => 60, 'refpoint' => 'top_centre'],
        );

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['template_revision', 'item'], array_keys($response->data));
        self::assertSame(2, $response->data['template_revision']);
        self::assertSame([
            'id',
            'page_id',
            'sequence',
            'element',
            'name',
            'data',
            'data_version',
            'current_version',
            'available',
            'package',
            'posx',
            'posy',
            'width',
            'height',
            'refpoint',
            'alignment',
        ], array_keys($response->data['item']));

        self::assertSame('learner_name', $response->data['item']['element']);
        self::assertTrue($response->data['item']['available']);
        self::assertSame(1, $response->data['item']['sequence']);
        self::assertSame(40, $response->data['item']['posx']);
        self::assertSame('top_centre', $response->data['item']['refpoint']);
        self::assertSame($response->data['item']['current_version'], $response->data['item']['data_version']);

        // The envelope is the repository's and never the provider's: what
        // comes back is the payload alone.
        self::assertArrayNotHasKey('_v', (array) $response->data['item']['data']);
    }

    public function testEveryAvailablePaletteElementCanBeAddedWithItsProviderDefaults(): void
    {
        $templateId = (int) $this->createElementsTemplate()['id'];
        $pageId = $this->pageIdsOf($templateId)[0];
        $palette = $this->call('GET', '/manage/certificate-templates/' . $templateId . '/element-types', $this->fixture->manager());
        self::assertSame(200, $palette->status);
        self::assertNotNull($palette->data);

        foreach ($palette->data['items'] as $type) {
            if (!$type['available']) {
                continue;
            }
            $response = $this->call(
                'POST',
                '/manage/certificate-template-pages/' . $pageId . '/elements',
                $this->fixture->manager(),
                ['element' => $type['key']],
            );

            self::assertSame(201, $response->status, $type['key'] . ': ' . self::describe($response));
            self::assertNotNull($response->data);
            self::assertEquals((array) $type['default_payload'], (array) $response->data['item']['data']);
            self::assertSame($type['current_version'], $response->data['item']['data_version']);
        }
    }

    public function testAnUnconfiguredPaletteElementCanMoveButMustBeCompletedBeforePublication(): void
    {
        $templateId = (int) $this->createElementsTemplate()['id'];
        $pageId = $this->pageIdsOf($templateId)[0];
        $elementId = $this->created('POST', '/manage/certificate-template-pages/' . $pageId . '/elements', [
            'element' => 'text',
        ]);
        $path = '/manage/certificate-template-elements/' . $elementId;
        $moved = $this->call('PATCH', $path, $this->fixture->manager(), ['posx' => 20, 'posy' => 30]);
        self::assertSame(200, $moved->status, self::describe($moved));
        self::assertNotNull($moved->data);
        self::assertSame(20, $moved->data['item']['posx']);
        self::assertSame('', ((array) $moved->data['item']['data'])['text']);

        // Satisfy the separate verification rule so the blank text is the blocker.
        $this->created('POST', '/manage/certificate-template-pages/' . $pageId . '/elements', [
            'element' => 'verification_code',
        ]);
        $preview = $this->call('POST', '/manage/certificate-templates/' . $templateId . '/preview', $this->fixture->manager());
        self::assertSame(200, $preview->status, self::describe($preview));
        self::assertNotNull($preview->data);
        self::assertNotEmpty($preview->data['findings']);

        $revision = $this->templateRevision($templateId);
        $publishPath = '/manage/certificate-templates/' . $templateId . '/publish';
        $refused = $this->call('POST', $publishPath, $this->fixture->manager());
        self::assertSame(422, $refused->status, self::describe($refused));
        self::assertSame($revision, $this->templateRevision($templateId));

        foreach ([null, [], ['text' => '   ']] as $invalid) {
            $saved = $this->call('PATCH', $path, $this->fixture->manager(), ['data' => $invalid]);
            self::assertSame(422, $saved->status, self::describe($saved));
            self::assertSame($revision, $this->templateRevision($templateId));
        }
        $saved = $this->call('PATCH', $path, $this->fixture->manager(), ['data' => ['text' => 'Congratulations']]);
        self::assertSame(200, $saved->status, self::describe($saved));
        $published = $this->call('POST', $publishPath, $this->fixture->manager());
        self::assertSame(200, $published->status, self::describe($published));
    }

    public function testAnElementKeyNoProviderRegistersIsRefusedNamingEveryKeyThisBuildHas(): void
    {
        $template = $this->createElementsTemplate();
        $pageId = $this->pageIdsOf((int) $template['id'])[0];

        $response = $this->call(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            $this->fixture->manager(),
            ['element' => 'hologram'],
        );

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        // The usual cause is a package that failed to install, and a bare
        // "invalid element" sends an administrator looking in the wrong place.
        self::assertStringContainsString('learner_name', $response->data['errors']['element']);
    }

    public function testAPayloadAboveTheVersionTheProviderDeclaresIsRefusedRatherThanStored(): void
    {
        $template = $this->createElementsTemplate();
        $pageId = $this->pageIdsOf((int) $template['id'])[0];

        $response = $this->call(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            $this->fixture->manager(),
            ['element' => 'border', 'data_version' => 99],
        );

        // A stored payload nothing can read is a miss that no later check can
        // tell apart from an uninstalled package.
        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('data_version', $response->data['errors']);
    }

    public function testAnElementPayloadIsCheckedByItsOwnProvider(): void
    {
        $template = $this->createElementsTemplate();
        $pageId = $this->pageIdsOf((int) $template['id'])[0];

        $response = $this->call(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            $this->fixture->manager(),
            ['element' => 'text', 'data' => ['text' => '   ']],
        );

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('data', $response->data['errors']);
    }

    public function testAnElementIsMovedAndItsPayloadIsReplacedWholesale(): void
    {
        $template = $this->createElementsTemplate();
        $pageId = $this->pageIdsOf((int) $template['id'])[0];
        $elementId = $this->created(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            ['element' => 'text', 'data' => ['text' => 'Awarded to {learner_name}', 'max_width_mm' => 140]],
        );

        $moved = $this->call(
            'PATCH',
            '/manage/certificate-template-elements/' . $elementId,
            $this->fixture->manager(),
            ['posx' => 15, 'posy' => 90],
        );

        self::assertSame(200, $moved->status);
        self::assertNotNull($moved->data);
        self::assertSame(['template_revision', 'item'], array_keys($moved->data));
        self::assertSame(3, $moved->data['template_revision']);
        self::assertSame(15, $moved->data['item']['posx']);
        // A drag is a patch of position alone and leaves the payload where it
        // was, or every drag would be a settings reset.
        self::assertSame('Awarded to {learner_name}', ((array) $moved->data['item']['data'])['text']);

        $reconfigured = $this->call(
            'PATCH',
            '/manage/certificate-template-elements/' . $elementId,
            $this->fixture->manager(),
            ['data' => ['text' => 'Presented to {learner_name}']],
        );

        self::assertSame(200, $reconfigured->status);
        self::assertNotNull($reconfigured->data);
        // Replaced wholesale rather than merged, so removing a field is
        // expressible.
        self::assertSame(4, $reconfigured->data['template_revision']);
        self::assertArrayNotHasKey('max_width_mm', (array) $reconfigured->data['item']['data']);
    }

    public function testElementsAreListedAndReorderedInDrawOrder(): void
    {
        $template = $this->createElementsTemplate();
        $manager = $this->fixture->manager();
        $pageId = $this->pageIdsOf((int) $template['id'])[0];

        $border = $this->created(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            ['element' => 'border'],
        );
        $name = $this->created(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            ['element' => 'learner_name'],
        );

        $listed = $this->call(
            'GET',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            $manager,
        );

        self::assertSame(200, $listed->status);
        self::assertNotNull($listed->data);
        self::assertSame([$border, $name], array_column($listed->data['items'], 'id'));

        $reordered = $this->call(
            'PUT',
            '/manage/certificate-template-pages/' . $pageId . '/elements/order',
            $manager,
            ['ids' => [$name, $border]],
        );

        self::assertSame(200, $reordered->status);
        self::assertNotNull($reordered->data);
        self::assertSame(['template_revision', 'items'], array_keys($reordered->data));
        self::assertSame(4, $reordered->data['template_revision']);
        // Sequence is draw order, so this is what puts a border behind a name
        // rather than over it.
        self::assertSame([$name, $border], array_column($reordered->data['items'], 'id'));
        self::assertSame([1, 2], array_column($reordered->data['items'], 'sequence'));
    }

    public function testAnElementIsDeletedAndItsSiblingsCloseTheGap(): void
    {
        $template = $this->createElementsTemplate();
        $manager = $this->fixture->manager();
        $pageId = $this->pageIdsOf((int) $template['id'])[0];

        $first = $this->created(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            ['element' => 'border'],
        );
        $second = $this->created(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            ['element' => 'learner_name'],
        );

        self::assertSame(200, $this->call(
            'DELETE',
            '/manage/certificate-template-elements/' . $first,
            $manager,
        )->status);

        $listed = $this->call(
            'GET',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            $manager,
        );

        self::assertNotNull($listed->data);
        self::assertSame([$second], array_column($listed->data['items'], 'id'));
        self::assertSame([1], array_column($listed->data['items'], 'sequence'));
    }

    public function testEveryWriteRouteIsRefusedToATeacherWhoIsNotAManager(): void
    {
        $template = $this->createElementsTemplate();
        $templateId = (int) $template['id'];
        $pageId = $this->pageIdsOf($templateId)[0];
        $elementId = $this->created(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            ['element' => 'border'],
        );

        $routes = [
            ['POST', '/manage/certificate-templates'],
            ['GET', '/manage/certificate-templates/' . $templateId],
            ['PATCH', '/manage/certificate-templates/' . $templateId],
            ['DELETE', '/manage/certificate-templates/' . $templateId],
            ['GET', '/manage/certificate-templates/' . $templateId . '/pages'],
            ['POST', '/manage/certificate-templates/' . $templateId . '/pages'],
            ['PUT', '/manage/certificate-templates/' . $templateId . '/pages/order'],
            ['PATCH', '/manage/certificate-template-pages/' . $pageId],
            ['DELETE', '/manage/certificate-template-pages/' . $pageId],
            ['GET', '/manage/certificate-template-pages/' . $pageId . '/elements'],
            ['POST', '/manage/certificate-template-pages/' . $pageId . '/elements'],
            ['PUT', '/manage/certificate-template-pages/' . $pageId . '/elements/order'],
            ['PATCH', '/manage/certificate-template-elements/' . $elementId],
            ['DELETE', '/manage/certificate-template-elements/' . $elementId],
            ['POST', '/manage/certificate-templates/' . $templateId . '/preview'],
        ];

        foreach ($routes as [$method, $path]) {
            self::assertSame(
                401,
                $this->call($method, $path, Principal::guest())->status,
                $method . ' ' . $path . ' as a guest',
            );
            self::assertSame(
                403,
                $this->call($method, $path, $this->fixture->teacher())->status,
                $method . ' ' . $path . ' as a teacher',
            );
        }
    }

    public function testThePreviewRendersThroughTheSameRendererIssuanceUsesAndWritesNothing(): void
    {
        $templateId = $this->fixture->kernel->ensureDefaultTemplate->execute();

        $response = $this->call(
            'POST',
            '/manage/certificate-templates/' . $templateId . '/preview',
            $this->fixture->manager(),
            ['user_id' => ManagementFixture::LEARNER_ID, 'course_id' => $this->fixture->courseId],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            ['media_type', 'html', 'data_url', 'findings', 'unresolved_tokens'],
            array_keys($response->data),
        );
        self::assertSame('text/html', $response->data['media_type']);
        self::assertNull($response->data['data_url']);
        self::assertIsString($response->data['html']);
        self::assertStringContainsString('Lena Learner', $response->data['html']);
        self::assertStringContainsString('Tea Tasting', $response->data['html']);

        // No certificate row, no code, no status list index: a preview that
        // writes anything is a preview that has issued a credential.
        self::assertSame(0, $this->fixture->env->database->count(Query::from('certificates')));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('credential_status_entries')));
    }

    public function testThePreviewLeavesATokenNoResolverOwnsIntactAndNamesIt(): void
    {
        $templateId = $this->created('POST', '/manage/certificate-templates', [
            'name' => 'Cohort',
            'body' => '<p>{learner_name} of {badge.criteria_url}</p>',
        ]);

        $response = $this->call(
            'POST',
            '/manage/certificate-templates/' . $templateId . '/preview',
            $this->fixture->manager(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        // Left intact rather than blanked, so a template written for a richer
        // build degrades visibly instead of silently losing a line.
        self::assertStringContainsString('{badge.criteria_url}', (string) $response->data['html']);
        self::assertSame(['badge.criteria_url'], $response->data['unresolved_tokens']);
    }

    public function testThePreviewCarriesTheTemplateLevelCheckAQrCodeAloneCannotPass(): void
    {
        $template = $this->createElementsTemplate();
        $pageId = $this->pageIdsOf((int) $template['id'])[0];

        $this->created(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            ['element' => 'qr_code', 'data' => ['alt' => 'Verify this certificate']],
        );

        $response = $this->call(
            'POST',
            '/manage/certificate-templates/' . $template['id'] . '/preview',
            $this->fixture->manager(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            ['vidyalms.certificate_template.error.no_readable_verification'],
            array_map(
                static fn (array $finding): string => (string) $finding['key'],
                array_values(array_filter(
                    $response->data['findings'],
                    static fn (array $finding): bool => $finding['severity'] === 'error',
                )),
            ),
        );

        self::assertSame(
            ['severity', 'key', 'message', 'element_id', 'parameters'],
            array_keys($response->data['findings'][0]),
        );
    }

    public function testThePreviewOfAnUnknownTemplateIsANotFound(): void
    {
        $response = $this->call(
            'POST',
            '/manage/certificate-templates/4242/preview',
            $this->fixture->manager(),
        );

        self::assertSame(404, $response->status);
    }

    public function testThePreviewOfACertificateThatDoesNotExistIsANotFoundRatherThanADifferentDocument(): void
    {
        $templateId = $this->fixture->kernel->ensureDefaultTemplate->execute();

        $response = $this->call(
            'POST',
            '/manage/certificate-templates/' . $templateId . '/preview',
            $this->fixture->manager(),
            ['certificate_id' => 4242],
        );

        self::assertSame(404, $response->status);
    }

    public function testTurningABodyTemplateIntoAnElementsOneGivesItThePageItNeeds(): void
    {
        $templateId = $this->created('POST', '/manage/certificate-templates', ['name' => 'Attendance']);

        $response = $this->call(
            'PATCH',
            '/manage/certificate-templates/' . $templateId,
            $this->fixture->manager(),
            ['format' => 'elements', 'renderer' => 'elements'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('elements', $response->data['format']);
        // A template with no page can hold no element, whichever route put it
        // into the elements format.
        self::assertCount(1, $this->pageIdsOf($templateId));
    }

    public function testEveryAuthoringMutationRequiresANonEmptyBoundedIdempotencyKey(): void
    {
        [$templateId, $writes] = $this->authoringWrites();
        $writes[] = ['POST', '/manage/certificate-templates', ['name' => 'Must not be created']];
        $before = $this->authoringSnapshot($templateId);

        foreach ($writes as [$method, $path, $body]) {
            // An absent header is a bad request; a present but oversized one
            // is a validation failure carrying the offending field. Pairs,
            // not a map: null and '' collapse onto one array key.
            foreach ([[null, 400], ['', 400], ['  ', 400], [str_repeat('a', 191), 422]] as [$key, $expected]) {
                $query = [];
                if ($method === 'DELETE') {
                    $query['expected_revision'] = (string) $this->templateRevision($templateId);
                } elseif ($path !== '/manage/certificate-templates') {
                    $body['expected_revision'] = $this->templateRevision($templateId);
                }
                $response = $this->fixture->call($method, $path, $this->fixture->manager(), $body, $query, $key);

                self::assertSame($expected, $response->status, $method . ' ' . $path . ': ' . self::describe($response));
                self::assertNotNull($response->data);

                if ($expected === 422) {
                    self::assertArrayHasKey('Idempotency-Key', $response->data['errors']);
                }

                self::assertSame($before, $this->authoringSnapshot($templateId));
            }
        }
    }

    public function testEveryExistingAggregateWriteRequiresAStrictBoundedRevision(): void
    {
        [$templateId, $writes] = $this->authoringWrites();
        $before = $this->authoringSnapshot($templateId);

        foreach ($writes as [$method, $path, $body]) {
            $invalid = $method === 'DELETE'
                ? [[], ['expected_revision' => ''], ['expected_revision' => '0'], ['expected_revision' => '-1'],
                    ['expected_revision' => '01'], ['expected_revision' => '1.5'], ['expected_revision' => '2147483647']]
                : [[], ['expected_revision' => null], ['expected_revision' => 0], ['expected_revision' => -1],
                    ['expected_revision' => '1'], ['expected_revision' => 1.5], ['expected_revision' => 2147483647]];

            foreach ($invalid as $precondition) {
                // Delete preconditions belong in the query; JSON bodies
                // accept integers only. Neither boundary is repaired here.
                $query = [];
                if ($method === 'DELETE') {
                    foreach ($precondition as $field => $value) {
                        self::assertIsString($value);
                        $query[$field] = $value;
                    }
                }
                $requestBody = $method === 'DELETE' ? $body : $body + $precondition;
                $response = $this->fixture->call(
                    $method,
                    $path,
                    $this->fixture->manager(),
                    $requestBody,
                    $query,
                    'invalid-revision-' . ++$this->requestSequence,
                );

                self::assertSame(422, $response->status, $method . ' ' . $path . ': ' . self::describe($response));
                self::assertNotNull($response->data);
                self::assertArrayHasKey('expected_revision', $response->data['errors']);
                self::assertSame($before, $this->authoringSnapshot($templateId));
            }
        }
    }

    public function testAllChildAndRootWritesRefuseTheSameStaleAggregateRevision(): void
    {
        [$templateId, $writes] = $this->authoringWrites();
        $revision = $this->templateRevision($templateId);
        $before = $this->authoringSnapshot($templateId);

        foreach ($writes as [$method, $path, $body]) {
            $query = [];
            if ($method === 'DELETE') {
                $query['expected_revision'] = (string) ($revision - 1);
            } else {
                $body['expected_revision'] = $revision - 1;
            }
            $response = $this->fixture->call(
                $method,
                $path,
                $this->fixture->manager(),
                $body,
                $query,
                'stale-revision-' . ++$this->requestSequence,
            );

            self::assertSame(409, $response->status, $method . ' ' . $path . ': ' . self::describe($response));
            self::assertNotNull($response->data);
            self::assertSame($revision - 1, $response->data['expected_revision']);
            self::assertArrayNotHasKey('current_revision', $response->data);
            self::assertSame($before, $this->authoringSnapshot($templateId));
        }
    }

    public function testEmptyRootPageAndElementPatchesDoNotAdvanceRevisionOrKeepAClaim(): void
    {
        [$templateId, $writes] = $this->authoringWrites();
        $before = $this->authoringSnapshot($templateId);

        foreach ($writes as [$method, $path]) {
            if ($method !== 'PATCH') {
                continue;
            }
            $response = $this->fixture->call(
                $method,
                $path,
                $this->fixture->manager(),
                ['expected_revision' => $this->templateRevision($templateId)],
                [],
                'empty-patch-' . ++$this->requestSequence,
            );

            self::assertSame(422, $response->status, $path . ': ' . self::describe($response));
            self::assertNotNull($response->data);
            self::assertNotEmpty($response->data['errors']);
            self::assertSame($before, $this->authoringSnapshot($templateId));
        }
    }

    public function testCreateReplayNeedsNoRevisionAndReturnsTheOriginalRootOnlyOnce(): void
    {
        $before = $this->fixture->env->database->count(Query::from('certificate_templates'));
        $created = $this->fixture->call(
            'POST',
            '/manage/certificate-templates',
            $this->fixture->manager(),
            ['name' => 'A single logical create'],
            [],
            'create-replay',
        );
        $replayed = $this->fixture->call(
            'POST',
            '/manage/certificate-templates',
            $this->fixture->manager(),
            ['name' => 'A single logical create'],
            [],
            'create-replay',
        );

        self::assertSame(201, $created->status, self::describe($created));
        self::assertNotNull($created->data);
        self::assertSame(1, $created->data['revision']);
        $this->assertReplay($created, $replayed);
        $mismatched = $this->fixture->call(
            'POST',
            '/manage/certificate-templates',
            $this->fixture->manager(),
            ['name' => 'A changed create requires a fresh key'],
            [],
            'create-replay',
        );
        self::assertSame(422, $mismatched->status);
        self::assertNotNull($mismatched->data);
        self::assertSame('idempotency.input_mismatch', $mismatched->data['reason']);
        self::assertSame($before + 1, $this->fixture->env->database->count(Query::from('certificate_templates')));
        self::assertSame(1, $this->templateRevision((int) $created->data['id']));
    }

    public function testReplayIsIsolatedByResourceAndMethodAndNeverAdvancesTheRevision(): void
    {
        $firstId = $this->created('POST', '/manage/certificate-templates', ['name' => 'First']);
        $secondId = $this->created('POST', '/manage/certificate-templates', ['name' => 'Second']);
        $key = 'shared-key-different-resources-and-methods';

        foreach ([$firstId, $secondId] as $templateId) {
            $path = '/manage/certificate-templates/' . $templateId;
            $saved = $this->fixture->call('PATCH', $path, $this->fixture->manager(), [
                'name' => 'Saved ' . $templateId, 'expected_revision' => 1,
            ], [], $key);
            self::assertSame(200, $saved->status, self::describe($saved));
            self::assertNotNull($saved->data);
            self::assertSame($templateId, $saved->data['id']);
            self::assertSame('Saved ' . $templateId, $saved->data['name']);
            self::assertSame(2, $saved->data['revision']);
            $replayed = $this->fixture->call('PATCH', $path, $this->fixture->manager(), [
                'expected_revision' => 1, 'name' => 'Saved ' . $templateId,
            ], [], $key);
            $this->assertReplay($saved, $replayed);
            $mismatched = $this->fixture->call('PATCH', $path, $this->fixture->manager(), [
                'name' => 'A different edit', 'expected_revision' => 2,
            ], [], $key);
            self::assertSame(422, $mismatched->status);
            self::assertNotNull($mismatched->data);
            self::assertSame('idempotency.input_mismatch', $mismatched->data['reason']);
            self::assertSame(2, $this->templateRevision($templateId));
        }

        $path = '/manage/certificate-templates/' . $firstId;
        $archived = $this->fixture->call('DELETE', $path, $this->fixture->manager(), [], ['expected_revision' => '2'], $key);
        self::assertSame(200, $archived->status, self::describe($archived));
        self::assertNotNull($archived->data);
        self::assertSame('archived', $archived->data['state']);
        self::assertSame(3, $archived->data['revision']);
        // A host's rotating nonce is not authoring input and does not make
        // an otherwise identical retry a new archive operation.
        $replayed = $this->fixture->call('DELETE', $path, $this->fixture->manager(), [], [
            'expected_revision' => '2', '_wpnonce' => 'another-host-nonce',
        ], $key);
        $this->assertReplay($archived, $replayed);
        $mismatched = $this->fixture->call('DELETE', $path, $this->fixture->manager(), [], ['expected_revision' => '3'], $key);
        self::assertSame(422, $mismatched->status);
        self::assertNotNull($mismatched->data);
        self::assertSame('idempotency.input_mismatch', $mismatched->data['reason']);
        self::assertSame(3, $this->templateRevision($firstId));
        self::assertSame(2, $this->templateRevision($secondId));
    }

    public function testChildDeletesReplayAfterTheirRowsAreGoneAndRemainResourceIsolated(): void
    {
        [$templateId, $writes] = $this->authoringWrites();
        $deletes = array_values(array_filter(
            $writes,
            static fn (array $write): bool => $write[0] === 'DELETE' && !str_contains($write[1], '/certificate-templates/'),
        ));

        foreach ($deletes as [$method, $path]) {
            $revision = $this->templateRevision($templateId);
            $query = ['expected_revision' => (string) $revision];
            $deleted = $this->fixture->call($method, $path, $this->fixture->manager(), [], $query, 'delete-children');
            self::assertSame(200, $deleted->status, self::describe($deleted));
            self::assertSame(['template_revision' => $revision + 1], $deleted->data);
            $replayed = $this->fixture->call($method, $path, $this->fixture->manager(), [], $query, 'delete-children');
            $this->assertReplay($deleted, $replayed);
            self::assertSame($revision + 1, $this->templateRevision($templateId));
            self::assertSame(404, $this->fixture->call(
                $method,
                $path,
                $this->fixture->manager(),
                [],
                ['expected_revision' => (string) ($revision + 1)],
                'different-delete-key-' . ++$this->requestSequence,
            )->status);
        }
    }

    public function testAnEmptyFreeFormElementObjectHasByteIdenticalJsonOnReplay(): void
    {
        $templateId = (int) $this->createElementsTemplate()['id'];
        $pageId = $this->pageIdsOf($templateId)[0];
        $path = '/manage/certificate-template-pages/' . $pageId . '/elements';
        // The border provider accepts its defaults at render time, making an
        // explicitly empty free-form payload valid without inventing a fake
        // provider. The API must serialize it as an object, including retries.
        $body = ['element' => 'border', 'data' => [], 'expected_revision' => 1];
        $created = $this->fixture->call('POST', $path, $this->fixture->manager(), $body, [], 'empty-object-replay');
        $replayed = $this->fixture->call('POST', $path, $this->fixture->manager(), $body, [], 'empty-object-replay');

        self::assertSame(201, $created->status, self::describe($created));
        self::assertNotNull($created->data);
        self::assertSame('{}', json_encode($created->data['item']['data'], \JSON_THROW_ON_ERROR));
        $this->assertReplay($created, $replayed);
        self::assertSame(2, $this->templateRevision($templateId));
        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('certificate_template_elements')->where('page_id', '=', $pageId),
        ));
    }

    public function testAuthorizationIsCheckedAgainBeforeReturningAPreviouslyAuthorizedReplay(): void
    {
        $templateId = $this->created('POST', '/manage/certificate-templates', ['name' => 'Private draft']);
        $path = '/manage/certificate-templates/' . $templateId;
        $body = ['name' => 'Saved while authorized', 'expected_revision' => 1];
        $saved = $this->fixture->call('PATCH', $path, $this->fixture->manager(), $body, [], 'revoked-manager');
        self::assertSame(200, $saved->status, self::describe($saved));

        // Revoke the SAME actor, not another principal with a different key
        // scope. StubAuthorization intentionally has no production-facing
        // revocation API; this isolated fixture loses its in-memory grants.
        $grants = new \ReflectionProperty($this->fixture->env->authorization, 'grants');
        $grants->setValue($this->fixture->env->authorization, []);
        $replayed = $this->fixture->call('PATCH', $path, $this->fixture->manager(), $body, [], 'revoked-manager');

        self::assertSame(403, $replayed->status);
        self::assertArrayNotHasKey('Idempotent-Replayed', $replayed->headers);
        self::assertNotNull($replayed->data);
        self::assertArrayNotHasKey('name', $replayed->data);
        self::assertSame(2, $this->templateRevision($templateId));
    }

    /**
     * A populated aggregate and every authoring route except root creation.
     * The second page is empty, so deleting it leaves the separately tested
     * element alive on page one. All payloads are otherwise valid.
     *
     * @return array{int, list<array{string, string, array<string, mixed>}>}
     */
    private function authoringWrites(): array
    {
        $templateId = (int) $this->createElementsTemplate()['id'];
        $pageId = $this->pageIdsOf($templateId)[0];
        $secondId = $this->created('POST', '/manage/certificate-templates/' . $templateId . '/pages', []);
        $elementId = $this->created('POST', '/manage/certificate-template-pages/' . $pageId . '/elements', [
            'element' => 'border',
        ]);
        $root = '/manage/certificate-templates/' . $templateId;
        $page = '/manage/certificate-template-pages/' . $pageId;
        $element = '/manage/certificate-template-elements/' . $elementId;

        return [$templateId, [
            ['PATCH', $root, ['name' => 'Updated']],
            ['DELETE', $root, []],
            ['POST', $root . '/pages', ['orientation' => 'portrait']],
            ['PUT', $root . '/pages/order', ['ids' => [$secondId, $pageId]]],
            ['PATCH', $page, ['margin_left' => 20]],
            ['DELETE', '/manage/certificate-template-pages/' . $secondId, []],
            ['POST', $page . '/elements', ['element' => 'border']],
            ['PUT', $page . '/elements/order', ['ids' => [$elementId]]],
            ['PATCH', $element, ['posx' => 20]],
            ['DELETE', $element, []],
        ]];
    }

    /**
     * The full graph plus counts exposes root/child mutations AND leaked
     * idempotency claims on a refused write, not just a revision mismatch.
     *
     * @return array<string, mixed>
     */
    private function authoringSnapshot(int $templateId): array
    {
        $detail = $this->fixture->call('GET', '/manage/certificate-templates/' . $templateId, $this->fixture->manager());
        self::assertSame(200, $detail->status, self::describe($detail));

        return [
            'detail' => self::describe($detail),
            'templates' => $this->fixture->env->database->count(Query::from('certificate_templates')),
            'claims' => $this->fixture->env->database->count(Query::from('idempotency_keys')),
        ];
    }

    private function assertReplay(ApiResponse $original, ApiResponse $replayed): void
    {
        self::assertSame($original->status, $replayed->status, self::describe($replayed));
        self::assertSame(self::describe($original), self::describe($replayed));
        self::assertSame('true', $replayed->headers['Idempotent-Replayed'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function createElementsTemplate(): array
    {
        $response = $this->call('POST', '/manage/certificate-templates', $this->fixture->manager(), [
            'name' => 'Designer',
            'format' => 'elements',
        ]);

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);

        return $response->data;
    }

    /**
     * @return list<int>
     */
    private function pageIdsOf(int $templateId): array
    {
        $response = $this->call(
            'GET',
            '/manage/certificate-templates/' . $templateId . '/pages',
            $this->fixture->manager(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);

        return array_values(array_map(
            static fn (array $page): int => (int) $page['id'],
            $response->data['items'],
        ));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function created(string $method, string $path, array $body): int
    {
        $response = $this->call($method, $path, $this->fixture->manager(), $body);

        self::assertSame(201, $response->status, self::describe($response));
        self::assertNotNull($response->data);

        return (int) ($response->data['item']['id'] ?? $response->data['id']);
    }

    /**
     * Ordinary calls opt into the new contract with a fresh logical key and
     * the addressed aggregate's current revision. Explicit stale/invalid
     * preconditions are never replaced. Boundary tests use fixture->call()
     * directly so missing inputs cannot be hidden by this convenience.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function call(string $method, string $path, Principal $principal, array $body = [], array $query = []): ApiResponse
    {
        if ($method === 'GET' || str_ends_with($path, '/preview')) {
            return $this->fixture->call($method, $path, $principal, $body, $query);
        }

        if ($path !== '/manage/certificate-templates') {
            $templateId = $this->templateIdForPath($path);
            $revision = $this->templateRevision($templateId);
            if ($method === 'DELETE') {
                $query += ['expected_revision' => (string) $revision];
            } else {
                $body += ['expected_revision' => $revision];
            }
        }

        return $this->fixture->call($method, $path, $principal, $body, $query, 'certificate-routes-' . ++$this->requestSequence);
    }

    private function templateIdForPath(string $path): int
    {
        if (preg_match('#^/manage/certificate-templates/([1-9][0-9]*)#', $path, $match) === 1) {
            return (int) $match[1];
        }
        if (preg_match('#^/manage/certificate-template-pages/([1-9][0-9]*)#', $path, $match) === 1) {
            $pageId = (int) $match[1];
        } elseif (preg_match('#^/manage/certificate-template-elements/([1-9][0-9]*)#', $path, $match) === 1) {
            $element = self::row($this->fixture->env->database->selectOne(
                Query::from('certificate_template_elements')->where('id', '=', (int) $match[1]),
            ));
            $pageId = (int) $element['page_id'];
        } else {
            self::fail('Unexpected certificate authoring path: ' . $path);
        }
        $page = self::row($this->fixture->env->database->selectOne(
            Query::from('certificate_template_pages')->where('id', '=', $pageId),
        ));

        return (int) $page['template_id'];
    }

    private function templateRevision(int $templateId): int
    {
        return (int) self::row($this->fixture->env->database->selectOne(
            Query::from('certificate_templates')->where('id', '=', $templateId),
        ))['revision'];
    }

    /**
     * The template the free build seeds, with one certificate issued from it
     * so the criteria lock has fired.
     */
    private function issuedFromTheDefaultTemplate(): int
    {
        $templateId = $this->fixture->kernel->ensureDefaultTemplate->execute();
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

        return $templateId;
    }

    private static function describe(ApiResponse $response): string
    {
        return json_encode($response->data, \JSON_THROW_ON_ERROR);
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
