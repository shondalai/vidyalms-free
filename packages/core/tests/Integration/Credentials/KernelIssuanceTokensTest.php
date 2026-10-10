<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\EnsureDefaultTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\TemplateSnapshot;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Token\CreditTokenResolver;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The token families the shipped kernel actually seats, and what they answer.
 *
 * Every other test in this directory registers its resolvers by hand, so a
 * family that nothing registers passes them all. The free credit resolver was
 * registered nowhere in production: the free credit statement element and the
 * paid credit table both drew empty on a certificate carrying credit, and the
 * preview reported no finding because the tokens existed and merely resolved
 * to nothing.
 *
 * The three course facts were the same defect one step further on. They had a
 * reader and no writer at all, so category, grade and instructors printed
 * their raw placeholder on every certificate that named them.
 */
final class KernelIssuanceTokensTest extends TestCase
{
    public function testTheShippedKernelSeatsEveryFreeTokenFamilyTheStandardsPageNames(): void
    {
        $fixture = ManagementFixture::create();
        $keys = $fixture->kernel->registries->tokenResolvers->keys();

        // The free five of the design's token table. A key named in the
        // coding standards' free-provider table and registered by nothing is
        // a seam with no free code path that reaches it.
        foreach (['user', 'course', 'certificate', 'site', 'credit'] as $prefix) {
            self::assertContains($prefix, $keys, 'the ' . $prefix . ' token family is registered by nothing');
        }
    }

    public function testAnIssuanceFreezesTheCreditRowsAndTheThreeCourseFacts(): void
    {
        $fixture = ManagementFixture::create();
        $category = $fixture->env->courseCategories->add(7, 'Accessibility');
        $fixture->env->database->update(
            'courses',
            ['category_id' => $category->id],
            ['id' => $fixture->courseId],
        );

        $credits = $fixture->call(
            'PUT',
            '/manage/courses/' . $fixture->courseId . '/credits',
            $fixture->manager(),
            ['items' => [['scheme' => 'contact_hours', 'params' => ['contact_minutes' => 360]]]],
        );
        self::assertSame(200, $credits->status, json_encode($credits->data, JSON_THROW_ON_ERROR));

        $enrolment = $fixture->kernel->enrolLearner->execute(
            ManagementFixture::LEARNER_ID,
            $fixture->courseId,
            $fixture->entitlementId,
        );

        $fixture->env->database->insert('course_progress', [
            'enrolment_id' => $enrolment->id(),
            'lessons_total' => 3,
            'lessons_completed' => 3,
            'percent' => '86.00',
            'computed_at' => '2026-01-15 10:00:00',
            'created_at' => '2026-01-15 10:00:00',
            'updated_at' => '2026-01-15 10:00:00',
        ]);

        $certificate = $fixture->kernel->issueCertificate->execute(
            $enrolment->id(),
            ManagementFixture::LEARNER_ID,
            $fixture->courseId,
        );

        $stored = $fixture->kernel->certificateRepository->findById($certificate->id);
        self::assertNotNull($stored);

        $tokens = TemplateSnapshot::tokensOf($stored->contextSnapshot);

        self::assertArrayHasKey(CreditTokenResolver::TOKEN_ROWS, $tokens, 'the credit family froze nothing');
        self::assertSame('6', $tokens['credit.contact_hours'] ?? null);
        self::assertSame('Accessibility', $tokens['course.category'] ?? null);
        self::assertSame('86%', $tokens['course.grade'] ?? null);
        self::assertSame('Tara Teacher', $tokens['course.instructors'] ?? null);
    }

    public function testTheShippedKernelServesTheHostedAssertionTheBakedBadgePointsAt(): void
    {
        $fixture = ManagementFixture::create();

        $enrolment = $fixture->kernel->enrolLearner->execute(
            ManagementFixture::LEARNER_ID,
            $fixture->courseId,
            $fixture->entitlementId,
        );

        $certificate = $fixture->kernel->issueCertificate->execute(
            $enrolment->id(),
            ManagementFixture::LEARNER_ID,
            $fixture->courseId,
        );

        // The badge bakes {"method":"hosted","assertionUrl":"/credentials/{uuid}"}
        // and a hosted verifier dereferences that asking for JSON. On the
        // shipped kernel that answered 406, so the one portable artefact the
        // free build ships named an address no verifier could use.
        $response = $fixture->api->handle(new ApiRequest(
            'GET',
            '/credentials/' . (string) $certificate->uuid,
            Principal::guest(),
            headers: ['accept' => 'application/json'],
        ));

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->headers['Content-Type'] ?? null);

        self::assertTrue($response->isBinary(), 'a credential is served as bytes, whatever its media type');

        /** @var array<string, mixed> $document */
        $document = json_decode($response->binaryBytes(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame('https://w3id.org/openbadges/v2', $document['@context']);
        self::assertSame('Assertion', $document['type']);
        self::assertSame(['type' => 'HostedBadge'], $document['verification']);
    }

    public function testTheShippedTemplateKeyCannotBeClaimedByAnAuthoredTemplate(): void
    {
        $fixture = ManagementFixture::create();

        // On a site that upgraded from before template_key existed the
        // seeded row carries NULL, so the duplicate lookup missed and this
        // request succeeded there while a fresh install refused it: identical
        // code, two behaviours, and a shipped identifier taken by a template
        // the product does not know about.
        $fixture->env->database->update(
            'certificate_templates',
            ['template_key' => null],
            ['template_key' => EnsureDefaultTemplate::TEMPLATE_KEY],
        );

        $response = $fixture->call('POST', '/manage/certificate-templates', $fixture->manager(), [
            'name' => 'Mine',
            'template_key' => EnsureDefaultTemplate::TEMPLATE_KEY,
        ], idempotencyKey: 'reserved-template-key');

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('template_key', $response->data['errors']);
    }

    public function testTheSeededTemplateResolvesEveryPlaceholderOnAFreshInstall(): void
    {
        $fixture = ManagementFixture::create();

        $enrolment = $fixture->kernel->enrolLearner->execute(
            ManagementFixture::LEARNER_ID,
            $fixture->courseId,
            $fixture->entitlementId,
        );

        // No issuer record, no site rows and no site_logo_url option, which
        // is what a fresh install looks like on both hosts.
        $certificate = $fixture->kernel->issueCertificate->execute(
            $enrolment->id(),
            ManagementFixture::LEARNER_ID,
            $fixture->courseId,
        );

        $html = $fixture->kernel->renderCertificate->execute(
            $fixture->manager(),
            $certificate->id,
            'https://example.test/verify',
        );

        self::assertDoesNotMatchRegularExpression(
            '/\{[a-z0-9_]+\.[a-z0-9_]+\}/',
            $html,
            'the seeded template shipped a literal placeholder on a default install',
        );
        self::assertStringNotContainsString('<img', $html, 'a broken image on an evidentiary artefact');
    }

    public function testTheFreeCreditStatementRendersTheAmountItFroze(): void
    {
        $fixture = ManagementFixture::create();

        $fixture->call(
            'PUT',
            '/manage/courses/' . $fixture->courseId . '/credits',
            $fixture->manager(),
            ['items' => [['scheme' => 'contact_hours', 'params' => ['contact_minutes' => 360]]]],
        );

        $template = $fixture->call('POST', '/manage/certificate-templates', $fixture->manager(), [
            'name' => 'Credit bearing',
            'format' => 'elements',
        ], idempotencyKey: 'free-credit-template');
        self::assertSame(201, $template->status, json_encode($template->data, JSON_THROW_ON_ERROR));
        $templateId = (int) ($template->data['id'] ?? 0);

        $pages = $fixture->call(
            'GET',
            '/manage/certificate-templates/' . $templateId . '/pages',
            $fixture->manager(),
        );
        $pageId = (int) ($pages->data['items'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $pageId, json_encode($pages->data, JSON_THROW_ON_ERROR));

        $element = $fixture->call(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            $fixture->manager(),
            [
                'element' => 'credit_statement',
                'posx' => 20,
                'posy' => 120,
                'expected_revision' => (int) ($template->data['revision'] ?? 0),
            ],
            idempotencyKey: 'free-credit-element',
        );
        self::assertSame(201, $element->status, json_encode($element->data, JSON_THROW_ON_ERROR));
        $verification = $fixture->call(
            'POST',
            '/manage/certificate-template-pages/' . $pageId . '/elements',
            $fixture->manager(),
            ['element' => 'verification_code', 'expected_revision' => 2],
            idempotencyKey: 'free-credit-verification'
        );
        self::assertSame(201, $verification->status);
        $published = $fixture->call(
            'POST',
            '/manage/certificate-templates/' . $templateId . '/publish',
            $fixture->manager(),
            ['expected_revision' => 3],
            idempotencyKey: 'free-credit-publish'
        );
        self::assertSame(200, $published->status, json_encode($published->data, JSON_THROW_ON_ERROR));
        $selected = $fixture->call(
            'POST',
            '/manage/certificate-templates/' . $templateId . '/default',
            $fixture->manager(),
            ['expected_revision' => 4, 'expected_default_id' => null, 'expected_default_revision' => null],
            idempotencyKey: 'free-credit-default'
        );
        self::assertSame(200, $selected->status, json_encode($selected->data, JSON_THROW_ON_ERROR));

        $enrolment = $fixture->kernel->enrolLearner->execute(
            ManagementFixture::LEARNER_ID,
            $fixture->courseId,
            $fixture->entitlementId,
        );

        $certificate = $fixture->kernel->issueCertificate->execute(
            $enrolment->id(),
            ManagementFixture::LEARNER_ID,
            $fixture->courseId,
        );

        $html = $fixture->kernel->renderCertificate->execute(
            $fixture->manager(),
            $certificate->id,
            'https://example.test/verify',
        );

        self::assertStringContainsString('6.00 hours', $html);
        self::assertStringNotContainsString('data-vy-empty="no_credit"', $html);
    }
}
