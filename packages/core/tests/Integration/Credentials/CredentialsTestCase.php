<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateJobs;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTokens;
use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialAssembly;
use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialElementClaims;
use Shondalai\VidyaLMS\Core\Application\Credentials\EnsureDefaultTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\FreezeCredential;
use Shondalai\VidyaLMS\Core\Application\Credentials\IssuanceSnapshot;
use Shondalai\VidyaLMS\Core\Application\Credentials\IssueCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\ResolveExpiry;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Boot\CredentialProviders;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\NewCertificate;
use Shondalai\VidyaLMS\Core\Domain\Events\CertificateIssued;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCertificateRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCourseCreditRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCourseTitles;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Provider\Credential\VidyaLmsJsonFormat;
use Shondalai\VidyaLMS\Core\Provider\Credit\ContactHoursScheme;
use Shondalai\VidyaLMS\Core\Provider\Expiry\ExpiryPolicies;
use Shondalai\VidyaLMS\Core\Provider\Token\CertificateTokenResolver;
use Shondalai\VidyaLMS\Core\Provider\Token\CourseTokenResolver;
use Shondalai\VidyaLMS\Core\Provider\Token\CreditTokenResolver;
use Shondalai\VidyaLMS\Core\Provider\Token\UserTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatRegistry;
use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

abstract class CredentialsTestCase extends TestCase
{
    protected TestPorts $ports;
    protected SqlCertificateRepository $certificates;
    protected SqlCertificateTemplateRepository $templates;
    protected SqlCourseTitles $courseTitles;
    protected EventBus $eventBus;
    protected AccessGuard $guard;

    private TokenResolverRegistry $tokens;
    private CreditSchemeRegistry $schemes;
    private CertificateElementRegistry $elements;
    private ExpiryPolicyRegistry $expiryPolicyRegistry;
    private CredentialFormatRegistry $formats;
    private ?Settings $settingsService = null;
    private ?SqlCertificateCreditRepository $certificateCreditRepository = null;
    private ?SqlCourseCreditRepository $courseCreditRepository = null;
    private ?SqlCredentialStatusRepository $credentialStatusRepository = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ports = TestPorts::create();
        $this->certificates = new SqlCertificateRepository($this->ports->database, $this->ports->clock);
        $this->templates = new SqlCertificateTemplateRepository($this->ports->database, $this->ports->clock);
        $this->courseTitles = new SqlCourseTitles($this->ports->database);
        $this->eventBus = new EventBus($this->ports->eventBridge, new NullLogger());
        $this->guard = new AccessGuard($this->ports->authorization);
    }

    protected function issueCertificateUseCase(): IssueCertificate
    {
        return new IssueCertificate(
            $this->certificates,
            $this->templates,
            $this->ensureDefaultTemplateUseCase(),
            $this->eventBus,
            $this->ports->clock,
            $this->ports->random,
            $this->ports->database,
        );
    }

    /**
     * The whole credential stack: the freeze, the credit rows and the status
     * list allocation, wired the way the kernel wires them.
     *
     * It is a second builder rather than a parameter on the first because the
     * unfrozen path is a real code path (it is what every row issued before
     * the snapshot existed looks like) and both have to stay under test.
     */
    protected function issueWithSnapshot(bool $published = true): IssueCertificate
    {
        return new IssueCertificate(
            $this->certificates,
            $this->templates,
            $this->ensureDefaultTemplateUseCase(),
            $this->eventBus,
            $this->ports->clock,
            $this->ports->random,
            $this->ports->database,
            null,
            $this->issuanceSnapshot(),
            $this->courseCredits(),
            $this->certificateCredits(),
            $this->credentialStatus(),
            $published,
            $this->resolveExpiry(),
            $this->certificateJobs(),
            $this->freezeCredential(),
        );
    }

    protected function issuanceSnapshot(): IssuanceSnapshot
    {
        return new IssuanceSnapshot(
            $this->templates,
            new CertificateTokens($this->tokenResolvers()),
            $this->ports->users,
            $this->courseTitles,
            $this->creditSchemes(),
            $this->ports->random,
            'https://example.test/verify',
            $this->ports->locale,
            'UTC',
            $this->settings(),
        );
    }

    protected function settings(): Settings
    {
        return $this->settingsService ??= new Settings(
            new DatabaseSettingsRepository($this->ports->database, $this->ports->clock),
        );
    }

    protected function expiryPolicies(): ExpiryPolicyRegistry
    {
        if (!isset($this->expiryPolicyRegistry)) {
            $this->expiryPolicyRegistry = new ExpiryPolicyRegistry();

            foreach (ExpiryPolicies::all($this->ports->translator) as $policy) {
                $this->expiryPolicyRegistry->register($policy);
            }
        }

        return $this->expiryPolicyRegistry;
    }

    protected function resolveExpiry(): ResolveExpiry
    {
        return new ResolveExpiry($this->expiryPolicies());
    }

    protected function certificateJobs(): CertificateJobs
    {
        return new CertificateJobs($this->ports->scheduler, $this->ports->clock);
    }

    protected function credentialFormats(): CredentialFormatRegistry
    {
        if (!isset($this->formats)) {
            $this->formats = new CredentialFormatRegistry();
            $this->formats->register(new VidyaLmsJsonFormat($this->ports->translator));
        }

        return $this->formats;
    }

    protected function credentialAssembly(): CredentialAssembly
    {
        return new CredentialAssembly(
            $this->courseTitles,
            $this->ports->clock,
            $this->certificateCredits(),
            $this->credentialStatus(),
            $this->templates,
            $this->settings(),
            'https://example.test/api/v1/credentials',
            'https://example.test/verify',
            'https://example.test/api/v1/credentials/status',
            new CredentialElementClaims($this->elementRegistry()),
        );
    }

    protected function freezeCredential(): FreezeCredential
    {
        return new FreezeCredential(
            $this->certificates,
            $this->credentialFormats(),
            $this->credentialAssembly(),
        );
    }

    protected function tokenResolvers(): TokenResolverRegistry
    {
        if (!isset($this->tokens)) {
            $this->tokens = new TokenResolverRegistry();
            $this->tokens->register(new UserTokenResolver($this->ports->users));
            $this->tokens->register(new CourseTokenResolver($this->ports->database, 'https://example.test'));
            $this->tokens->register(new CertificateTokenResolver('https://example.test/verify'));
            $this->tokens->register(new CreditTokenResolver());
        }

        return $this->tokens;
    }

    protected function creditSchemes(): CreditSchemeRegistry
    {
        if (!isset($this->schemes)) {
            $this->schemes = new CreditSchemeRegistry();
            $this->schemes->register(new ContactHoursScheme($this->ports->translator));
        }

        return $this->schemes;
    }

    protected function elementRegistry(): CertificateElementRegistry
    {
        if (!isset($this->elements)) {
            $this->elements = new CertificateElementRegistry();

            foreach (CredentialProviders::elements() as $element) {
                $this->elements->register($element);
            }
        }

        return $this->elements;
    }

    protected function certificateCredits(): SqlCertificateCreditRepository
    {
        return $this->certificateCreditRepository ??= new SqlCertificateCreditRepository(
            $this->ports->database,
            $this->ports->clock,
        );
    }

    protected function courseCredits(): SqlCourseCreditRepository
    {
        return $this->courseCreditRepository ??= new SqlCourseCreditRepository(
            $this->ports->database,
            $this->ports->clock,
        );
    }

    protected function credentialStatus(): SqlCredentialStatusRepository
    {
        return $this->credentialStatusRepository ??= new SqlCredentialStatusRepository(
            $this->ports->database,
            $this->ports->clock,
            $this->ports->random,
        );
    }

    /**
     * A template whose renderer walks pages and elements, with one page and
     * whatever elements the test needs.
     *
     * @param list<CertificateTemplateElement> $elements
     */
    protected function seedElementsTemplate(array $elements, bool $isDefault = true): int
    {
        $templateId = $this->templates->insert(
            'Designed',
            'elements',
            '',
            $isDefault,
            'published',
            'test.elements',
            'elements',
        );

        $pageId = $this->templates->insertPage(new CertificateTemplatePage(0, $templateId));

        foreach ($elements as $element) {
            $this->templates->insertElement($pageId, $element);
        }

        return $templateId;
    }

    protected function seedCourseCredit(int $courseId, int $contactMinutes, ?string $fieldOfStudy = null): void
    {
        $this->courseCredits()->save(new CourseCredit(
            $courseId,
            ContactHoursScheme::KEY,
            array_filter([
                'contact_minutes' => $contactMinutes,
                'field_of_study' => $fieldOfStudy,
            ], static fn (mixed $value): bool => $value !== null),
            1,
            1,
        ));
    }

    /**
     * Seeds through the real catalogue copy, so the default body carries the
     * English prose and the literal DATA placeholders that RenderCertificate
     * substitutes at render time.
     */
    protected function ensureDefaultTemplateUseCase(): EnsureDefaultTemplate
    {
        return new EnsureDefaultTemplate($this->templates, new CatalogueTranslator());
    }

    protected function seedCourse(int $id, string $title): void
    {
        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');

        $this->ports->database->insert('courses', [
            'id' => $id,
            'title' => $title,
            'slug' => 'course-' . $id,
            'created_by' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function seedEnrolment(int $id, int $userId, int $courseId): void
    {
        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');

        $this->ports->database->insert('enrolments', [
            'id' => $id,
            'user_id' => $userId,
            'course_id' => $courseId,
            'entitlement_id' => 1,
            'status' => 'active',
            'enrolled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return list<CertificateIssued>
     */
    protected function certificateIssuedEvents(): array
    {
        return array_values(array_filter(
            $this->ports->eventBridge->published,
            static fn (DomainEvent $event): bool => $event instanceof CertificateIssued,
        ));
    }

    /**
     * One certificate written straight through the repository, for a test
     * that is arranging state rather than exercising issuance.
     *
     * The draft object exists because the row carries provenance, lifecycle,
     * credential and idempotency blocks and a twenty-argument insert is a
     * call nobody can read.
     */
    protected function writeCertificate(
        int $userId,
        int $courseId,
        int $enrolmentId,
        string $code,
        ?\DateTimeImmutable $expiresAt = null,
        int $templateId = 1,
        ?\DateTimeImmutable $issuedAt = null,
        bool $published = true,
    ): Certificate {
        return $this->certificates->insert(new NewCertificate(
            $userId,
            $courseId,
            $enrolmentId,
            $templateId,
            $code,
            'uuid-' . strtolower($code),
            $issuedAt ?? $this->ports->clock->now(),
            null,
            null,
            $expiresAt,
            null,
            null,
            null,
            'html',
            null,
            // Issued under a site that publishes learner names, which is the
            // shipped default and what the verification page reads.
            $published,
        ));
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>
     */
    protected static function row(?array $row): array
    {
        if ($row === null) {
            self::fail('Expected a database row, found none.');
        }

        return $row;
    }
}
