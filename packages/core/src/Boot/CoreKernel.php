<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Boot;

use Shondalai\VidyaLMS\Core\Application\Access\AccessGrantView;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityCatalogue;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityComposer;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\ManageAvailabilityGrants;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\ManageAvailabilityRule;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\PreviewAvailabilityRule;
use Shondalai\VidyaLMS\Core\Http\Handler\Availability\AvailabilityGrantsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\Availability\AvailabilityRuleHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\Availability\ListAvailabilityConditionsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\Availability\PreviewAvailabilityHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\Availability\RevokeAvailabilityGrantHandler;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityFactLoaders;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityRuleValidator;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\EntitlementFactLoader;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\GradeFactLoader;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\ProgressFactLoader;
use Shondalai\VidyaLMS\Core\Application\Notification\ConditionsStillHold;
use Shondalai\VidyaLMS\Core\Application\Notification\EnsureDefaultNotifications;
use Shondalai\VidyaLMS\Core\Application\Notification\ManageProviderSettings;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationAdmin;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationCatalogue;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationPreferenceCentre;
use Shondalai\VidyaLMS\Core\Application\Notification\RecordDeliveryFeedback;
use Shondalai\VidyaLMS\Core\Application\Notification\Unsubscribe;
use Shondalai\VidyaLMS\Core\Http\Handler\Notification\DeliveryFeedbackHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\Notification\MyNotificationPreferencesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\Notification\NotificationAdminHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\Notification\ProviderSettingsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\Notification\UnsubscribeHandler;
use Shondalai\VidyaLMS\Core\Http\Middleware\NoIndexMiddleware;
use Shondalai\VidyaLMS\Core\Http\Middleware\RateLimitMiddleware;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationEnqueue;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationFanout;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationJobs;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationRenderer;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateCompiler;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateStudio;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateTestDelivery;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;
use Shondalai\VidyaLMS\Core\Http\Handler\Notification\NotificationTemplateStudioHandler;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationScheduleResolver;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationSender;
use Shondalai\VidyaLMS\Core\Application\Notification\TransactionalOutbox;
use Shondalai\VidyaLMS\Core\Application\Notification\UnsubscribeTokens;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityGrantRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationConsentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationSuppressionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\ChannelConfirmationPolicy;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\ConsentPolicy;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\CrossRulePolicy;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\DuplicateWindowPolicy;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\FrequencyCapPolicy;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\PreferencePolicy;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\QuietHoursPolicy;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\SuppressionPolicy;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationFanoutTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationSendTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationSweepTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\UnlockNoticeTask;
use Shondalai\VidyaLMS\Core\Application\Access\EnrolLearner;
use Shondalai\VidyaLMS\Core\Application\Access\EnrolLearnerManually;
use Shondalai\VidyaLMS\Core\Application\Access\EnrolmentListing;
use Shondalai\VidyaLMS\Core\Application\Access\ExpireEntitlements;
use Shondalai\VidyaLMS\Core\Application\Access\ExtendEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\ListCourseLearners;
use Shondalai\VidyaLMS\Core\Application\Access\ListEnrolmentsForCourse;
use Shondalai\VidyaLMS\Core\Application\Access\ListMyEnrolments;
use Shondalai\VidyaLMS\Core\Application\Access\ListSiteEnrolments;
use Shondalai\VidyaLMS\Core\Application\Access\ActivateEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\HoldEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlementBySourceRef;
use Shondalai\VidyaLMS\Core\Application\Commerce\BackgroundActorResolver;
use Shondalai\VidyaLMS\Core\Application\Access\SelfEnrol;
use Shondalai\VidyaLMS\Core\Application\Assessment\AddQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\AttemptManifest;
use Shondalai\VidyaLMS\Core\Application\Assessment\AttemptRegrader;
use Shondalai\VidyaLMS\Core\Application\Assessment\BuildXapiStatements;
use Shondalai\VidyaLMS\Core\Application\Assessment\CreateStimulus;
use Shondalai\VidyaLMS\Core\Application\Assessment\GetCourseReport;
use Shondalai\VidyaLMS\Core\Application\Assessment\GetItemAnalysisReport;
use Shondalai\VidyaLMS\Core\Application\Assessment\GetQuizSettingsSchema;
use Shondalai\VidyaLMS\Core\Application\Assessment\GetStimulus;
use Shondalai\VidyaLMS\Core\Application\Assessment\ListIntegrityEvents;
use Shondalai\VidyaLMS\Core\Application\Assessment\ListStimuli;
use Shondalai\VidyaLMS\Core\Application\Assessment\RecordIntegrityEvent;
use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeAttempt;
use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\RemoveStimulus;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateStimulus;
use Shondalai\VidyaLMS\Core\Application\Assessment\QuestionTypes;
use Shondalai\VidyaLMS\Core\Application\Assessment\QuizPublicationGate;
use Shondalai\VidyaLMS\Core\Application\Assessment\CreateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\GetAttemptForLearner;
use Shondalai\VidyaLMS\Core\Application\Assessment\LearnerAccessibilityProfile;
use Shondalai\VidyaLMS\Core\Application\Assessment\SignedMedia;
use Shondalai\VidyaLMS\Core\Application\Assessment\GetQuizAuthoring;
use Shondalai\VidyaLMS\Core\Application\Assessment\ListQuizzesForCourse;
use Shondalai\VidyaLMS\Core\Application\Assessment\ManualGradingStatus;
use Shondalai\VidyaLMS\Core\Application\Assessment\QuizViews;
use Shondalai\VidyaLMS\Core\Application\Assessment\GradeAnswerManually;
use Shondalai\VidyaLMS\Core\Application\Assessment\RemoveQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\ReorderQuestions;
use Shondalai\VidyaLMS\Core\Application\Assessment\SaveAnswers;
use Shondalai\VidyaLMS\Core\Application\Assessment\StartAttempt;
use Shondalai\VidyaLMS\Core\Application\Assessment\SubmitAttempt;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assignments\ApplyLatePolicy;
use Shondalai\VidyaLMS\Core\Application\Assignments\ConfigureAssignment;
use Shondalai\VidyaLMS\Core\Application\Assignments\GetMySubmissions;
use Shondalai\VidyaLMS\Core\Application\Assignments\GradeSubmission;
use Shondalai\VidyaLMS\Core\Application\Assignments\ListSubmissionsForAssignment;
use Shondalai\VidyaLMS\Core\Application\Assignments\SubmitAssignment;
use Shondalai\VidyaLMS\Core\Application\Commerce\CommercePrices;
use Shondalai\VidyaLMS\Core\Application\Credentials\AddCertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Application\Credentials\AddCertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Application\Credentials\ArchiveCertificateTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\BackfillCertificates;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateElementRule;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateExpiryRule;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateJobs;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateAggregate;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateChecks;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateViews;
use Shondalai\VidyaLMS\Core\Application\Credentials\CreateCertificateTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\DeleteCertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Application\Credentials\DeleteCertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Application\Credentials\GetCertificateTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\ListCertificateTemplateElements;
use Shondalai\VidyaLMS\Core\Application\Credentials\ListCertificateTemplatePages;
use Shondalai\VidyaLMS\Core\Application\Credentials\ListCertificateTemplates;
use Shondalai\VidyaLMS\Core\Application\Credentials\PreviewCertificateTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReorderCertificateTemplateElements;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReorderCertificateTemplatePages;
use Shondalai\VidyaLMS\Core\Application\Credentials\UpdateCertificateTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\UpdateCertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Application\Credentials\UpdateCertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTokens;
use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialAssembly;
use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialElementClaims;
use Shondalai\VidyaLMS\Core\Application\Credentials\EnsureDefaultTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\FreezeCredential;
use Shondalai\VidyaLMS\Core\Application\Credentials\GetCredential;
use Shondalai\VidyaLMS\Core\Application\Credentials\IssuerIdentity;
use Shondalai\VidyaLMS\Core\Application\Credentials\ResolveExpiry;
use Shondalai\VidyaLMS\Core\Application\Credentials\IssuanceSnapshot;
use Shondalai\VidyaLMS\Core\Application\Credentials\IssueCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificatesForTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\SuspendCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\IssueCertificateOnCourseCompletion;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecords;
use Shondalai\VidyaLMS\Core\Application\Credentials\ListCertificates;
use Shondalai\VidyaLMS\Core\Application\Credentials\ListMyCertificates;
use Shondalai\VidyaLMS\Core\Application\Credentials\RenderCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\RetireCredential;
use Shondalai\VidyaLMS\Core\Application\Credentials\RevokeCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\VerifyCertificate;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddAuthoredLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ChangeCourseLifecycle;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveModule;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AssignInstructor;
use Shondalai\VidyaLMS\Core\Application\Curriculum\CreateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AuthoringLessonView;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetAuthoringCurriculum;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetAuthoringLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCatalogue;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCourseBySlug;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCourseCategory;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCurriculum;
use Shondalai\VidyaLMS\Core\Application\Curriculum\LessonAvailability;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetLessonForLearner;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetManagedCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ListInstructors;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ListCourseCategories;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ListManagedCourses;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ManagedCourseView;
use Shondalai\VidyaLMS\Core\Application\Curriculum\MoveLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\PublishCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\RemoveInstructor;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ReorderLessons;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ReorderModules;
use Shondalai\VidyaLMS\Core\Application\Curriculum\SetPrerequisites;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateModule;
use Shondalai\VidyaLMS\Core\Application\Platform\AbandonImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\ActivityLogProjector;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Platform\FeatureProviderLedger;
use Shondalai\VidyaLMS\Core\Application\Platform\GetGradebook;
use Shondalai\VidyaLMS\Core\Application\Platform\GetImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\GetLearnerRecord;
use Shondalai\VidyaLMS\Core\Application\Platform\ListLearners;
use Shondalai\VidyaLMS\Core\Application\Platform\GetPlatformSettings;
use Shondalai\VidyaLMS\Core\Application\Platform\GradebookQueue;
use Shondalai\VidyaLMS\Core\Application\Platform\HostFact;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportIntake;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportRunner;
use Shondalai\VidyaLMS\Core\Application\Platform\ListImportJobs;
use Shondalai\VidyaLMS\Core\Application\Platform\ListProviderTypes;
use Shondalai\VidyaLMS\Core\Application\Platform\AuthoriseUpload;
use Shondalai\VidyaLMS\Core\Application\Platform\PruneImportSources;
use Shondalai\VidyaLMS\Core\Application\Platform\QueueImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\RecordSchemaVersion;
use Shondalai\VidyaLMS\Core\Application\Platform\RunImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\RunQueuedImport;
use Shondalai\VidyaLMS\Core\Application\Platform\SearchUsers;
use Shondalai\VidyaLMS\Core\Application\Platform\SendTransactionalMail;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Platform\SiteOverview;
use Shondalai\VidyaLMS\Core\Application\Progress\CompleteAssignmentLessonOnGrade;
use Shondalai\VidyaLMS\Core\Application\Progress\CompleteQuizLessonOnAttempt;
use Shondalai\VidyaLMS\Core\Application\Progress\GetLearnerCourseProgress;
use Shondalai\VidyaLMS\Core\Application\Progress\GetMyCourseProgress;
use Shondalai\VidyaLMS\Core\Application\Progress\GradeOutcomeReader;
use Shondalai\VidyaLMS\Core\Application\Progress\RecomputeCourseProgress;
use Shondalai\VidyaLMS\Core\Application\Progress\RecomputeCourseProgressForCourse;
use Shondalai\VidyaLMS\Core\Application\Progress\RecordLessonProgress;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCreditRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CreditSchemeIdentityRepository;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptGraded;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptSubmitted;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionGraded;
use Shondalai\VidyaLMS\Core\Domain\Progress\CompletionEngine;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\Handler\AddLessonHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\AddModuleHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\AddQuestionHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ArchiveCourseHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ChangeCourseLifecycleHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ArchiveLessonHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ArchiveModuleHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\AssignInstructorHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ConfigureAssignmentHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CreateCourseHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CreateQuizHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\EnrolLearnerHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ExtendEntitlementHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetAdminDashboardHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CreateStimulusHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetAttemptHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetAttemptXapiHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetCourseReportHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetItemAnalysisHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetQuizSettingsSchemaHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetStimulusHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListIntegrityEventsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListStimuliHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\RecordIntegrityEventHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\RegradeAttemptHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\RegradeQuizHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\RemoveStimulusHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\UpdateStimulusHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetAuthoringCurriculumHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetAuthoringLessonHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetCatalogueHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetCourseHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetCourseCategoryHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetCurriculumHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetGradebookHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetImportJobHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetLearnerCourseProgressHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetLearnerRecordHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetLessonHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetManagedCourseHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetMyProgressHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetPlatformSettingsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetQuizAuthoringHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GradeAnswerHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GradeSubmissionHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\Handler\ListAdminEnrolmentsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListAdminLearnersHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CertificateTemplateElementHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CertificateTemplateElementsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CertificateTemplateHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CertificateTemplatePageHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CertificateTemplatePagesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CertificateTemplatesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CourseCreditsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\CreditIdentitiesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\PreviewCertificateTemplateHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\DownloadMyCertificateHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetCertificateHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetCredentialHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\GetCredentialStatusListHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListCertificateElementTypesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListCertificatesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListCreditSchemesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ReissueCertificateHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ReissueTemplateHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\SuspendCertificateHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListCourseLearnersHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListCourseCategoriesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListImportJobsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListInstructorsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListLessonTypesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListManagedCoursesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListMyCertificatesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListMyEnrolmentsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListQuestionTypesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ListQuizzesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\MoveLessonHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\PublishCourseHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\RecordLessonProgressHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\RemoveInstructorHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\RemoveQuestionHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ReorderLessonsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ReorderModulesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\ReorderQuestionsHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\RevokeCertificateHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\RevokeEntitlementHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\SaveAnswersHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\SearchUsersHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\SelfEnrolHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\SetPrerequisitesHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\StartAttemptHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\StartImportHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\SubmitAssignmentHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\SubmitAttemptHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\UpdateCourseHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\UpdateLessonHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\UpdateModuleHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\UpdateQuestionHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\UpdateQuizHandler;
use Shondalai\VidyaLMS\Core\Http\Handler\VerifyCertificateHandler;
use Shondalai\VidyaLMS\Core\Http\Route;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEnrolmentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEntitlementRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseAttemptQuestionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseAttemptRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseIntegrityEventRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseQuestionDefinitionStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseQuestionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseQuizRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseStimulusRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assignments\DatabaseAssignmentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assignments\DatabaseSubmissionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCertificateRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCourseCreditRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCourseTitles;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCreditSchemeIdentityRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlIssuanceCourseFacts;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseCourseRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseLessonRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseModuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseActivityLogRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseEmailLogRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseFeatureProviderRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseGradebookRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportJobRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportMapRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportRowProblemRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSchemaVersionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSiteMetricsRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress\SqlCourseProgressRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress\SqlLessonProgressRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress\SqlVideoPositionRepository;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Provider\Certificate\HtmlCertificateRenderer;
use Shondalai\VidyaLMS\Core\Provider\Composition\PublishedQuestionsComposition;
use Shondalai\VidyaLMS\Core\Provider\Commerce\CmsShopCommerceSource;
use Shondalai\VidyaLMS\Core\Provider\EnrolmentRule\ManualEnrolmentRule;
use Shondalai\VidyaLMS\Core\Provider\EnrolmentRule\OpenEnrolmentRule;
use Shondalai\VidyaLMS\Core\Provider\Importer\CommunityQuizImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\Guru\GuruImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\CurriculumWriter;
use Shondalai\VidyaLMS\Core\Provider\Importer\JsonCourseImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\LearnDashImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\LifterLmsImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\MoodleBackupImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\OSCampus\OSCampusImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\Roster\RosterImporter;
use Shondalai\VidyaLMS\Core\Provider\Organisation\StandardOrgRoleMapper;
use Shondalai\VidyaLMS\Core\Provider\Roster\OneRosterCsvMapper;
use Shondalai\VidyaLMS\Core\Provider\Roster\OrgMembersCsvMapper;
use Shondalai\VidyaLMS\Core\Registry\Context\RoleMappingContext;
use Shondalai\VidyaLMS\Core\Provider\Importer\Shika\ShikaImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\TutorLmsImporter;
use Shondalai\VidyaLMS\Core\Provider\LessonType\AssignmentLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\QuizLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\ReadingLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\ResourceLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\VideoLessonType;
use Shondalai\VidyaLMS\Core\Provider\Availability\CompletionCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\EnrolmentSourceCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\RoleCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\ScoreCondition;
use Shondalai\VidyaLMS\Core\Provider\LatePolicy\NoLatePolicy;
use Shondalai\VidyaLMS\Core\Provider\Notification\Audience\CourseInstructorsAudience;
use Shondalai\VidyaLMS\Core\Provider\Notification\Audience\LearnerAudience;
use Shondalai\VidyaLMS\Core\Provider\Notification\Audience\SiteManagersAudience;
use Shondalai\VidyaLMS\Core\Provider\Notification\EmailNotificationChannel;
use Shondalai\VidyaLMS\Core\Provider\Notification\Feedback\Rfc5965FeedbackMapper;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\AttemptGradedTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\CertificateIssuedTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\ContentUnlockedTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\CourseCompletedTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\EnrolledTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\EntitlementExpiringSweep;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\SubmissionReceivedTrigger;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\AbsoluteAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\CourseStartAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\EnrolmentAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\EntitlementStartAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\LaterOfEnrolmentAndCourseStartAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\RelativeAnchor;
use Shondalai\VidyaLMS\Core\Provider\Token\CertificateTokenResolver;
use Shondalai\VidyaLMS\Core\Provider\Token\CohortTokenResolver;
use Shondalai\VidyaLMS\Core\Provider\Token\CourseTokenResolver;
use Shondalai\VidyaLMS\Core\Provider\Token\ReportTokenResolver;
use Shondalai\VidyaLMS\Core\Provider\Token\SiteTokenResolver;
use Shondalai\VidyaLMS\Core\Provider\Token\UnsubscribeTokenResolver;
use Shondalai\VidyaLMS\Core\Provider\Token\UserTokenResolver;
use Shondalai\VidyaLMS\Core\Application\Access\CollectAccessEvidence;
use Shondalai\VidyaLMS\Core\Application\Reporting\GetOrganisationReport;
use Shondalai\VidyaLMS\Core\Application\Reporting\GetReportRun;
use Shondalai\VidyaLMS\Core\Application\Reporting\GetSiteReport;
use Shondalai\VidyaLMS\Core\Application\Reporting\ReportExportPipeline;
use Shondalai\VidyaLMS\Core\Application\Reporting\StartReportExport;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report\ReportRunStore;
use Shondalai\VidyaLMS\Core\Provider\ExportFormat\CsvExportFormat;
use Shondalai\VidyaLMS\Core\Provider\ExportFormat\JsonExportFormat;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\MeanGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\NaturalGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\Privacy\CoreReportingPrivacy;
use Shondalai\VidyaLMS\Core\Provider\Report\CourseOverviewReport;
use Shondalai\VidyaLMS\Core\Provider\Report\EnrolmentRosterReport;
use Shondalai\VidyaLMS\Core\Provider\Report\OrgAccessLogReport;
use Shondalai\VidyaLMS\Core\Provider\Report\SiteLearningReport;
use Shondalai\VidyaLMS\Core\Provider\ReportDelivery\DownloadDelivery;
use Shondalai\VidyaLMS\Core\Provider\ReportDelivery\NotificationLinkDelivery;
use Shondalai\VidyaLMS\Core\Provider\StatementForwarder\LogStatementForwarder;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\SweepReportRunsTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\BackfillCertificatesTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ExpireEntitlementsTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\GenerateReportTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\RecomputeCourseProgressTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\RecomputeEnrolmentProgressTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ReissueCertificateTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ReissueCertificatesTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\RenderCertificateTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\RunImportTask;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The single composition root of the free build. An adapter constructs its
 * Ports bundle, hands it here, and receives every use case fully wired, the
 * registries for Pro and third-party packages to extend, and the routed
 * ApiKernel serving the /v1 contract.
 */
final class CoreKernel
{
    /** Increment only when the published provider interfaces change incompatibly. */
    public const PROVIDER_CONTRACT = 2;
    public readonly \Shondalai\VidyaLMS\Core\Application\Progress\RecordLogin $recordLogin;
    private const PACKAGE = 'vidyalms-core';

    /**
     * The routes that answer without a session, and therefore the ones the
     * rate limiter and the noindex header cover.
     *
     * A verification code is short by design so a person can read it off a
     * certificate, and an unsubscribe token is a signed guess away from
     * somebody else's preferences. Neither is behind a session, so the only
     * thing between them and a walk of the key space is core middleware: the
     * same control written once per adapter is the same control written
     * differently twice.
     *
     * @var list<string>
     */
    private const UNAUTHENTICATED_PREFIXES = [
        '/certificates/',
        // A credential id is a uuid rather than a short code, so walking the
        // key space is not the threat here; unmetered dereferencing of every
        // credential a site has ever issued is, and the noindex header
        // matters for the same reason it does on the verification page.
        '/credentials/',
        '/notifications/unsubscribe/',
        '/notifications/feedback/',
    ];

    /**
     * The automation step scope, whose two windows are the ones the design
     * names as wrong for this caller under the shipped defaults.
     *
     * The claim window is the queue lease horizon rather than a wall clock,
     * so a step that claims and then dies with the host process is released
     * through the queue reclaim path and re-executed exactly once. At the
     * shipped PT1H default it would be reclaimed after an hour, which is the
     * duplicate side effect the ledger exists to prevent, and both hosts are
     * documented multi-firers so that path is the normal case.
     *
     * The outcome window is thirty days, which is at least the external retry
     * ladder total (immediate, 5s, 5m, 30m, 2h, 5h, 10h, 10h, whose eighth
     * attempt lands past twenty-seven hours) plus the maximum delay a step may
     * carry. At the shipped PT24H default the eighth attempt arrives past the
     * outcome expiry and the effect runs twice once a prune task exists.
     *
     * @var array<string, string>
     */
    private const AUTOMATION_CLAIM_TTLS = ['automation.action' => 'PT5M'];

    /** @var array<string, string> */
    private const AUTOMATION_OUTCOME_TTLS = ['automation.action' => 'P30D'];

    public readonly Registries $registries;

    /**
     * The wire shapes of the management surface, one presenter per bounded
     * context, and the decorator chain a package adds a field through.
     */
    public readonly PresenterRegistry $presenters;
    public readonly AccessGuard $guard;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisations $organisations;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers $organisationMembers;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver $organisationAccess;

    /**
     * The rule in front of the resolver, published because a route registered
     * outside the organisation route files needs to authorise a tenant too:
     * the organisation report catalogue is registered in apiKernel(), where
     * the constructor local this used to be is out of scope.
     */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess $organisationAccessPolicy;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationPrivacy $organisationPrivacy;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ExportOrganisationAudit $organisationAuditExport;
    public readonly ?\Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationInvitations $organisationInvitations;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\EncryptedInvitationDelivery $invitationDelivery;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationLearning $organisationLearning;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationSeats $organisationSeats;

    /** Reserve at issue, and the two ways a reservation ends. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\SeatReservations $seatReservations;

    /** The three seat threshold alerts, raised from one place by two kinds of caller. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\SeatThresholdAlerts $seatThresholdAlerts;

    /** One acquiring event, then one releasing event, inside one transaction. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\TransferSeat $transferSeat;
    /**
     * The receipt of every chain-preserving ledger pseudonymisation, and the
     * verifier that tells one from an edit.
     */
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\LedgerRedactionStore $ledgerRedactions;
    /** Putting a compliance requirement in front of a person, or saying why not. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ComplianceAssignments $complianceAssignments;
    /** Writing the requirement down, which is what gave the line above a caller. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationCompliance $organisationCompliance;
    /** The organisation's selection from the courses a site owner released to it. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationCatalogue $organisationCatalogue;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits $organisationUnits;
    /** The organisation-scoped import surface, which is what makes "adds people by CSV" reachable. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationImports $organisationImports;
    /** Issuer claims, mapping rules and the links they produce. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationConnections $organisationConnections;
    /** Domain claims and join rules: the two halves of how somebody gets in. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationJoining $organisationJoining;
    /** The one writer of org_memberships.directory_managed. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ApplyProvisioningAssertion $applyProvisioningAssertion;

    /**
     * The session-resolution path a host adapter calls after it authenticates
     * somebody, and the first reader Ports::$assertedIdentity has ever had.
     */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\ApplyHostAssertion $applyHostAssertion;

    /** The one reader of an organisation own retention policy. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRetentionPolicies $organisationRetention;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningEvents $organisationLearningEvents;
    /** The append-only read log. Public because the report over it is a later wave's and needs the same instance. */
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogStore $adminAccessLog;
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore $organisationStore;
    /** The organisation this request turned out to be about, pinned once by OrgScopeMiddleware. */
    public readonly \Shondalai\VidyaLMS\Core\Http\Middleware\ActiveOrganisation $activeOrganisation;
    public readonly CourseAccess $courseAccess;
    public readonly EventBus $events;
    public readonly DatabaseIdempotencyStore $idempotencyStore;
    public readonly FeatureProviderLedger $featureProviders;

    /**
     * The one resolver of question type providers, exposed so an adapter can
     * ask the same question the core asks and get the same registry-miss
     * answer rather than reimplementing the contract.
     */
    public readonly QuestionTypes $questionTypeResolver;

    /** The frozen served set of an attempt, written once at StartAttempt. */
    public readonly AttemptManifest $attemptManifest;
    public readonly Settings $settings;

    /**
     * Who this site issues credentials as, resolved from the settings record
     * over the host facts. Public because the settings screen writes it and
     * the verification page, the credential formats and the site tokens all
     * read it, and because two answers to "who issued this" is one too many.
     */
    public readonly IssuerIdentity $issuerIdentity;
    public readonly string $verificationBaseUrl;

    /**
     * Where a credential dereferences and where its status list is published.
     *
     * Both are here rather than composed at each call site because a
     * credential id is printed into a document that outlives every request
     * that produced it, and two call sites composing it differently is how a
     * wallet ends up holding an id nothing resolves.
     */
    public readonly string $credentialBaseUrl;
    public readonly string $statusListBaseUrl;

    /**
     * The public base of this site's unsubscribe landing page, from the host,
     * before the stored override is consulted.
     */
    public readonly string $unsubscribeBaseUrl;

    /**
     * The domain a bounce is addressed back to, derived by the adapter from
     * the site's own from address and never from the request host. The
     * channel's provider settings row overrides it.
     */
    public readonly string $envelopeDomain;
    public readonly ListingPolicy $listingPolicy;

    /**
     * The restriction engine and the objects around it.
     *
     * Public because both adapters and a paid package read them: an adapter
     * enforcing availability in its own search indexer needs the same answer
     * the player gets, and a policy guard written in an adapter instead of a
     * provider covers only the routes that adapter happens to own.
     */
    public readonly AvailabilityEngine $availability;
    public readonly AvailabilityComposer $availabilityComposer;
    public readonly AvailabilityFactLoaders $availabilityFactLoaders;
    public readonly AvailabilityRuleValidator $availabilityValidator;
    public readonly AvailabilityCatalogue $availabilityCatalogue;
    public readonly ManageAvailabilityRule $manageAvailabilityRule;
    public readonly ManageAvailabilityGrants $manageAvailabilityGrants;
    public readonly PreviewAvailabilityRule $previewAvailabilityRule;
    public readonly SqlAvailabilityRuleRepository $availabilityRules;
    public readonly SqlAvailabilityGrantRepository $availabilityGrants;

    /** The notification engine, exposed for the same reason. */
    public readonly NotificationEnqueue $notificationEnqueue;
    public readonly NotificationJobs $notificationJobs;
    public readonly NotificationFanout $notificationFanout;
    public readonly NotificationSender $notificationSender;
    public readonly NotificationRenderer $notificationRenderer;
    public readonly NotificationTemplateCompiler $notificationTemplateCompiler;
    public readonly NotificationTemplateBlueprintCatalogue $notificationBlueprints;
    public readonly NotificationTemplateStudio $notificationTemplateStudio;
    public readonly NotificationTemplateTestDelivery $notificationTemplateTestDelivery;
    public readonly UnsubscribeTokens $unsubscribeTokens;
    public readonly EnsureDefaultNotifications $ensureDefaultNotifications;
    public readonly NotificationAdmin $notificationAdmin;
    public readonly NotificationCatalogue $notificationCatalogue;
    public readonly NotificationPreferenceCentre $notificationPreferenceCentre;
    public readonly Unsubscribe $unsubscribe;
    public readonly RecordDeliveryFeedback $recordDeliveryFeedback;
    public readonly ManageProviderSettings $manageProviderSettings;
    public readonly SqlNotificationQueueRepository $notificationQueue;

    /**
     * The free automation engine.
     *
     * It reuses five parts of the notification spine unchanged: the trigger
     * registry with its event and sweep split, the availability condition tree
     * on the Automation surface, the transactional outbox pattern with its
     * claim triple, the idempotency store, and the queue's own reclaim
     * discipline. Exactly three things are new here: an action registry, a
     * rules table and the machinery between them.
     */
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository $automationRules;
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRunRepository $automationRuns;
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationQueueRepository $automationQueue;
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationActionLogRepository $automationActionLog;
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleTestRepository $automationRuleTests;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\AutomationLoopGuard $automationLoops;
    /**
     * The frame stack the engine pushes before it calls an action, so a run
     * opened from inside one inherits the cascade whether or not the action
     * says so. It is the kernel's because both the step machine and the
     * opener must hold the same one.
     */
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\AutomationCascade $automationCascade;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\AutomationJobs $automationJobs;
    /** The automation half of the transactional outbox, filled once the engine exists. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\AutomationOutbox $automationOutbox;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\OpenAutomationRuns $openAutomationRuns;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\ReleaseAutomationStep $releaseAutomationStep;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\ManageAutomationRules $manageAutomationRules;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationFanout $automationFanout;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationStep $automationStep;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationSweep $automationSweep;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\PreviewAutomationRule $previewAutomationRule;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\RunStoredRuleTest $runStoredRuleTest;
    /**
     * The wire side of the same engine: the vocabulary the builder reads, the
     * allowlist and paging every route needs, and the presentation. They hold
     * no rule of their own and delegate every authoring decision to
     * ManageAutomationRules, so a save refused on the API is refused for the
     * same reason a save refused anywhere else is.
     */
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationReadModel $automationReads;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\AutomationCatalogue $automationCatalogue;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\AutomationRuleAdmin $automationRuleAdmin;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\AutomationRulePreview $automationRulePreview;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\AutomationRuleTesting $automationRuleTesting;
    public readonly \Shondalai\VidyaLMS\Core\Application\Automation\AutomationRunLog $automationRunLog;

    // ── AI, stage B ─────────────────────────────────────────────────────────
    //
    // The read and administration side of the AI surface: what a caller may
    // invoke, the data-use policy and its acceptance, the review queue, the run
    // and job logs, the provenance, route administration, the four enablement
    // levels with the stop above them, and the learner's own activity view.
    //
    // Public for the reason the report pipeline is: a package registering a
    // placement, a task or a bridge from a KernelReady listener reads these to
    // render its own screen, and a seam that handed over registries alone would
    // force every package to invent its own hand-off.
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel $aiReads;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiAccess $aiAccess;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiEnablementState $aiEnablement;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiPolicyCentre $aiPolicyCentre;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiPlacementCatalogue $aiPlacementCatalogue;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiDraftReview $aiDraftReview;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiRunLog $aiRunLog;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\GetMyAiActivity $myAiActivity;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiRouteAdmin $aiRouteAdmin;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiPolicyAdmin $aiPolicyAdmin;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiStopControl $aiStopControl;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\GetAiUsageReport $aiUsageReport;
    // The run pipeline, its ledger and the two entry paths over it.
    //
    // The pipeline is public for the same reason the read model is: a package
    // registering its own task from a KernelReady listener reaches the run path
    // through this rather than assembling eleven stages of its own, which is the
    // duplication a named stage list exists to prevent.
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiRunRepository $aiRunStore;
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiDraftRepository $aiDraftStore;
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiJobRepository $aiJobStore;
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiProvenanceRepository $aiProvenanceStore;
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiJobMapRepository $aiJobMapStore;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiRunPipeline $aiPipeline;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\StartAiRun $startAiRun;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\EstimateAiRun $estimateAiRun;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\QueueAiJob $queueAiJob;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\RunQueuedAiJob $runQueuedAiJob;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AiJobRunner $aiJobRunner;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\AcceptAiDraft $acceptAiDraft;
    public readonly \Shondalai\VidyaLMS\Core\Application\Ai\RecallAiRun $recallAiRun;
    public readonly SqlNotificationRuleRepository $notificationRules;
    public readonly SqlNotificationPreferenceRepository $notificationPreferences;
    public readonly SqlNotificationConsentRepository $notificationConsents;
    public readonly SqlNotificationSuppressionRepository $notificationSuppressions;
    public readonly SqlNotificationTemplateRepository $notificationTemplates;
    public readonly SqlProviderSettingsRepository $providerSettings;

    /** Shared credential resolution for AI and notification integrations. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Commerce\ProviderSecrets $providerSecrets;

    // ── Reporting ───────────────────────────────────────────────────────────
    //
    // Public for the same reason the access log is: a paid package registering
    // a report from a KernelReady listener needs the pipeline that runs it and
    // the store that records it, and a seam that handed over registries alone
    // would force every package to invent its own hand-off.
    public readonly ReportRunStore $reportRuns;

    /**
     * The saved schedules, public on the same terms as the run store above: a
     * paid package that adds an organisation schedule screen writes through
     * this store rather than inventing a second writer of one table.
     */
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report\ReportScheduleStore $reportSchedules;
    public readonly ReportExportPipeline $reportExports;
    public readonly GetSiteReport $getSiteReport;
    public readonly StartReportExport $startReportExport;

    /** The report catalogue, public so a paid manage screen can build a picker from it. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Reporting\ListReportDefinitions $listReportDefinitions;
    public readonly GetReportRun $getReportRun;
    public readonly GetOrganisationReport $getOrganisationReport;
    public readonly CollectAccessEvidence $collectAccessEvidence;

    public readonly GetCatalogue $getCatalogue;
    public readonly GetCourseBySlug $getCourseBySlug;
    public readonly GetCurriculum $getCurriculum;
    public readonly GetLessonForLearner $getLessonForLearner;
    public readonly CreateCourse $createCourse;
    public readonly UpdateCourse $updateCourse;
    public readonly PublishCourse $publishCourse;
    public readonly ArchiveCourse $archiveCourse;
    public readonly ChangeCourseLifecycle $changeCourseLifecycle;
    public readonly AddModule $addModule;
    public readonly UpdateModule $updateModule;
    public readonly ArchiveModule $archiveModule;
    public readonly ReorderModules $reorderModules;
    public readonly AddLesson $addLesson;
    public readonly UpdateLesson $updateLesson;
    public readonly ArchiveLesson $archiveLesson;
    public readonly MoveLesson $moveLesson;
    public readonly ReorderLessons $reorderLessons;
    public readonly SetPrerequisites $setPrerequisites;
    public readonly AssignInstructor $assignInstructor;
    public readonly RemoveInstructor $removeInstructor;

    public readonly CreateQuiz $createQuiz;
    public readonly UpdateQuiz $updateQuiz;
    public readonly AddQuestion $addQuestion;
    public readonly UpdateQuestion $updateQuestion;
    public readonly RemoveQuestion $removeQuestion;
    public readonly ReorderQuestions $reorderQuestions;
    public readonly StartAttempt $startAttempt;
    public readonly GetAttemptForLearner $getAttemptForLearner;
    public readonly SaveAnswers $saveAnswers;
    public readonly SubmitAttempt $submitAttempt;
    public readonly GradeAnswerManually $gradeAnswerManually;
    public readonly GetQuizSettingsSchema $getQuizSettingsSchema;
    public readonly RegradeAttempt $regradeAttempt;
    public readonly RegradeQuiz $regradeQuiz;
    public readonly RecordIntegrityEvent $recordIntegrityEvent;
    public readonly ListIntegrityEvents $listIntegrityEvents;
    public readonly BuildXapiStatements $buildXapiStatements;
    public readonly GetItemAnalysisReport $getItemAnalysisReport;

    public readonly GetCourseReport $getCourseReport;
    public readonly ListStimuli $listStimuli;
    public readonly CreateStimulus $createStimulus;
    public readonly GetStimulus $getStimulus;
    public readonly UpdateStimulus $updateStimulus;
    public readonly RemoveStimulus $removeStimulus;

    public readonly ConfigureAssignment $configureAssignment;
    public readonly SubmitAssignment $submitAssignment;
    public readonly GradeSubmission $gradeSubmission;

    /**
     * The grade write's one question of the late policy registry, exposed
     * because it is the free code path that makes the seam reachable and an
     * adapter's own grading surface asks the same question.
     */
    public readonly ApplyLatePolicy $applyLatePolicy;
    public readonly GetMySubmissions $getMySubmissions;
    public readonly \Shondalai\VidyaLMS\Core\Application\Assignments\GetMyAssignment $getMyAssignment;
    public readonly ListSubmissionsForAssignment $listSubmissionsForAssignment;

    public readonly EnrolLearner $enrolLearner;
    public readonly SelfEnrol $selfEnrol;
    public readonly GrantEntitlement $grantEntitlement;
    public readonly RevokeEntitlement $revokeEntitlement;

    /**
     * The four commerce entry points on the access spine.
     *
     * Held here rather than constructed by each bridge, because that is what
     * makes "refunds flow through the existing use cases" structurally true
     * rather than a review rule: a shop bridge hand-writing its own lookup
     * query is how one copy forgets the enrolment suspension, another revokes
     * on a partial refund, and none of them records why.
     */
    public readonly RevokeEntitlementBySourceRef $revokeEntitlementBySourceRef;
    public readonly HoldEntitlement $holdEntitlement;
    public readonly ActivateEntitlement $activateEntitlement;
    public readonly BackgroundActorResolver $backgroundActor;

    public readonly ExpireEntitlements $expireEntitlements;
    public readonly ListMyEnrolments $listMyEnrolments;
    public readonly \Shondalai\VidyaLMS\Core\Application\Organisation\MyOrganisationLearning $myOrganisationLearning;
    public readonly ListEnrolmentsForCourse $listEnrolmentsForCourse;

    public readonly RecordLessonProgress $recordLessonProgress;
    public readonly RecomputeCourseProgress $recomputeCourseProgress;

    /**
     * The gradebook's three stored things, and the two use cases over them.
     *
     * Public on the same terms as the report run store: a paid package that
     * adds a weighted aggregation strategy or an organisation gradebook screen
     * reads and writes through these rather than opening the tables itself, so
     * a grade change entry is written whoever wrote the grade.
     */
    public readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Grades\GradeBookStore $gradeBook;
    public readonly \Shondalai\VidyaLMS\Core\Application\Grades\RecordGrade $recordGrade;
    public readonly \Shondalai\VidyaLMS\Core\Application\Grades\CourseGradeTotals $courseGradeTotals;
    /**
     * The gradebook's three reads.
     *
     * It is named apart from {@see GetGradebook}, which is the shipped manual
     * grading queue: that one answers what is waiting for a human, and this one
     * answers what the course is marked out of. Two questions, two use cases,
     * and naming them apart is cheaper than discovering the difference from a
     * screen that called the wrong one.
     */
    public readonly \Shondalai\VidyaLMS\Core\Application\Grades\ReadGradebook $readGradebook;
    public readonly \Shondalai\VidyaLMS\Core\Application\Grades\RegradeGradeCell $regradeGradeCell;
    public readonly \Shondalai\VidyaLMS\Core\Application\Reporting\GetLearnerTimeline $getLearnerTimeline;
    public readonly \Shondalai\VidyaLMS\Core\Application\Reporting\ManageReportSchedules $manageReportSchedules;
    public readonly RecomputeCourseProgressForCourse $recomputeCourseProgressForCourse;
    public readonly GetMyCourseProgress $getMyCourseProgress;

    public readonly EnsureDefaultTemplate $ensureDefaultTemplate;
    public readonly IssueCertificate $issueCertificate;
    public readonly RenderCertificate $renderCertificate;
    public readonly RevokeCertificate $revokeCertificate;
    /** The other end of status list allocation, for a host that removes a credential row. */
    public readonly RetireCredential $retireCredential;
    public readonly SuspendCertificate $suspendCertificate;
    public readonly ReissueCertificate $reissueCertificate;
    public readonly ReissueCertificatesForTemplate $reissueCertificatesForTemplate;
    public readonly ListMyCertificates $listMyCertificates;
    public readonly VerifyCertificate $verifyCertificate;
    public readonly GetCredential $getCredential;

    /**
     * The token bag and the template checks, exposed because the authoring
     * surface reads both: the palette needs the placeholder vocabulary the
     * registry can actually answer, and the preview needs the findings.
     */
    public readonly CertificateTokens $certificateTokens;

    /**
     * The record and context one credential build is handed, shared by the
     * freeze at issue and the read at dereference so the two cannot disagree
     * about what a credential says.
     */
    public readonly CredentialAssembly $credentialAssembly;
    public readonly CertificateTemplateChecks $certificateTemplateChecks;

    /**
     * The certificate template authoring surface: the three levels of the
     * aggregate, their order, and the preview that runs the same renderer
     * issuance runs without writing anything.
     */
    public readonly ListCertificateTemplates $listCertificateTemplates;
    public readonly GetCertificateTemplate $getCertificateTemplate;
    public readonly CreateCertificateTemplate $createCertificateTemplate;
    public readonly UpdateCertificateTemplate $updateCertificateTemplate;
    public readonly ArchiveCertificateTemplate $archiveCertificateTemplate;
    public readonly ListCertificateTemplatePages $listCertificateTemplatePages;
    public readonly AddCertificateTemplatePage $addCertificateTemplatePage;
    public readonly UpdateCertificateTemplatePage $updateCertificateTemplatePage;
    public readonly DeleteCertificateTemplatePage $deleteCertificateTemplatePage;
    public readonly ReorderCertificateTemplatePages $reorderCertificateTemplatePages;
    public readonly ListCertificateTemplateElements $listCertificateTemplateElements;
    public readonly AddCertificateTemplateElement $addCertificateTemplateElement;
    public readonly UpdateCertificateTemplateElement $updateCertificateTemplateElement;
    public readonly DeleteCertificateTemplateElement $deleteCertificateTemplateElement;
    public readonly ReorderCertificateTemplateElements $reorderCertificateTemplateElements;
    public readonly PreviewCertificateTemplate $previewCertificateTemplate;

    /**
     * Held so the issuance listener can require an active entitlement at the
     * moment a certificate is minted.
     */
    private readonly EntitlementRepository $entitlementRepository;

    /**
     * The authored credit an issuance reads and a learner's own scheme
     * identifiers, both exposed because the management surface writes them.
     */
    public readonly CourseCreditRepository $courseCreditRepository;
    public readonly CreditSchemeIdentityRepository $creditSchemeIdentities;

    /**
     * The three credential repositories the routes read directly, exposed
     * because a management screen asks about provenance, credit and status
     * slots and none of those questions is a use case of its own.
     */
    public readonly CertificateRepository $certificateRepository;
    public readonly CertificateTemplateRepository $certificateTemplateRepository;
    public readonly \Shondalai\VidyaLMS\Core\Application\Credentials\CertificateStudio $certificateStudio;
    public readonly \Shondalai\VidyaLMS\Core\Application\Credentials\CourseCertificatePolicy $courseCertificatePolicy;
    public readonly CertificateCreditRepository $certificateCreditRepository;
    public readonly CredentialStatusRepository $credentialStatusRepository;

    public readonly RecordSchemaVersion $recordSchemaVersion;
    public readonly ImportMap $importMap;
    public readonly RunImportJob $runImportJob;
    public readonly QueueImportJob $queueImportJob;
    public readonly RunQueuedImport $runQueuedImport;
    public readonly GetImportJob $getImportJob;
    public readonly AbandonImportJob $abandonImportJob;
    public readonly ListImportJobs $listImportJobs;
    /**
     * The importer catalogue, so a host screen and a headless client read one
     * contract instead of each reading the registry in process and inventing
     * its own answer.
     */
    public readonly \Shondalai\VidyaLMS\Core\Application\Platform\ListImporters $listImporters;
    /** The site console's half of the row report; the organisation's half is $organisationImports. */
    public readonly \Shondalai\VidyaLMS\Core\Application\Platform\ImportRowProblems $importRowProblems;
    public readonly PruneImportSources $pruneImportSources;
    public readonly AuthoriseUpload $authoriseUpload;

    public readonly ListManagedCourses $listManagedCourses;
    public readonly ListCourseCategories $listCourseCategories;
    public readonly GetCourseCategory $getCourseCategory;
    public readonly GetManagedCourse $getManagedCourse;
    public readonly ListInstructors $listInstructors;
    public readonly GetAuthoringCurriculum $getAuthoringCurriculum;
    public readonly GetAuthoringLesson $getAuthoringLesson;
    public readonly AddAuthoredLesson $addAuthoredLesson;

    public readonly ListQuizzesForCourse $listQuizzesForCourse;
    public readonly GetQuizAuthoring $getQuizAuthoring;
    public readonly ManualGradingStatus $manualGradingStatus;

    /**
     * The publication checks, public so a package can attach its own facts
     * to every context the gate builds without the core knowing the shapes.
     */
    public readonly QuizPublicationGate $quizPublicationGate;

    public readonly GradebookQueue $gradebookQueue;
    public readonly GetGradebook $getGradebook;
    public readonly SiteOverview $siteOverview;
    public readonly GetLearnerRecord $getLearnerRecord;
    public readonly ListLearners $listLearners;
    public readonly GetPlatformSettings $getPlatformSettings;
    public readonly SearchUsers $searchUsers;
    public readonly ListProviderTypes $listProviderTypes;

    public readonly EnrolLearnerManually $enrolLearnerManually;
    public readonly ExtendEntitlement $extendEntitlement;
    public readonly \Shondalai\VidyaLMS\Core\Application\Commerce\SyncShopAccess $syncShopAccess;
    public readonly ListCourseLearners $listCourseLearners;
    public readonly ListSiteEnrolments $listSiteEnrolments;
    public readonly AccessGrantView $accessGrantView;

    public readonly GetLearnerCourseProgress $getLearnerCourseProgress;
    public readonly GradeOutcomeReader $gradeOutcomeReader;
    public readonly ListCertificates $listCertificates;
    public readonly CertificateRecords $certificateRecords;

    private readonly Ports $ports;
    /*
     * Typed as the interfaces, not as the database implementations: a
     * package may hand the kernel a repository of its own through the
     * Repositories bundle, and everything above these depends on the
     * interface anyway.
     */
    private readonly CourseRepository $courseRepository;
    private readonly QuizRepository $quizRepository;
    private ?ApiKernel $apiKernel = null;

    /** @var list<Route> queued until apiKernel() builds the kernel */
    private array $contributedRoutes = [];

    /**
     * @param array<string, mixed> $options host facts the core cannot know:
     *                                     "verification_base_url",
     *                                     "unsubscribe_base_url",
     *                                     "envelope_domain",
     *                                     "expiry_sweep_schedule",
     *                                     "host_settings_url" and
     *                                     "self_enrolment".
     *                                     The two the Settings and Dashboard
     *                                     screens state take a string or a
     *                                     Closure(): ?string, so a host that
     *                                     has to look one up (Joomla's
     *                                     scheduler owns the sweep cadence)
     *                                     is not made to look it up on every
     *                                     request that boots the kernel.
     */
    public function __construct(
        Ports $ports,
        array $options = [],
        ?Repositories $repositories = null,
        ?\Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration $configuration = null
    ) {
        $this->ports = $ports;

        $baseUrl = $options['verification_base_url'] ?? '';
        $this->verificationBaseUrl = \is_string($baseUrl) ? $baseUrl : '';

        // The two machine-readable credential bases. They exist now even
        // though the free build serves only unsigned formats and publishes no
        // status list, so the URLs are stable before anything embeds one in a
        // QR code printed on paper. Both default to the API's own credential
        // routes derived from the verification base, because a site that
        // configures neither should still emit a dereferenceable id.
        $credentialBaseUrl = $options['credential_base_url'] ?? '';
        $this->credentialBaseUrl = \is_string($credentialBaseUrl) && $credentialBaseUrl !== ''
            ? rtrim($credentialBaseUrl, '/')
            : self::siblingRoute($this->verificationBaseUrl, 'credentials');

        $statusListBaseUrl = $options['status_list_base_url'] ?? '';
        $this->statusListBaseUrl = \is_string($statusListBaseUrl) && $statusListBaseUrl !== ''
            ? rtrim($statusListBaseUrl, '/')
            : ($this->credentialBaseUrl === '' ? '' : $this->credentialBaseUrl . '/status');

        // The unsubscribe base is a host fact for the same reason the
        // verification base is: only the adapter knows the public URL of its
        // own site page, and a stored copy goes stale the day the site moves.
        // The setting stays as the override for a site that publishes the
        // page somewhere else, which is the shape the design names; it was the
        // only source before, and nothing anywhere ever wrote it, so no
        // shipped install emitted a single List-Unsubscribe header.
        $unsubscribeBaseUrl = $options['unsubscribe_base_url'] ?? '';
        $this->unsubscribeBaseUrl = \is_string($unsubscribeBaseUrl) ? rtrim($unsubscribeBaseUrl, '/') : '';

        // A host fact for the same reason: the core has no site, and the one
        // source it must never take this from is the request, because the
        // queue is drained by a scheduled task that has none.
        $envelopeDomain = $options['envelope_domain'] ?? '';
        $this->envelopeDomain = \is_string($envelopeDomain) ? trim($envelopeDomain) : '';

        // The site's own name, address and logo, which every CMS already
        // knows about itself and none of which the core can. They default
        // the credential issuer and the three site.* tokens, so a fresh
        // install renders a certificate carrying the site logo and issues a
        // credential naming its issuer with no admin action at all. They come
        // from the host at boot and never from the request, for the reason
        // the credential bases do: issuance runs from a scheduled task that
        // has no request behind it, and a signed document cannot be corrected
        // once it is in a wallet.
        $siteName = $options['site_name'] ?? '';
        $siteName = \is_string($siteName) ? trim($siteName) : '';
        $siteUrl = $options['site_url'] ?? '';
        $siteUrl = \is_string($siteUrl) && trim($siteUrl) !== ''
            ? rtrim(trim($siteUrl), '/')
            : self::originOf($this->verificationBaseUrl);
        $siteLogoUrl = $options['site_logo_url'] ?? '';
        $siteLogoUrl = \is_string($siteLogoUrl) ? trim($siteLogoUrl) : '';

        $this->listingPolicy = ListingPolicy::fromOption($options['listing'] ?? null);
        $sweepSchedule = HostFact::fromOption($options['expiry_sweep_schedule'] ?? null);
        $hostSettingsUrl = HostFact::fromOption($options['host_settings_url'] ?? null);
        $selfEnrolment = $options['self_enrolment'] ?? true;
        $selfEnrolment = \is_bool($selfEnrolment) ? $selfEnrolment : true;

        $database = $ports->database;
        $clock = $ports->clock;

        $this->guard = new AccessGuard($ports->authorization);
        $this->events = new EventBus($ports->eventBridge, $ports->logger);

        $lessonAvailability = new LessonAvailability($database);

        // The registries exist before the repositories that read them: a
        // repository decoding a provider shaped payload has to ask the
        // provider which version wrote it.
        $this->registries = new Registries();
        $this->presenters = new PresenterRegistry($ports->locale->current(), $ports->translator);

        // Every repository is the free build's own unless a package handed
        // the kernel one of its own. Use cases depend on the interface, so a
        // replacement (Pro's group-scoped course repository is the case this
        // exists for) needs no use case rewritten and no free build byte
        // changed.
        $repositories ??= new Repositories();

        $courses = $repositories->courses ?? new DatabaseCourseRepository($database, $clock);
        $this->courseRepository = $courses;
        $modules = $repositories->modules ?? new DatabaseModuleRepository($database, $clock);
        $lessons = $repositories->lessons
            ?? new DatabaseLessonRepository($database, $clock, $this->registries->lessonTypes);
        $quizzes = $repositories->quizzes
            ?? new DatabaseQuizRepository($database, $clock, $this->registries->aggregationMethods);
        $this->quizRepository = $quizzes;
        $questions = $repositories->questions
            ?? new DatabaseQuestionRepository(
                $database,
                $clock,
                $this->registries->questionTypes,
                $this->registries->scoringPolicies,
                $this->registries->definitionUpgraders,
            );
        $attempts = $repositories->attempts ?? new DatabaseAttemptRepository($database, $clock);
        $attemptQuestions = new DatabaseAttemptQuestionRepository($database, $clock);
        $questionDefinitions = new DatabaseQuestionDefinitionStore($database, $clock);
        $stimuli = new DatabaseStimulusRepository($database, $clock);
        $integrityEvents = new DatabaseIntegrityEventRepository($database, $clock);
        $assignments = $repositories->assignments ?? new DatabaseAssignmentRepository($database, $clock);
        $submissions = $repositories->submissions ?? new DatabaseSubmissionRepository($database, $clock);
        $entitlements = $repositories->entitlements ?? new DatabaseEntitlementRepository($database, $clock);
        $this->entitlementRepository = $entitlements;
        $enrolments = $repositories->enrolments ?? new DatabaseEnrolmentRepository($database, $clock);
        $categoryVisibility = $ports->courseCategories instanceof \Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryVisibility ? $ports->courseCategories : null;
        $this->courseAccess = new CourseAccess($this->guard, $courses, $enrolments, $categoryVisibility);
        $lessonProgress = $repositories->lessonProgress ?? new SqlLessonProgressRepository($database, $clock);
        $courseProgress = $repositories->courseProgress ?? new SqlCourseProgressRepository($database, $clock);
        $videoPositions = new SqlVideoPositionRepository($database, $clock);
        $certificates = $repositories->certificates ?? new SqlCertificateRepository($database, $clock);
        $certificateTemplates = $repositories->certificateTemplates
            ?? new SqlCertificateTemplateRepository($database, $clock);
        $certificateCredits = new SqlCertificateCreditRepository($database, $clock);
        $courseCredits = new SqlCourseCreditRepository($database, $clock);
        $credentialStatus = new SqlCredentialStatusRepository($database, $clock, $ports->random);
        $this->certificateRepository = $certificates;
        $this->certificateTemplateRepository = $certificateTemplates;
        $this->certificateCreditRepository = $certificateCredits;
        $this->credentialStatusRepository = $credentialStatus;
        $this->courseCreditRepository = $courseCredits;
        $this->creditSchemeIdentities = new SqlCreditSchemeIdentityRepository($database, $clock);
        $courseTitles = new SqlCourseTitles($database);
        $activityLog = new DatabaseActivityLogRepository($database, $clock);
        $emailLog = new DatabaseEmailLogRepository($database, $clock);
        $featureProviderRows = new DatabaseFeatureProviderRepository($database, $clock);
        $idempotencyKeys = new DatabaseIdempotencyKeyRepository($database, $clock);
        $importJobs = new DatabaseImportJobRepository($database, $clock);
        $importMapRows = new DatabaseImportMapRepository($database, $clock);
        $schemaVersions = new DatabaseSchemaVersionRepository($database, $clock);
        $settingsRows = new DatabaseSettingsRepository($database, $clock);

        $this->settings = new Settings($settingsRows);
        $configuration ??= new \Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration(
            new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseConfigurationRepository($database, $clock),
        );
        // Who this site issues as. The settings record is the answer and the
        // three host facts are its default, so a fresh install emits a real
        // issuer id and name before an administrator has typed anything: no
        // adapter seeded the record, so every shipped site was emitting an
        // empty issuer inside credentials that claim to be one signature away
        // from conformant.
        $this->issuerIdentity = new IssuerIdentity(
            $this->settings,
            $ports->translator,
            $siteName,
            $siteUrl,
            $siteLogoUrl,
        );
        $this->featureProviders = new FeatureProviderLedger($featureProviderRows, $settingsRows);
        // Let each host tune replay windows for learning and background work.
        $this->idempotencyStore = new DatabaseIdempotencyStore(
            $idempotencyKeys,
            $clock,
            self::durationMap($options['idempotency_claim_ttls'] ?? null)
                + self::AUTOMATION_CLAIM_TTLS,
            self::durationMap($options['idempotency_outcome_ttls'] ?? null)
                + self::AUTOMATION_OUTCOME_TTLS,
        );

        $this->registries->lessonTypes->register(new ReadingLessonType($ports->translator));
        $this->registries->lessonTypes->register(new VideoLessonType($ports->translator));
        $this->registries->lessonTypes->register(new ResourceLessonType($ports->translator));
        $this->registries->lessonTypes->register(new QuizLessonType($ports->translator));
        $this->registries->lessonTypes->register(new AssignmentLessonType($ports->translator));
        // Every assessment provider the free build ships, in one list that
        // the catalogue generator uses too, so the generated artefact cannot
        // describe a site nobody runs.
        AssessmentProviders::registerInto($this->registries, $ports->translator);
        $this->registries->enrolmentRules->register(new OpenEnrolmentRule($ports->translator));
        $this->registries->enrolmentRules->register(new ManualEnrolmentRule($ports->translator));
        $this->registries->commerceSources->register(new CmsShopCommerceSource($ports->commerce, $ports->translator));
        $this->registries->notificationChannels->register(
            new EmailNotificationChannel($ports->users, $ports->mailer, $emailLog, $ports->translator),
        );
        // course-overview no longer checks its own capability: authorisation
        // moved to GetCourseReport and the reporting use cases, so a provider
        // is handed an already-authorised context. The three tabular reports
        // beside it are registered further down, where the collaborators they
        // read through exist.
        $this->registries->reports->register(new CourseOverviewReport($database, $ports->translator));
        $this->registries->certificateRenderers->register(new HtmlCertificateRenderer($ports->translator));
        // Every credential provider the free build ships, in one list: the
        // seventeen elements, the elements renderer beside the html one, the
        // two credential formats and the contact hours scheme.
        CredentialProviders::registerInto($this->registries, $ports->translator, $ports->storage, null, $ports->pdf);

        // The learning-flow seams and their free providers. Every one of the
        // eight has at least one, and a free code path reaches each: a seam
        // only the paid build uses is a seam nobody tests, and a free
        // provider nothing calls is the same failure one step further in.
        $this->registries->availabilityConditions->register(new DateCondition());
        $this->registries->availabilityConditions->register(new CompletionCondition());
        $this->registries->availabilityConditions->register(new ScoreCondition());
        $this->registries->availabilityConditions->register(new RoleCondition($ports->authorization));
        $this->registries->availabilityConditions->register(new EnrolmentSourceCondition());

        $this->registries->scheduleAnchors->register(new AbsoluteAnchor());
        $this->registries->scheduleAnchors->register(new EnrolmentAnchor());
        $this->registries->scheduleAnchors->register(new EntitlementStartAnchor());
        $this->registries->scheduleAnchors->register(new CourseStartAnchor());
        $this->registries->scheduleAnchors->register(new LaterOfEnrolmentAndCourseStartAnchor());
        // The offset anchor. Every one of the five above answers an instant
        // that already exists and applies no offset, and the fan-out applies
        // none of its own, so "wait three days after enrolment" resolved to
        // the enrolment instant, which is in the past, and the past-anchor
        // rule fired it immediately. It reads the registry it is registered
        // into, which is why it is constructed with it.
        $this->registries->scheduleAnchors->register(new RelativeAnchor($this->registries->scheduleAnchors));

        $this->registries->latePolicies->register(new NoLatePolicy());

        $this->registries->notificationTriggers->register(new EnrolledTrigger());
        $invitationSecret = $options['organisation_invitation_secret'] ?? '';
        $invitationUrl = $options['organisation_invitation_url'] ?? '';
        $invitationBox = new \Shondalai\VidyaLMS\Core\Application\Organisation\InvitationSecretBox(
            is_string($invitationSecret) ? $invitationSecret : '',
        );
        $invitationEnabled = $invitationBox->available() && is_string($invitationUrl)
            && str_starts_with($invitationUrl, 'https://');
        if ($invitationEnabled) {
            $this->registries->tokenResolvers->register(
                new \Shondalai\VidyaLMS\Core\Provider\Token\InvitationTokenResolver(),
            );
            $this->registries->notificationTriggers->register(
                new \Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\OrganisationInvitationTrigger(),
            );
            $this->registries->notificationTriggers->register(
                new \Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\OrganisationInvitationTrigger(true),
            );
        }
        $this->registries->notificationTriggers->register(new CourseCompletedTrigger());
        $this->registries->notificationTriggers->register(new CertificateIssuedTrigger());
        $this->registries->notificationTriggers->register(new SubmissionReceivedTrigger());
        $this->registries->notificationTriggers->register(new AttemptGradedTrigger());
        $this->registries->notificationTriggers->register(new ContentUnlockedTrigger());
        $this->registries->notificationTriggers->register(new EntitlementExpiringSweep());
        // The three seat threshold alerts, over the free ledger and never over
        // a stored recipient list on the pool. Their default audience is
        // org_admins, which is free, because a free pool exhausting with no
        // possible recipient is an alert that cannot be delivered.
        foreach (\Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\SeatThresholdTrigger::all() as $seatTrigger) {
            $this->registries->notificationTriggers->register($seatTrigger);
        }

        $this->registries->audiences->register(new LearnerAudience());
        $this->registries->audiences->register(new CourseInstructorsAudience());
        $this->registries->audiences->register(new SiteManagersAudience($ports->logger));

        // The three free escalation audiences. They resolve from
        // org_role_assignments and org_memberships and never from
        // HoldersOfCapability, which answers site-wide from the host CMS and
        // would mail every site manager from a screen showing one
        // organisation. They take the registry rather than a role key, so a
        // role a package registers is in the audience the moment it claims
        // the right; the registry is seeded later in this constructor and is
        // the same object, so the order here does not matter.
        $this->registries->audiences->register(
            new \Shondalai\VidyaLMS\Core\Provider\Notification\Audience\OrgAdminsAudience($this->registries->orgRoles)
        );
        $this->registries->audiences->register(
            new \Shondalai\VidyaLMS\Core\Provider\Notification\Audience\OrgUnitManagersAudience($this->registries->orgRoles)
        );
        $this->registries->audiences->register(
            new \Shondalai\VidyaLMS\Core\Provider\Notification\Audience\LineManagerAudience()
        );

        $this->registries->deliveryFeedbackMappers->register(new Rfc5965FeedbackMapper());

        $this->registries->tokenResolvers->register(new UserTokenResolver($ports->users));
        $this->registries->tokenResolvers->register(
            new CourseTokenResolver(
                $database,
                $this->settings->getString(SiteTokenResolver::SETTING_URL, '') ?: $siteUrl,
                new SqlIssuanceCourseFacts($database, $ports->users, $ports->courseCategories),
                $ports->courseLinks,
            ),
        );
        $this->registries->tokenResolvers->register(new SiteTokenResolver($this->settings, $this->issuerIdentity));
        $this->registries->tokenResolvers->register(new CertificateTokenResolver($this->verificationBaseUrl));
        $this->registries->tokenResolvers->register(new CohortTokenResolver());
        // The report prefix had a template, a caller and a parameter bag and
        // no provider, so every scheduled report email left the site with
        // "{report.url}" where the download link belonged.
        $this->registries->tokenResolvers->register(new ReportTokenResolver());
        $this->registries->tokenResolvers->register(
            new UnsubscribeTokenResolver(
                $this->settings,
                new UnsubscribeTokens($this->settings, $clock, $ports->random),
                $this->unsubscribeBaseUrl,
            ),
        );

        // The restriction engine. It is built here rather than lazily
        // because every read path that must respect availability takes it,
        // and a use case that resolved it at call time would be a second
        // answer to the one question LessonAvailability exists to answer
        // once.
        $recipientProfile = $ports->recipientProfile;
        $this->availabilityRules = new SqlAvailabilityRuleRepository($database, $clock);
        $this->availabilityGrants = new SqlAvailabilityGrantRepository($database, $clock);
        $this->availabilityFactLoaders = new AvailabilityFactLoaders();
        $this->availabilityFactLoaders->register(new ProgressFactLoader($database));
        $this->availabilityFactLoaders->register(new GradeFactLoader($database));
        $this->availabilityFactLoaders->register(new EntitlementFactLoader($database));
        $this->availabilityComposer = new AvailabilityComposer(
            $this->registries->availabilityConditions,
            $this->availabilityFactLoaders,
            $ports->logger,
        );
        $siteTimezone = $this->settings->getString('site.timezone', 'UTC');
        $this->availability = new AvailabilityEngine(
            $this->availabilityRules,
            $this->availabilityGrants,
            $this->availabilityComposer,
            $database,
            $clock,
            $siteTimezone === '' ? 'UTC' : $siteTimezone,
            $ports->scheduler,
            $ports->logger,
            $categoryVisibility,
        );
        $this->availabilityValidator = new AvailabilityRuleValidator(
            $this->registries->availabilityConditions,
            $this->availabilityFactLoaders,
            $this->availabilityRules,
            $database,
        );
        $this->availabilityCatalogue = new AvailabilityCatalogue(
            $this->registries->availabilityConditions,
            $this->registries->scheduleAnchors,
            $this->guard,
            $ports->translator,
            $this->featureProviders,
        );
        $this->manageAvailabilityRule = new ManageAvailabilityRule(
            $this->availabilityRules,
            $this->availabilityValidator,
            $this->courseAccess,
            $database,
        );
        $this->manageAvailabilityGrants = new ManageAvailabilityGrants(
            $this->availabilityGrants,
            $this->guard,
            $this->courseAccess,
            $clock,
            $database,
        );
        $this->previewAvailabilityRule = new PreviewAvailabilityRule(
            $this->availabilityRules,
            $this->availabilityComposer,
            $this->registries->availabilityConditions,
            $this->courseAccess,
            $database,
            $clock,
            $ports->locale,
        );

        // The notification engine. The policies are registered here because
        // each needs a repository, and a policy that read its own table
        // through a static would be a policy nobody could substitute.
        $notificationQueue = new SqlNotificationQueueRepository($database, $clock);
        $notificationRules = new SqlNotificationRuleRepository($database, $clock);
        $notificationPreferences = new SqlNotificationPreferenceRepository($database, $clock);
        $notificationConsents = new SqlNotificationConsentRepository($database, $clock);
        $notificationSuppressions = new SqlNotificationSuppressionRepository($database, $clock);
        $notificationTemplates = new SqlNotificationTemplateRepository($database, $clock);
        $providerSettings = new SqlProviderSettingsRepository($database, $clock);

        $this->notificationQueue = $notificationQueue;
        $this->notificationRules = $notificationRules;
        $this->notificationPreferences = $notificationPreferences;
        $this->notificationConsents = $notificationConsents;
        $this->notificationSuppressions = $notificationSuppressions;
        $this->notificationTemplates = $notificationTemplates;
        $this->providerSettings = $providerSettings;
        $this->providerSecrets = new \Shondalai\VidyaLMS\Core\Application\Commerce\ProviderSecrets(
            $providerSettings,
            $this->ports->secrets,
        );

        $this->registries->notificationPolicies->register(new PreferencePolicy($notificationPreferences));
        $this->registries->notificationPolicies->register(new ConsentPolicy($notificationConsents));
        $this->registries->notificationPolicies->register(new SuppressionPolicy($notificationSuppressions));
        $this->registries->notificationPolicies->register(new ChannelConfirmationPolicy());
        $this->registries->notificationPolicies->register(new QuietHoursPolicy());
        $this->registries->notificationPolicies->register(new FrequencyCapPolicy($notificationQueue));
        $this->registries->notificationPolicies->register(new DuplicateWindowPolicy($notificationQueue));
        $this->registries->notificationPolicies->register(new CrossRulePolicy($notificationRules));

        $this->unsubscribeTokens = new UnsubscribeTokens($this->settings, $clock, $ports->random);
        $this->notificationBlueprints = new NotificationTemplateBlueprintCatalogue($this->registries->notificationTriggers);
        $this->notificationTemplateCompiler = new NotificationTemplateCompiler(
            $this->notificationBlueprints,
            $this->registries->tokenResolvers,
            $this->registries->notificationChannels,
            layout: new \Shondalai\VidyaLMS\Core\Application\Notification\NotificationEmailLayout($ports->translator),
        );
        $this->notificationRenderer = new NotificationRenderer(
            $notificationTemplates,
            $this->registries->tokenResolvers,
            $this->notificationTemplateCompiler,
        );
        $this->ensureDefaultNotifications = new EnsureDefaultNotifications(
            $this->registries->notificationTriggers,
            $notificationRules,
            $notificationTemplates,
            $ports->translator,
            $ports->locale,
            $this->settings,
            $this->notificationBlueprints,
            $this->notificationTemplateCompiler,
            $this->registries->notificationChannels,
        );
        $this->notificationTemplateStudio = new NotificationTemplateStudio(
            $notificationTemplates,
            $this->notificationBlueprints,
            $this->notificationTemplateCompiler,
            $this->registries->notificationChannels,
            $this->guard,
            $ports->locale,
            $ports->translator,
            $this->ensureDefaultNotifications,
            $siteUrl,
        );
        $this->notificationEnqueue = new NotificationEnqueue(
            $this->registries->notificationTriggers,
            $notificationRules,
            $notificationQueue,
            $clock,
            $database,
            $ports->users,
            [CertificateIssuedTrigger::FACT_VERIFICATION_BASE_URL => $this->verificationBaseUrl],
            $this->ensureDefaultNotifications,
            new NotificationScheduleResolver(
                $this->registries->scheduleAnchors,
                $clock,
                $database,
                $ports->logger,
                $siteTimezone === '' ? 'UTC' : $siteTimezone,
            ),
        );
        // The outbox transaction the five trigger-emitting use cases share.
        // The listener stays registered as the safety net for events that
        // reach the bus by another road; the dedupe digest makes the two
        // paths idempotent rather than duplicative.
        // The two hops queue their own work the moment the row that needs
        // them exists. Both handlers stay periodic as the net; without the
        // wake-up an intent row waited for the next turn of a five minute
        // clock and its recipient row for the turn after that.
        $this->notificationJobs = new NotificationJobs($ports->scheduler, $clock);
        // Empty here and filled where the automation engine is built, several
        // hundred lines below: the outbox is what every trigger-emitting use
        // case already holds, and without an automation half an event trigger
        // could never open an automation run on a free build.
        $this->automationOutbox = new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationOutbox();
        $outbox = new TransactionalOutbox(
            $database,
            $this->events,
            $this->notificationEnqueue,
            $this->notificationJobs,
            $this->automationOutbox,
        );

        $this->notificationFanout = new NotificationFanout(
            $notificationQueue,
            $notificationRules,
            $this->registries->audiences,
            $notificationPreferences,
            $database,
            $ports->authorization,
            $clock,
            $ports->logger,
            $siteTimezone === '' ? 'UTC' : $siteTimezone,
            $recipientProfile,
            $this->registries->notificationPolicies,
            $providerSettings,
            $this->notificationJobs,
        );
        // The organisation providers register through the same public methods a
        // package outside the core uses, into the same seated registries, so a
        // KernelReady listener adding a role, a type, a join rule or a reclaim
        // policy reaches exactly what the free build reached. They were local
        // variables here until this wave, which is why nothing outside the core
        // could register one at all.
        \Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders::seed($this->registries);
        $orgRoles = $this->registries->orgRoles;
        $orgStore = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore($database, $clock);
        // The identity home. It is built here, beside the organisation store,
        // rather than with the identity use cases below, because the
        // invitation ceremony reads the join rule row through it and is
        // constructed first: a store with two collaborators and no state is
        // cheap to build early and impossible to build twice.
        $orgIdentityStore = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore(
            $database,
            $clock,
        );
        // The tenancy middleware needs the same store instance the use cases
        // read through, so the sole-membership step of the active-organisation
        // order asks the same question the console does.
        $this->organisationStore = $orgStore;
        $this->activeOrganisation = new \Shondalai\VidyaLMS\Core\Http\Middleware\ActiveOrganisation();
        $this->organisationAccess = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver(
            $this->guard,
            $orgStore,
            $orgRoles,
            // Every scope a route mints is published to the request, so the
            // access log records the resolution that actually ran rather than
            // a second one made with the default right.
            $this->activeOrganisation,
            $this->registries->fieldDisclosure,
            $this->settings,
        );
        $this->organisationRetention = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRetentionPolicies(
            $orgStore,
        );
        // The rule in front of the resolver, on the same terms CourseAccess
        // sits in front of the course repository: it owns the question and the
        // resolver owns the walk.
        $orgAccessPolicy = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess(
            $this->organisationAccess,
        );
        $this->organisationAccessPolicy = $orgAccessPolicy;
        $this->invitationDelivery = new \Shondalai\VidyaLMS\Core\Application\Organisation\EncryptedInvitationDelivery(
            $database,
            $notificationQueue,
            $invitationBox,
            $clock,
            $ports->users,
            $this->ensureDefaultNotifications,
            is_string($invitationUrl) ? $invitationUrl : '',
            $this->registries->invitationMatch,
        );
        $this->notificationSender = new NotificationSender(
            $notificationQueue,
            $notificationRules,
            $this->registries->notificationPolicies,
            $this->registries->notificationChannels,
            $this->notificationRenderer,
            $providerSettings,
            $ports->users,
            $clock,
            $ports->random,
            $ports->logger,
            $ports->locale,
            $database,
            $recipientProfile,
            new ConditionsStillHold($this->availabilityComposer, $database),
            $this->unsubscribeTokens,
            $this->settings->getString(UnsubscribeTokenResolver::SETTING_BASE_URL, '') !== ''
                ? rtrim($this->settings->getString(UnsubscribeTokenResolver::SETTING_BASE_URL, ''), '/')
                : $this->unsubscribeBaseUrl,
            $this->settings->getString(UnsubscribeTokenResolver::SETTING_MAILTO, ''),
            $this->envelopeDomain,
            $invitationEnabled ? $this->invitationDelivery : null,
        );

        $this->notificationTemplateTestDelivery = new NotificationTemplateTestDelivery(
            $this->notificationTemplateCompiler,
            $this->notificationBlueprints,
            $this->notificationSender,
            $ports->users,
            $this->idempotencyStore,
            $clock,
            $this->guard,
        );
        $this->notificationCatalogue = new NotificationCatalogue(
            $this->registries->notificationTriggers,
            $this->registries->audiences,
            $this->registries->notificationChannels,
            $this->registries->scheduleAnchors,
            $this->guard,
            $ports->translator,
            $ports->authorization,
            $this->featureProviders,
        );
        $this->notificationAdmin = new NotificationAdmin(
            $notificationRules,
            $notificationQueue,
            $notificationPreferences,
            $notificationSuppressions,
            $notificationConsents,
            $this->registries->notificationTriggers,
            $this->registries->notificationChannels,
            $this->guard,
            $this->availabilityValidator,
            $this->availabilityRules,
        );
        $this->notificationPreferenceCentre = new NotificationPreferenceCentre(
            $notificationPreferences,
            $notificationConsents,
            $this->registries->notificationTriggers,
            $this->registries->notificationChannels,
            $this->guard,
            $ports->translator,
            $this->registries->scheduledTasks,
        );
        $this->unsubscribe = new Unsubscribe(
            $this->unsubscribeTokens,
            $notificationPreferences,
            $notificationConsents,
            $this->registries->notificationTriggers,
            $this->registries->notificationChannels,
            $ports->translator,
        );
        $this->recordDeliveryFeedback = new RecordDeliveryFeedback(
            $this->registries->deliveryFeedbackMappers,
            $notificationSuppressions,
            $notificationQueue,
            $providerSettings,
            $this->providerSecrets,
            $clock,
            $ports->logger,
            $notificationPreferences,
            $notificationConsents,
            $invitationEnabled ? $this->invitationDelivery : null,
        );
        $this->manageProviderSettings = new ManageProviderSettings(
            $this->registries,
            $providerSettings,
            $this->guard,
            $ports->translator,
            $this->featureProviders,
        );

        $this->getCatalogue = new GetCatalogue($courses, $categoryVisibility);
        $this->getCourseBySlug = new GetCourseBySlug($courses, $this->courseAccess);
        $this->getCurriculum = new GetCurriculum($courses, $modules, $lessons, $this->courseAccess, $this->availability);
        $this->getLessonForLearner = new GetLessonForLearner(
            $lessons,
            $courses,
            $this->registries->lessonTypes,
            $this->courseAccess,
            $database,
            $this->registries->aggregationMethods,
            $this->availability,
        );
        $this->createCourse = new CreateCourse(
            $courses,
            $this->guard,
            $database,
            $this->events,
            $clock,
            $ports->courseCategories,
        );
        $this->updateCourse = new UpdateCourse(
            $courses,
            $this->courseAccess,
            $this->events,
            $clock,
            $ports->courseCategories,
            $this->registries->enrolmentRules,
            $this->registries->latePolicies,
        );
        $this->quizPublicationGate = new QuizPublicationGate(
            $quizzes,
            $questions,
            $this->registries->publicationChecks,
        );
        $this->publishCourse = new PublishCourse(
            $courses,
            $lessons,
            $this->courseAccess,
            $clock,
            $this->events,
            $this->quizPublicationGate,
        );
        $this->archiveCourse = new ArchiveCourse($courses, $this->courseAccess, $this->events, $clock);
        $this->changeCourseLifecycle = new ChangeCourseLifecycle($courses, $this->courseAccess, $this->events, $clock);
        $this->addModule = new AddModule($modules, $this->courseAccess);
        $this->updateModule = new UpdateModule($modules, $this->courseAccess);
        $this->reorderModules = new ReorderModules($modules, $this->courseAccess);
        $this->addLesson = new AddLesson($lessons, $modules, $this->courseAccess, $this->registries->lessonTypes);
        $this->updateLesson = new UpdateLesson(
            $lessons,
            $this->courseAccess,
            $this->registries->lessonTypes,
            $lessonAvailability,
        );
        $this->moveLesson = new MoveLesson($lessons, $modules, $this->courseAccess);
        $this->reorderLessons = new ReorderLessons($lessons, $modules, $this->courseAccess);
        $this->setPrerequisites = new SetPrerequisites($courses, $this->courseAccess);
        $this->assignInstructor = new AssignInstructor($courses, $this->guard);
        $this->removeInstructor = new RemoveInstructor($courses, $this->guard);

        $this->createQuiz = new CreateQuiz(
            $quizzes,
            $database,
            $this->courseAccess,
            $this->registries->attemptPolicies,
            $this->registries->aggregationMethods,
            $this->registries->negativeMarking,
        );
        $this->updateQuiz = new UpdateQuiz(
            $quizzes,
            $database,
            $this->courseAccess,
            $this->registries->attemptPolicies,
            $this->registries->aggregationMethods,
            $this->registries->negativeMarking,
            $questions,
        );
        $this->getQuizSettingsSchema = new GetQuizSettingsSchema(
            $quizzes,
            $this->courseAccess,
            $this->registries->attemptPolicies,
            $this->registries->aggregationMethods,
            $this->registries->negativeMarking,
            $ports->translator,
            $this->featureProviders,
            $this->registries->quizSettingGroups,
        );
        $this->listStimuli = new ListStimuli($stimuli, $this->courseAccess);
        $this->createStimulus = new CreateStimulus($stimuli, $this->courseAccess);
        $this->getStimulus = new GetStimulus($stimuli, $this->courseAccess);
        $this->updateStimulus = new UpdateStimulus($stimuli, $this->courseAccess);
        $this->removeStimulus = new RemoveStimulus($stimuli, $this->courseAccess);
        $this->recordIntegrityEvent = new RecordIntegrityEvent(
            $attempts,
            $integrityEvents,
            $this->guard,
            $clock,
            $this->registries->integrityEventKinds,
        );
        $this->listIntegrityEvents = new ListIntegrityEvents(
            $attempts,
            $quizzes,
            $integrityEvents,
            $this->courseAccess,
        );
        $this->getItemAnalysisReport = new GetItemAnalysisReport(
            $this->registries->reports,
            $this->courseAccess,
            $ports->translator,
        );
        $this->getCourseReport = new GetCourseReport(
            $this->registries->reports,
            $this->courseAccess,
            $ports->translator,
        );

        // The free composition is registered here rather than with the other
        // assessment providers because it is the one that needs a
        // repository, and the provider list is deliberately reachable
        // without a database so the catalogue generator can read it.
        $this->registries->quizCompositions->register(
            new PublishedQuestionsComposition($questions, $ports->translator),
        );
        // One resolver, so the registry-miss contract behaves identically in
        // all seven places that used to resolve providers themselves.
        $questionTypes = new QuestionTypes(
            $this->registries->questionTypes,
            $ports->translator,
            $this->featureProviders,
        );
        $manifest = new AttemptManifest(
            $attemptQuestions,
            $questionDefinitions,
            $questions,
            $questionTypes,
            $this->registries->interactionPrimitives,
            $ports->locale,
        );
        $this->questionTypeResolver = $questionTypes;
        $this->attemptManifest = $manifest;

        $this->buildXapiStatements = new BuildXapiStatements(
            $attempts,
            $quizzes,
            $manifest,
            $questionTypes,
            $this->courseAccess,
            $this->events,
            $this->verificationBaseUrl,
            $ports->locale,
            $ports->cache,
        );

        $this->addQuestion = new AddQuestion(
            $quizzes,
            $questions,
            $this->registries->questionTypes,
            $this->courseAccess,
            $stimuli,
            $this->registries->definitionUpgraders,
            $this->registries->rubrics,
        );
        $this->updateQuestion = new UpdateQuestion(
            $questions,
            $quizzes,
            $questionTypes,
            $this->courseAccess,
            $this->registries->rubrics,
        );
        $this->removeQuestion = new RemoveQuestion($questions, $quizzes, $this->courseAccess);
        $this->reorderQuestions = new ReorderQuestions($questions, $quizzes, $this->courseAccess);
        $this->startAttempt = new StartAttempt(
            $quizzes,
            $attempts,
            $database,
            $this->guard,
            $clock,
            $lessonAvailability,
            $this->registries->attemptPolicies,
            $manifest,
            $ports->random,
            $this->registries->quizCompositions,
            $this->registries->negativeMarking,
            new LearnerAccessibilityProfile($database),
            $ports->locale,
            $this->availability,
        );
        $this->regradeAttempt = new RegradeAttempt(
            $attempts,
            $quizzes,
            new AttemptRegrader(
                $attempts,
                $manifest,
                $questions,
                $questionTypes,
                $this->registries->scoringPolicies,
                $clock,
                $ports->translator,
                $ports->locale,
            ),
            $this->courseAccess,
        );
        $this->regradeQuiz = new RegradeQuiz(
            $quizzes,
            $questions,
            $attempts,
            new AttemptRegrader(
                $attempts,
                $manifest,
                $questions,
                $questionTypes,
                $this->registries->scoringPolicies,
                $clock,
                $ports->translator,
                $ports->locale,
            ),
            $this->courseAccess,
        );
        $this->getAttemptForLearner = new GetAttemptForLearner(
            $quizzes,
            $attempts,
            $manifest,
            $this->guard,
            $database,
            $questionTypes,
            new SignedMedia($ports->storage),
            $this->registries->attemptPolicies,
            $clock,
        );
        $this->saveAnswers = new SaveAnswers(
            $quizzes,
            $attempts,
            $attemptQuestions,
            $manifest,
            $questionTypes,
            $this->guard,
            $clock,
            $database,
            $ports->locale,
        );
        $this->submitAttempt = new SubmitAttempt(
            $quizzes,
            $attempts,
            $manifest,
            $questionTypes,
            $this->registries->scoringPolicies,
            $this->guard,
            $this->events,
            $clock,
            $database,
            $ports->locale,
            $outbox,
        );
        $this->gradeAnswerManually = new GradeAnswerManually(
            $quizzes,
            $questions,
            $attempts,
            $manifest,
            $questionTypes,
            $this->courseAccess,
            $this->events,
            $clock,
            $this->registries->rubrics,
        );

        $this->configureAssignment = new ConfigureAssignment($assignments, $this->courseAccess, $database);
        $this->submitAssignment = new SubmitAssignment(
            $assignments,
            $submissions,
            $this->guard,
            $this->events,
            $database,
            $clock,
            $this->availability,
            $outbox,
            $ports->storage,
        );
        $this->gradeSubmission = new GradeSubmission(
            $submissions,
            $assignments,
            $this->courseAccess,
            $this->events,
            $database,
            $clock,
            $this->applyLatePolicy = new ApplyLatePolicy(
                $this->registries->latePolicies,
                $database,
                $ports->logger,
            ),
        );
        $this->getMySubmissions = new GetMySubmissions($submissions, $this->guard);
        $this->getMyAssignment = new \Shondalai\VidyaLMS\Core\Application\Assignments\GetMyAssignment(
            $this->getLessonForLearner,
            $assignments,
            $submissions,
            $this->presenters->assessment,
            $ports->storage,
            $clock,
            $database,
        );
        $this->listSubmissionsForAssignment = new ListSubmissionsForAssignment(
            $submissions,
            $assignments,
            $database,
            $this->courseAccess,
        );

        $this->enrolLearner = new EnrolLearner($enrolments, $this->events, $clock, $outbox);
        $this->selfEnrol = new SelfEnrol(
            $this->guard,
            $database,
            $entitlements,
            $enrolments,
            $this->enrolLearner,
            $this->registries->enrolmentRules,
            $this->events,
            $clock,
            $this->availability,
            $categoryVisibility,
        );
        $this->grantEntitlement = new GrantEntitlement(
            $this->courseAccess,
            $ports->users,
            $database,
            $entitlements,
            $this->enrolLearner,
            $this->events,
            $clock,
            $this->registries->commerceSources,
        );
        $this->revokeEntitlement = new RevokeEntitlement(
            $this->courseAccess,
            $entitlements,
            $enrolments,
            $this->events,
            $clock,
        );
        $this->revokeEntitlementBySourceRef = new RevokeEntitlementBySourceRef($entitlements, $this->revokeEntitlement);
        $this->holdEntitlement = new HoldEntitlement(
            $this->courseAccess,
            $entitlements,
            $enrolments,
            $this->events,
            $clock,
        );
        $this->activateEntitlement = new ActivateEntitlement(
            $this->courseAccess,
            $entitlements,
            $this->enrolLearner,
            $clock,
        );
        $this->backgroundActor = new BackgroundActorResolver($this->courseAccess, $this->guard, $this->settings);
        $orgLearningStore = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore(
            $database,
            $clock,
            // Every seat assignment's opaque reference is minted here, before
            // its insert, because the row's own id does not exist until the
            // insert has happened and source_ref names the reference.
            $ports->random,
        );
        $orgReleases = new \Shondalai\VidyaLMS\Core\Application\Organisation\CourseReleasePolicy($database, $clock);
        // The pairing three use cases were inlining, as one object.
        $orgComposed = new \Shondalai\VidyaLMS\Core\Application\Organisation\ComposedAccess($orgAccessPolicy, $orgReleases);
        $this->organisationLearningEvents = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningEvents(
            $this->notificationEnqueue,
            $this->events,
        );
        // The attempt half of the revocation ceremony, shared by every
        // revoking path. Before it, a reclaim at minute forty of a sixty
        // minute attempt left the learner unable to save or submit, with
        // answers stored and no grade: RequireCurrentOrganisationAccess was
        // standing in for a ceremony that did not exist.
        $closeAttempts = new \Shondalai\VidyaLMS\Core\Application\Organisation\CloseAttemptsOnAccessLoss(
            $database,
            $this->submitAttempt,
            $this->organisationLearningEvents,
        );
        $orgSeats = new \Shondalai\VidyaLMS\Core\Application\Organisation\SeatLifecycle(
            $orgLearningStore,
            $this->registries->seatReclaim,
            $this->revokeEntitlement,
            $this->organisationLearningEvents,
            $clock,
            $closeAttempts,
        );
        $orgTermination = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccessTermination(
            $orgLearningStore,
            $orgSeats,
            $this->revokeEntitlement,
            $this->organisationLearningEvents,
            $closeAttempts,
        );
        // The chain-preserving pseudonymisation the design's ledger-versus-
        // erasure resolution needs, keyed by the same host secret the access
        // log's anchor is keyed by, domain separated inside the store.
        $this->ledgerRedactions = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\LedgerRedactionStore(
            $database,
            $clock,
            \is_string($options['access_log_secret'] ?? null) ? $options['access_log_secret'] : (is_string($invitationSecret) ? $invitationSecret : ''),
        );
        $this->registries->privacyContributors->register(new \Shondalai\VidyaLMS\Core\Provider\Privacy\CoreOrganisationsPrivacy(
            $database,
            $ports->users,
            $this->ledgerRedactions,
            $this->organisationRetention,
        ));
        // The learner's own learning, registered once so neither host hook holds
        // a table list. Adding a table to the contributor changes both hosts'
        // exports with no adapter edit and no language-key edit, which is what
        // makes the portability claim true rather than aspirational.
        $this->registries->privacyContributors->register(
            new \Shondalai\VidyaLMS\Core\Provider\Privacy\CoreLearningPrivacy($database, $ports->storage),
        );
        // The AI tables, for the same reason. GET /me/ai-activity is the
        // Article 15 answer and it is not the export: ai_run_subjects is the
        // table that says whose data a run was about, and it was in neither
        // host's manifest, so a subject access request answered without it and
        // an erasure left draft payloads standing.
        $this->registries->privacyContributors->register(
            new \Shondalai\VidyaLMS\Core\Provider\Privacy\CoreAiPrivacy($database),
        );
        $this->registries->privacyContributors->observeRecordedProviders($featureProviderRows);
        $this->organisationPrivacy = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationPrivacy(
            $this->organisationAccess,
            $orgStore,
            $this->registries->privacyContributors,
            $orgTermination,
            $orgRoles,
            $this->settings,
        );
        $this->organisationAuditExport = new \Shondalai\VidyaLMS\Core\Application\Organisation\ExportOrganisationAudit(
            $this->organisationAccess,
            $orgStore,
        );
        $this->organisations = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisations(
            $this->organisationAccess,
            $orgStore,
            $ports->users,
            $orgRoles,
            $this->registries->orgTypes,
            $orgTermination,
            $this->registries->invitationMatch,
        );
        // Which of the three organisation policy seats this site is running.
        // The three were seated with published miss directions and read by
        // nothing at all until this wave, so a Business registration into any
        // of them was a silent no-op.
        $orgPolicies = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrgPolicyResolution(
            $this->registries->orgVisibility,
            $this->registries->orgMembershipTermination,
            $this->registries->orgUnitMove,
            $this->settings,
        );
        $this->organisationMembers = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers(
            $orgAccessPolicy,
            $orgStore,
            $orgRoles,
            $ports->users,
            $ports->translator,
            $orgTermination,
            // The member read publishes the person it resolved, which is what
            // fills the access log's subject anchor and makes its index and
            // its per-person query answerable.
            $this->activeOrganisation,
            $orgPolicies,
        );
        // Constructed before the two use cases that raise it, because a seat
        // moving raises the alert at the moment it happens and the assigning
        // and releasing routes are two of the three callers.
        $this->seatThresholdAlerts = new \Shondalai\VidyaLMS\Core\Application\Organisation\SeatThresholdAlerts(
            $this->notificationEnqueue,
            $clock,
            $this->settings,
        );
        // The six numbers an organisation's front page is, computed in one place
        // and read from two: GET .../summary, which amendment C7 keeps
        // permanently as an alias, and the registered org-overview definition.
        // Two copies of these queries would be two answers to one question.
        $orgOverview = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationOverviewReader(
            $database,
            $clock,
        );
        $this->organisationLearning = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationLearning(
            $this->organisationAccess,
            $orgStore,
            $orgLearningStore,
            $orgComposed,
            $this->grantEntitlement,
            $this->revokeEntitlement,
            $this->organisationLearningEvents,
            $orgTermination,
            $ports->users,
            $closeAttempts,
            $orgOverview,
            $orgPolicies,
        );
        $this->organisationSeats = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationSeats(
            $this->organisationAccess,
            $orgStore,
            $orgLearningStore,
            $orgComposed,
            $this->grantEntitlement,
            $this->organisationLearningEvents,
            $orgSeats,
            $ports->users,
            $this->registries->seatReclaim,
            // The other three policy families, resolved inside the use case
            // when each decision is taken. A pool stores a key; the key is
            // looked up at the moment the question is asked, so a package
            // registering a provider is selectable without a rebuild.
            $this->registries->seatScopes,
            $this->registries->seatOverAllocation,
            $this->registries->seatExpiryDisposition,
            $this->registries->seatEventReasons,
            $this->seatThresholdAlerts,
        );
        $this->organisationUnits = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits(
            $this->organisationAccess,
            $orgStore,
            $this->settings,
        );
        // Reserve at issue. It is a kernel property rather than a local
        // because three of the four organisation sweeps and the invitation
        // ceremony all end at the same three verbs, and a caller that had to
        // construct it would construct its own release instead, which is the
        // duplication the ledger cannot survive.
        $this->seatReservations = new \Shondalai\VidyaLMS\Core\Application\Organisation\SeatReservations(
            $orgLearningStore,
            $orgStore,
            $orgComposed,
            $this->grantEntitlement,
            $this->organisationLearningEvents,
            $this->seatThresholdAlerts,
        );
        $this->transferSeat = new \Shondalai\VidyaLMS\Core\Application\Organisation\TransferSeat(
            $this->organisationAccess,
            $orgStore,
            $orgLearningStore,
            $orgComposed,
            $ports->users,
            $this->grantEntitlement,
            $this->revokeEntitlement,
            $this->organisationLearningEvents,
            $this->seatThresholdAlerts,
        );
        $this->organisationInvitations = $invitationEnabled
            ? new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationInvitations(
                $this->organisationAccess,
                $orgStore,
                $this->organisationMembers,
                $orgRoles,
                $ports->users,
                $clock,
                $ports->random,
                $this->invitationDelivery,
                new \Shondalai\VidyaLMS\Core\Application\Organisation\InvitationAttemptLimit($database, $clock),
                $this->registries->orgJoinRules,
                $this->registries->invitationMatch,
                $this->settings,
                $this->seatReservations,
                // Which rule this organisation joins under, read from its own
                // row rather than from a constant three call sites agreed on.
                $orgIdentityStore,
            ) : null;
        $this->registries->scheduledTasks->register(new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationTerminationTask(
            $orgTermination,
            $orgStore,
            $ports->scheduler,
            $clock,
        ));
        // The admin access log and the only writer that removes a row from it.
        // Both are free and both land before the first report over the log,
        // because prev_hash and hash form a chain and a chain started later
        // cannot cover the rows written before it.
        // The anchor is a settings row and the key is the host's own secret,
        // domain separated inside the store. The fall-back to the invitation
        // secret exists so a host that has not been taught the new option
        // still gets a keyed anchor rather than an unkeyed one; a host that
        // supplies neither gets an anchor that says it is unkeyed.
        $accessLogSecret = $options['access_log_secret'] ?? $options['organisation_invitation_secret'] ?? '';
        $this->adminAccessLog = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogStore(
            $database,
            $clock,
            $settingsRows,
            is_string($accessLogSecret) ? $accessLogSecret : '',
        );
        // The per-organisation lengthening, which is what stops
        // AccessLogRetentionTask::retentionDaysFor() being a clamp with no
        // caller: organisations.retention_policy exists now, so the sweep asks
        // per owning organisation and the clamp is the rule that policy may
        // never break.
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AccessLogRetentionTask(
                $this->adminAccessLog,
                $this->settings,
                new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationRetentionPolicies(
                    $database,
                ),
            )
        );
        // Compliance ships free as schema with no rule registered, and this is
        // the walk that exercises the seam every day: every open assignment is
        // re-asked, and with nothing registered every answer is provably not
        // compliant rather than quietly absent.
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ComplianceWindowTask(
                new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\ComplianceStore($database),
                $clock,
                $this->registries->complianceRules,
            )
        );
        $this->complianceAssignments = new \Shondalai\VidyaLMS\Core\Application\Organisation\ComplianceAssignments(
            $database,
            $clock,
            $this->registries->complianceRules,
            $this->registries->scheduleAnchors,
        );
        // The authoring half. Until it existed the four compliance tables had
        // no writer anywhere, so ComplianceAssignments::assign had no caller
        // and the daily window task reported checked=0 on every site.
        $this->organisationCompliance = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationCompliance(
            $this->organisationAccess,
            $orgStore,
            $orgLearningStore,
            $this->complianceAssignments,
            $this->registries->complianceRules,
            $this->registries->scheduleAnchors,
            $orgReleases,
        );
        // The catalogue. It takes the release policy rather than the composed
        // access object, because an entry is authorised by the organisation
        // rule this use case already ran and bounded by the release record,
        // and a course grant is somebody else's call.
        $this->organisationCatalogue = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationCatalogue(
            $this->organisationAccess,
            $orgStore,
            $orgLearningStore,
            $orgReleases,
            $this->registries->orgCatalogueSources,
            $clock,
            $this->featureProviders,
        );
        // The four free organisation sweeps. Every one of them acts on a
        // column that was written and never read: reserved_until, ends_at,
        // expires_at, and the two derivations of a pool's balance. They act as
        // the administrator whose own recorded action created the row, which
        // is why the sweep authority is a collaborator rather than a constant.
        $sweptRecords = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\SweptRecordStore($database, $clock);
        $sweepAuthority = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationSweepAuthority($sweptRecords);
        // Progress reaches the seat model through a listener and never a
        // query. It is subscribed here rather than in subscribeListeners()
        // because the tenant witness it needs is built on the line above, and
        // it is subscribed to CourseCompleted before the certificate listener
        // so a finished seat is already `completed` when the certificate is
        // issued against it.
        $seatProgress = new \Shondalai\VidyaLMS\Core\Application\Organisation\SeatProgressListener(
            $orgLearningStore,
            $orgStore,
            $sweepAuthority,
        );
        foreach (
            [\Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted::class,
                \Shondalai\VidyaLMS\Core\Domain\Events\AttemptSubmitted::class,
                \Shondalai\VidyaLMS\Core\Domain\Events\SubmissionReceived::class,
                CourseCompleted::class] as $progressEvent
        ) {
            $this->events->subscribe($progressEvent, $seatProgress);
        }
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\SeatReconciliationTask(
                $sweptRecords,
                $sweepAuthority,
                $orgLearningStore,
                $this->seatThresholdAlerts,
                $this->settings,
            )
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ReservationExpiryTask(
                $sweptRecords,
                $sweepAuthority,
                $orgStore,
                $this->seatReservations,
            )
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\PoolTermSweepTask(
                $sweptRecords,
                $sweepAuthority,
                $orgStore,
                $orgLearningStore,
                $orgSeats,
                $this->registries->seatExpiryDisposition,
                $clock,
            )
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\MembershipTermSweepTask(
                $sweptRecords,
                $sweepAuthority,
                $orgStore,
                $this->organisationMembers,
            )
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\InvitationExpiryTask(
                $sweptRecords,
                $sweepAuthority,
                $orgStore,
                $this->registries->provisioningSources,
                $this->seatReservations,
            )
        );

        // ── The reporting seams ─────────────────────────────────────────────
        //
        // Registered here rather than beside course-overview because these
        // three read through collaborators built above this line: the site
        // report needs the clock, the roster needs the users port and the
        // access-log report needs the append-only store that was constructed
        // four statements ago.
        //
        // org-access-log is the first organisation-scoped report and it is
        // pulled forward from the governance wave on purpose: it is the only
        // free report that exercises HonoursOrgScope, OrgScopedReportInterface,
        // the anchored keyset walk, the suppression policy and
        // AdminAccessLogStore::verify() all at once, and every one of those was
        // a published promise with no production caller until it existed.
        $this->registries->reports->register(new SiteLearningReport($database, $clock, $ports->translator));
        $this->registries->reports->register(new EnrolmentRosterReport($database, $ports->users, $ports->translator));
        $this->recordLogin = new \Shondalai\VidyaLMS\Core\Application\Progress\RecordLogin($this->events, $ports->clock, $ports->random);
        foreach (['assignment-history', 'active-learning-time', 'training-activity'] as $evidenceReport) {
            $this->registries->reports->register(new \Shondalai\VidyaLMS\Core\Provider\Report\TrainingEvidenceReport(
                $evidenceReport,
                $database,
                $ports->users,
                $ports->translator,
            ));
        }
        $this->registries->reports->register(new OrgAccessLogReport($this->adminAccessLog, $ports->translator));
        // Advisory by design and free by argument: a report that prompts a
        // human tutor is the one shape of early warning GDPR Article 22 leaves
        // alone, and a site that cannot see who is falling behind until it buys
        // something is a site whose learners drop out first.
        $this->registries->reports->register(new \Shondalai\VidyaLMS\Core\Provider\Report\AtRiskLearnersReport(
            $database,
            $ports->users,
            $ports->translator,
            $clock,
        ));
        // The other two free organisation reports, so the catalogue's free line
        // is three definitions rather than one. org-overview is the re-homing
        // amendment C7 scheduled for this wave: GET .../summary stays as a
        // permanent alias and both it and the report now read one set of
        // queries. org-roster is built on membership rather than enrolment and
        // marks the people with no enrolment, who are precisely the ones an
        // organisation administrator has to chase.
        $this->registries->reports->register(new \Shondalai\VidyaLMS\Core\Provider\Report\OrgOverviewReport(
            $orgOverview,
            $ports->translator,
        ));
        $this->registries->reports->register(new \Shondalai\VidyaLMS\Core\Provider\Report\OrgRosterReport(
            $database,
            $ports->users,
            $ports->translator,
        ));
        // The free field disclosure policy, and the first provider that seam has
        // ever had. The free deny list is applied by the pipeline stage
        // identically on screen and in export, because disclosure implemented
        // per report is disclosure forgotten in the eleventh one.
        $this->registries->fieldDisclosure->register(
            new \Shondalai\VidyaLMS\Core\Provider\FieldDisclosure\OrgMinimalDisclosure($ports->translator),
        );

        // The four reporting registries' free providers. Each has a free code
        // path: csv and json are what every export route writes through,
        // download and notification-link are asked by the pipeline on every
        // run, and the log forwarder receives every statement the free build
        // already builds, so a site owner can see what a forwarder would send
        // before buying one. natural and mean are the one place the
        // free-provider rule is met by a test fixture rather than by a screen,
        // because the gradebook that will call them is a later wave, and that
        // is named here rather than counted quietly.
        $this->registries->exportFormats->register(new CsvExportFormat($ports->translator));
        $this->registries->exportFormats->register(new JsonExportFormat($ports->translator));
        $this->registries->reportDeliveries->register(new DownloadDelivery($ports->translator));
        $this->registries->reportDeliveries->register(new NotificationLinkDelivery(
            $this->notificationQueue,
            $this->notificationTemplates,
            $clock,
            $ports->translator,
            $ports->locale->current(),
            $this->notificationJobs,
        ));
        $this->registries->gradeAggregations->register(new NaturalGradeAggregation($ports->translator));
        $this->registries->gradeAggregations->register(new MeanGradeAggregation($ports->translator));
        // The three strategies the gradebook's own columns already implied.
        // weight_bp, drop_lowest and extra_credit were written, read back and
        // folded by nothing: a site could set them and be handed a plain mean.
        $this->registries->gradeAggregations->register(
            new \Shondalai\VidyaLMS\Core\Provider\GradeAggregation\WeightedMeanGradeAggregation($ports->translator),
        );
        $this->registries->gradeAggregations->register(
            new \Shondalai\VidyaLMS\Core\Provider\GradeAggregation\DropLowestGradeAggregation($ports->translator),
        );
        $this->registries->gradeAggregations->register(
            new \Shondalai\VidyaLMS\Core\Provider\GradeAggregation\ExtraCreditGradeAggregation($ports->translator),
        );
        // And the display half of the same story: display_mode has stored
        // "real", "percentage" and "letter" since the gradebook shipped and
        // nothing rendered any of them.
        $this->registries->gradeDisplays->register(
            new \Shondalai\VidyaLMS\Core\Provider\GradeDisplay\RealGradeDisplay($ports->translator),
        );
        $this->registries->gradeDisplays->register(
            new \Shondalai\VidyaLMS\Core\Provider\GradeDisplay\PercentageGradeDisplay($ports->translator),
        );
        $this->registries->gradeDisplays->register(
            new \Shondalai\VidyaLMS\Core\Provider\GradeDisplay\LetterGradeDisplay($ports->translator),
        );
        $this->registries->statementForwarders->register(new LogStatementForwarder($ports->logger, $ports->translator));

        $this->reportRuns = new ReportRunStore($database, $clock);
        $this->reportSchedules = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report\ReportScheduleStore(
            $database,
            $clock,
        );
        // The one read behind both delivery refusals: how many organisations a
        // recipient set spans. It is deliberately unanchored, because a read
        // narrowed to one tenant could never see the crossing it detects.
        $audienceSpan = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationAudienceSpan(
            $database,
        );
        $this->reportExports = new ReportExportPipeline(
            $this->registries->exportFormats,
            $this->registries->reportDeliveries,
            $this->reportRuns,
            $ports->storage,
            $clock,
            $this->settings,
            $ports->logger,
            new \Shondalai\VidyaLMS\Core\Application\Reporting\DisclosurePipeline($this->registries->fieldDisclosure),
            // How many organisations a recipient set spans, answered from
            // membership rows and never from a site-wide capability lookup. A
            // recipient who belongs to no organisation at all counts as their
            // own span of one, because they are somebody the run named rather
            // than somebody a tenant contributed.
            \Shondalai\VidyaLMS\Core\Domain\Reporting\DeliveryBoundaryPolicy::resolvedBy(
                static fn (array $recipients): int => $audienceSpan->spanOf($recipients),
            ),
        );
        $this->getSiteReport = new GetSiteReport(
            $this->registries->reports,
            $this->guard,
            $ports->translator,
            $this->reportExports,
            $this->settings,
        );
        $this->listReportDefinitions = new \Shondalai\VidyaLMS\Core\Application\Reporting\ListReportDefinitions(
            $this->registries->reports,
            $this->guard,
            $ports->translator,
            // The export control offers exactly what these two registries
            // answer with, so a package that registers xlsx or an sftp drop
            // appears in the picker without a screen being edited.
            $this->registries->exportFormats,
            $this->registries->reportDeliveries,
        );
        $this->startReportExport = new StartReportExport(
            $this->registries->reports,
            $this->reportExports,
            $this->guard,
            $this->settings,
        );
        $this->getReportRun = new GetReportRun($this->reportRuns, $this->reportExports, $this->guard, $orgAccessPolicy);
        $this->getLearnerTimeline = new \Shondalai\VidyaLMS\Core\Application\Reporting\GetLearnerTimeline(
            $database,
            $this->guard,
        );
        $this->manageReportSchedules = new \Shondalai\VidyaLMS\Core\Application\Reporting\ManageReportSchedules(
            $this->reportSchedules,
            $this->registries->reports,
            $this->registries->exportFormats,
            $this->registries->reportDeliveries,
            $this->registries->audiences,
            $this->guard,
            $clock,
        );
        $this->getOrganisationReport = new GetOrganisationReport(
            $this->registries->reports,
            $orgAccessPolicy,
            $this->reportExports,
            $ports->translator,
            $this->organisationRetention,
            $this->settings,
        );
        $this->collectAccessEvidence = new CollectAccessEvidence($database, $this->courseAccess, $clock);
        // report_runs rows and their artefacts are personal data and appear in
        // both adapter privacy manifests, which is what stops an erasure
        // request having to hunt object storage for an untracked blob.
        $this->registries->privacyContributors->register(
            new CoreReportingPrivacy($database, $this->reportRuns, $ports->storage),
        );
        $this->registries->scheduledTasks->register(new SweepReportRunsTask($this->reportRuns, $ports->storage));
        $this->registries->scheduledTasks->register(new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\PersonalLogRetentionTask(
            $database, $ports->clock, new \Shondalai\VidyaLMS\Core\Application\Platform\RetentionSettings($this->settings),
        ));
        $this->registries->scheduledTasks->register(new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\PrivacyFileCleanupTask(
            new \Shondalai\VidyaLMS\Core\Application\Platform\PrivacyFileDeletion($database, $ports->storage),
        ));
        // reports.schedule registers on the free build because report_schedules
        // is a free table here: the schedule, its per-recipient render and both
        // delivery refusals are the security half of scheduled reporting, and a
        // task type registered against a table that exists is exactly what the
        // operations surface's own rule asks for.
        $this->registries->scheduledTasks->register(new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ScheduleReportsTask(
            $this->reportSchedules,
            $this->registries->reports,
            $this->reportExports,
            $this->registries->audiences,
            $this->guard,
            $clock,
            $database,
            $ports->authorization,
            $ports->logger,
            $sweepAuthority,
        ));

        $this->expireEntitlements = new ExpireEntitlements(
            $entitlements,
            $enrolments,
            $this->enrolLearner,
            $this->events,
            $clock,
            $this->settings,
        );
        $this->listMyEnrolments = new ListMyEnrolments($this->guard, $enrolments);
        $this->myOrganisationLearning = new \Shondalai\VidyaLMS\Core\Application\Organisation\MyOrganisationLearning(
            $this->guard,
            $this->organisationAccess,
            $orgStore,
            $this->organisationCatalogue,
            $clock,
        );
        $this->listEnrolmentsForCourse = new ListEnrolmentsForCourse($this->courseAccess, $enrolments);

        // The gradebook's three stored things, and the one writer of all of
        // them. It is built before the progress recalculation because that
        // recalculation is now where a quiz grade cell is written from.
        $this->gradeBook = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Grades\GradeBookStore(
            $database,
            $clock,
        );
        $this->recordGrade = new \Shondalai\VidyaLMS\Core\Application\Grades\RecordGrade($this->gradeBook, $clock);
        $this->courseGradeTotals = new \Shondalai\VidyaLMS\Core\Application\Grades\CourseGradeTotals(
            $this->gradeBook,
            $this->registries->gradeAggregations,
        );
        $this->readGradebook = new \Shondalai\VidyaLMS\Core\Application\Grades\ReadGradebook(
            $this->gradeBook,
            $this->courseGradeTotals,
            $this->courseAccess,
            $database,
            $this->registries->gradeAggregations,
            $this->registries->gradeDisplays,
        );
        $this->regradeGradeCell = new \Shondalai\VidyaLMS\Core\Application\Grades\RegradeGradeCell(
            $this->gradeBook,
            $this->recordGrade,
            $this->readGradebook,
            $this->courseAccess,
            $database,
        );
        $this->recomputeCourseProgress = new RecomputeCourseProgress(
            $lessonProgress,
            $courseProgress,
            new CompletionEngine(),
            $this->guard,
            $this->events,
            $clock,
            $database,
            $lessonAvailability,
            $this->availability,
            $outbox,
            new \Shondalai\VidyaLMS\Core\Application\Grades\QuizGradeSync(
                $this->recordGrade,
                $this->gradeBook,
                $this->registries->gradeAggregations,
                $ports->logger,
            ),
        );
        $this->recomputeCourseProgressForCourse = new RecomputeCourseProgressForCourse(
            $this->recomputeCourseProgress,
            $database,
            $ports->scheduler,
            $clock,
        );

        // The two archives live here rather than with the other curriculum
        // use cases because removing a lesson changes who has completed the
        // course, and the recompute that answers that is built above.
        $this->archiveLesson = new ArchiveLesson(
            $lessons,
            $enrolments,
            $this->recomputeCourseProgressForCourse,
            $this->courseAccess,
            $this->events,
            $clock,
        );
        $this->archiveModule = new ArchiveModule(
            $modules,
            $lessons,
            $enrolments,
            $this->recomputeCourseProgressForCourse,
            $this->courseAccess,
            $database,
            $this->events,
            $clock,
        );
        $this->recordLessonProgress = new RecordLessonProgress(
            $lessonProgress,
            $videoPositions,
            $this->recomputeCourseProgress,
            $this->registries->lessonTypes,
            $this->guard,
            $this->events,
            $clock,
            $database,
            $lessonAvailability,
            $this->availability,
        );
        $this->getMyCourseProgress = new GetMyCourseProgress($lessonProgress, $courseProgress, $this->guard, $database);

        $this->ensureDefaultTemplate = new EnsureDefaultTemplate($certificateTemplates, $ports->translator);

        // The credential collaborators. The snapshot object is shared by the
        // issue path and the reissue path deliberately: two hand-built
        // freezes drift, and a reissue that froze less than the original
        // would be an evidentiary defect nobody notices until an audit.
        $this->certificateTokens = new CertificateTokens($this->registries->tokenResolvers);
        $issuanceSnapshot = new IssuanceSnapshot(
            $certificateTemplates,
            $this->certificateTokens,
            $ports->users,
            $courseTitles,
            $this->registries->creditSchemes,
            $ports->random,
            $this->verificationBaseUrl,
            $ports->locale,
            $siteTimezone === '' ? 'UTC' : $siteTimezone,
            $this->settings,
        );
        $resolveExpiry = new ResolveExpiry(
            $this->registries->expiryPolicies,
            $siteTimezone === '' ? 'UTC' : $siteTimezone,
        );
        $certificateJobs = new CertificateJobs($ports->scheduler, $clock);
        $this->credentialAssembly = new CredentialAssembly(
            $courseTitles,
            $clock,
            $certificateCredits,
            $credentialStatus,
            $certificateTemplates,
            $this->settings,
            $this->credentialBaseUrl,
            $this->verificationBaseUrl,
            $this->statusListBaseUrl,
            new CredentialElementClaims($this->registries->certificateElements),
            $this->issuerIdentity,
        );
        $frozenFormat = $this->settings->getString(FreezeCredential::SETTING_FORMAT, '');
        $freezeCredential = new FreezeCredential(
            $certificates,
            $this->registries->credentialFormats,
            $this->credentialAssembly,
            // The documented per-site choice had no writer at all, so the
            // sentence describing it was true of nothing. Empty means the
            // build's own highest-ranked storable format, which is what every
            // site gets until it names one.
            $frozenFormat === '' ? null : $frozenFormat,
        );
        $this->certificateTemplateChecks = new CertificateTemplateChecks(
            $this->registries->certificateElements,
            $this->certificateTokens,
        );

        // The template authoring surface. One aggregate object carries the
        // rules every write inside the three levels obeys (the revision
        // precondition, the criteria lock and the transaction), and one views
        // object carries the read, so a list and the row it opens cannot
        // disagree about whether an element is available.
        $templateAggregate = new CertificateTemplateAggregate($certificateTemplates, $clock, $database);
        $templateViews = new CertificateTemplateViews(
            $certificateTemplates,
            $this->registries->certificateElements,
            $this->certificateTemplateChecks,
            $this->featureProviders,
        );
        $expiryRule = new CertificateExpiryRule(
            $this->registries->expiryPolicies,
            $clock,
            $siteTimezone === '' ? 'UTC' : $siteTimezone,
        );
        $this->certificateStudio = new \Shondalai\VidyaLMS\Core\Application\Credentials\CertificateStudio(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $this->certificateTemplateChecks,
            $this->registries->certificateRenderers,
            new \Shondalai\VidyaLMS\Core\Provider\Certificate\CoreCertificateBlueprints($ports->translator, $ports->locale->current()),
            $this->guard,
            $clock,
            $ports->translator,
        );
        $this->courseCertificatePolicy = new \Shondalai\VidyaLMS\Core\Application\Credentials\CourseCertificatePolicy(
            $database,
            $this->courseAccess,
            $certificateTemplates,
            $this->certificateStudio,
            $this->ensureDefaultTemplate,
            $expiryRule,
            $this->registries->expiryPolicies,
            $clock,
        );
        $elementRule = new CertificateElementRule(
            $this->registries->certificateElements,
            $certificateTemplates,
            $this->certificateTokens,
            $courseCredits,
        );
        $this->listCertificateTemplates = new ListCertificateTemplates(
            $certificateTemplates,
            $templateViews,
            $this->guard,
            $this->listingPolicy,
            new \Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateThumbnail(
                $this->registries->certificateRenderers->get('elements'),
            ),
        );
        $this->getCertificateTemplate = new GetCertificateTemplate($templateAggregate, $templateViews, $this->guard);
        $this->createCertificateTemplate = new CreateCertificateTemplate(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $this->registries->certificateRenderers,
            $expiryRule,
            $this->guard,
        );
        $this->updateCertificateTemplate = new UpdateCertificateTemplate(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $this->registries->certificateRenderers,
            $expiryRule,
            $this->guard,
        );
        $this->archiveCertificateTemplate = new ArchiveCertificateTemplate(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $this->guard,
        );
        $this->listCertificateTemplatePages = new ListCertificateTemplatePages(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $this->guard,
        );
        $this->addCertificateTemplatePage = new AddCertificateTemplatePage(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $this->guard,
        );
        $this->updateCertificateTemplatePage = new UpdateCertificateTemplatePage(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $this->guard,
        );
        $this->deleteCertificateTemplatePage = new DeleteCertificateTemplatePage(
            $certificateTemplates,
            $templateAggregate,
            $this->guard,
        );
        $this->reorderCertificateTemplatePages = new ReorderCertificateTemplatePages(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $this->guard,
        );
        $this->listCertificateTemplateElements = new ListCertificateTemplateElements(
            $templateAggregate,
            $templateViews,
            $this->guard,
        );
        $this->addCertificateTemplateElement = new AddCertificateTemplateElement(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $elementRule,
            $this->guard,
        );
        $this->updateCertificateTemplateElement = new UpdateCertificateTemplateElement(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $elementRule,
            $this->guard,
        );
        $this->deleteCertificateTemplateElement = new DeleteCertificateTemplateElement(
            $certificateTemplates,
            $templateAggregate,
            $this->guard,
        );
        $this->reorderCertificateTemplateElements = new ReorderCertificateTemplateElements(
            $certificateTemplates,
            $templateAggregate,
            $templateViews,
            $this->guard,
        );
        $this->previewCertificateTemplate = new PreviewCertificateTemplate(
            $certificateTemplates,
            $templateAggregate,
            $this->certificateTemplateChecks,
            $this->registries->certificateRenderers,
            $this->certificateTokens,
            $certificates,
            $courseTitles,
            $ports->users,
            $this->guard,
            $clock,
            $ports->translator,
            $ports->locale,
            $this->verificationBaseUrl,
        );

        $this->issueCertificate = new IssueCertificate(
            $certificates,
            $certificateTemplates,
            $this->ensureDefaultTemplate,
            $this->events,
            $clock,
            $ports->random,
            $database,
            $outbox,
            $issuanceSnapshot,
            $courseCredits,
            $certificateCredits,
            $credentialStatus,
            $this->settings->getBool(VerifyCertificate::SETTING_PUBLISH_LEARNER_NAME, true),
            $resolveExpiry,
            $certificateJobs,
            $freezeCredential,
            $this->courseCertificatePolicy,
        );
        $this->renderCertificate = new RenderCertificate(
            $certificates,
            $certificateTemplates,
            $this->registries->certificateRenderers,
            $courseTitles,
            $ports->users,
            $this->guard,
            $ports->translator,
            $ports->locale,
            $this->certificateTokens,
            '',
            $clock,
        );
        $this->reissueCertificate = new ReissueCertificate(
            $certificates,
            $certificateTemplates,
            $certificateCredits,
            $issuanceSnapshot,
            $this->guard,
            $this->events,
            $clock,
            $ports->random,
            $outbox,
            $credentialStatus,
            $resolveExpiry,
            $database,
            $certificateJobs,
            $freezeCredential,
            $this->certificateStudio,
        );
        $this->reissueCertificatesForTemplate = new ReissueCertificatesForTemplate(
            $certificates,
            $certificateTemplates,
            $ports->scheduler,
            $clock,
            $this->guard,
            $this->certificateStudio,
        );
        $this->revokeCertificate = new RevokeCertificate($certificates, $this->guard, $clock, $credentialStatus);
        $this->retireCredential = new RetireCredential($credentialStatus, $this->guard, $clock);
        $this->suspendCertificate = new SuspendCertificate($certificates, $this->guard, $clock, $credentialStatus);
        $this->listMyCertificates = new ListMyCertificates($certificates, $this->guard);
        $this->verifyCertificate = new VerifyCertificate(
            $certificates,
            $courseTitles,
            $ports->users,
            $clock,
            $this->settings,
            $certificateCredits,
            $this->verificationBaseUrl,
            $this->credentialBaseUrl,
        );
        $this->getCredential = new GetCredential(
            $certificates,
            $this->registries->credentialFormats,
            $this->credentialAssembly,
        );

        $this->recordSchemaVersion = new RecordSchemaVersion($schemaVersions);
        $this->importMap = new ImportMap($importMapRows);
        $intake = new ImportIntake($this->registries->importers);
        $importRowProblems = new DatabaseImportRowProblemRepository($database);
        $runner = new ImportRunner($importJobs, $importRowProblems, $this->importMap);
        $this->runImportJob = new RunImportJob($importJobs, $intake, $runner, $this->guard);
        $this->queueImportJob = new QueueImportJob($importJobs, $intake, $ports->scheduler, $this->guard, $clock);
        $this->runQueuedImport = new RunQueuedImport($importJobs, $intake, $runner, $this->guard, $orgAccessPolicy);
        $this->getImportJob = new GetImportJob($importJobs, $this->guard);
        $this->abandonImportJob = new AbandonImportJob($importJobs, $this->guard, $clock);
        $this->listImportJobs = new ListImportJobs($importJobs, $this->guard);
        $this->listImporters = new \Shondalai\VidyaLMS\Core\Application\Platform\ListImporters(
            $this->registries->importers,
            $this->guard,
        );
        $this->importRowProblems = new \Shondalai\VidyaLMS\Core\Application\Platform\ImportRowProblems(
            $importJobs,
            $importRowProblems,
            $this->guard,
        );
        $this->pruneImportSources = new PruneImportSources($importJobs, $ports->storage, $clock, $importRowProblems);
        $this->organisationImports = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationImports(
            $orgAccessPolicy,
            new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgImportStore(
                $database,
                $importRowProblems,
            ),
            $this->queueImportJob,
            $this->runImportJob,
            $intake,
        );
        // The identity surface. It is built here, beside the import surface,
        // because the two are the same sentence in the free build's own
        // description: people arrive by invitation, by CSV, or because the
        // host asserted who they are. Its store is built earlier, with the
        // organisation store, because the invitation ceremony reads the join
        // rule row through it.
        $this->organisationConnections = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationConnections(
            $orgAccessPolicy,
            $orgIdentityStore,
            $orgStore,
            $this->registries->orgRoles,
            $this->registries->identityMappers,
            $ports->users,
        );
        $this->organisationJoining = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationJoining(
            $orgAccessPolicy,
            $orgIdentityStore,
            $orgStore,
            $this->registries->orgJoinRules,
            $this->registries->orgRoles,
            $ports->domainVerification,
            $this->settings,
            $ports->random,
            $clock,
        );
        $this->applyProvisioningAssertion = new \Shondalai\VidyaLMS\Core\Application\Organisation\ApplyProvisioningAssertion(
            $orgIdentityStore,
            $orgStore,
            $this->organisationConnections,
            $this->registries->provisioningSources,
        );
        $this->applyHostAssertion = new \Shondalai\VidyaLMS\Core\Application\Organisation\ApplyHostAssertion(
            $ports->assertedIdentity,
            $orgIdentityStore,
            $sweepAuthority,
            $this->applyProvisioningAssertion,
            $ports->verifiedAddresses,
        );
        // The domain re-check and the provisioning sweep are registered free
        // and both answer honestly on a stock build: no verifier is installed
        // and no registered source sweeps incrementally. A task key is stored
        // in host job rows and both drains resolve a handler by it, so a key
        // that appeared only with a paid package would fail to resolve on
        // every site that later removed one.
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\DomainRecheckTask(
                $orgIdentityStore,
                $ports->domainVerification,
                $clock,
            )
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ProvisioningSyncTask(
                $this->registries->provisioningSources,
                new \Shondalai\VidyaLMS\Core\Application\Organisation\ProvisioningWatermarks($database, $clock),
                $orgIdentityStore,
            )
        );
        $this->authoriseUpload = new AuthoriseUpload($this->guard, $database, $clock, $orgAccessPolicy);


        // ── The management surface ───────────────────────────────────────
        $gradebookRows = new DatabaseGradebookRepository($database);
        $siteMetrics = new DatabaseSiteMetricsRepository($database);

        $this->gradebookQueue = new GradebookQueue(
            $gradebookRows,
            $questionTypes,
            $ports->users,
            new SignedMedia($ports->storage),
            $this->registries->rubrics,
        );
        $managedCourseView = new ManagedCourseView($courses, $ports->users);
        $quizViews = new QuizViews($questions, $lessons, $modules);
        $authoringLessonView = new AuthoringLessonView(
            $lessons,
            $this->registries->lessonTypes,
            $quizzes,
            $quizViews,
            $assignments,
            $database,
        );
        $enrolmentListing = new EnrolmentListing($enrolments, $ports->users, $this->listingPolicy);
        $certificateRecords = new CertificateRecords($courses, $ports->users);

        $this->listManagedCourses = new ListManagedCourses(
            $courses,
            $managedCourseView,
            $this->guard,
            $this->listingPolicy,
        );
        $this->listCourseCategories = new ListCourseCategories(
            $ports->courseCategories,
            $this->guard,
            $this->listingPolicy,
        );
        $this->getCourseCategory = new GetCourseCategory($ports->courseCategories, $this->guard);
        $this->getManagedCourse = new GetManagedCourse(
            $courses,
            $managedCourseView,
            $this->gradebookQueue,
            $this->courseAccess,
        );
        $this->listInstructors = new ListInstructors($managedCourseView, $this->courseAccess);
        $this->getAuthoringLesson = new GetAuthoringLesson($lessons, $authoringLessonView, $this->courseAccess);
        $this->getAuthoringCurriculum = new GetAuthoringCurriculum(
            $modules,
            $lessons,
            $authoringLessonView,
            $this->courseAccess,
        );
        $this->addAuthoredLesson = new AddAuthoredLesson(
            $this->addLesson,
            $this->createQuiz,
            $this->updateQuiz,
            $this->configureAssignment,
            $quizzes,
            $modules,
            $this->getAuthoringLesson,
            $this->courseAccess,
            $database,
        );

        $this->listQuizzesForCourse = new ListQuizzesForCourse($quizzes, $quizViews, $this->courseAccess);
        $this->getQuizAuthoring = new GetQuizAuthoring($quizzes, $quizViews, $this->courseAccess);
        $this->manualGradingStatus = new ManualGradingStatus(
            $attempts,
            $quizzes,
            $manifest,
            $questionTypes,
        );

        $this->getGradebook = new GetGradebook($this->gradebookQueue, $this->courseAccess, $this->listingPolicy);
        $this->siteOverview = new SiteOverview(
            $siteMetrics,
            $gradebookRows,
            $certificates,
            $courses,
            $this->registries->lessonTypes,
            $this->settings,
            $this->guard,
            $clock,
            $sweepSchedule,
        );
        $this->accessGrantView = new AccessGrantView($enrolments);
        $this->enrolLearnerManually = new EnrolLearnerManually($this->grantEntitlement, $this->accessGrantView);
        $this->extendEntitlement = new ExtendEntitlement(
            $this->courseAccess,
            $entitlements,
            $enrolments,
            $this->enrolLearner,
            $clock,
        );
        $this->syncShopAccess = new \Shondalai\VidyaLMS\Core\Application\Commerce\SyncShopAccess(
            $database,
            $ports->users,
            $this->courseAccess,
            $entitlements,
            $this->grantEntitlement,
            $this->extendEntitlement,
            $this->revokeEntitlement,
            $this->enrolLearner,
            $this->events,
            $clock,
        );
        $this->listCourseLearners = new ListCourseLearners($enrolmentListing, $this->courseAccess);
        $this->listSiteEnrolments = new ListSiteEnrolments($enrolments, $enrolmentListing, $this->guard, $clock);
        $this->listLearners = new ListLearners(
            $enrolments,
            $ports->users,
            $this->guard,
            $this->listingPolicy,
        );
        $this->getLearnerCourseProgress = new GetLearnerCourseProgress(
            $enrolments,
            $enrolmentListing,
            $lessonProgress,
            $courseProgress,
            $this->courseAccess,
        );
        $this->gradeOutcomeReader = new GradeOutcomeReader($lessonProgress, $enrolments, $assignments);
        $this->listCertificates = new ListCertificates(
            $certificates,
            $certificateRecords,
            $ports->users,
            $this->guard,
            $clock,
            $this->listingPolicy,
        );
        $this->getLearnerRecord = new GetLearnerRecord(
            $enrolments,
            $enrolmentListing,
            $certificates,
            $certificateRecords,
            $ports->users,
            $this->guard,
            $clock,
        );
        $this->getPlatformSettings = new GetPlatformSettings(
            $certificateTemplates,
            $this->registries->commerceSources,
            $this->settings,
            $this->guard,
            $this->verificationBaseUrl,
            $sweepSchedule,
            $hostSettingsUrl,
            $selfEnrolment,
            $this->issuerIdentity,
            $ports->users,
            $configuration,
        );
        $this->searchUsers = new SearchUsers($ports->users, $this->guard);
        $this->listProviderTypes = new ListProviderTypes(
            $this->registries->lessonTypes,
            $this->registries->questionTypes,
            $this->guard,
            $this->registries->questionPresets,
        );
        $this->certificateRecords = $certificateRecords;

        // Importers depend on the use cases they write through, so they are
        // registered once those exist rather than with the other providers.
        $this->registries->importers->register(new JsonCourseImporter(
            $ports->storage,
            $this->importMap,
            $ports->translator,
            $this->createCourse,
            $this->updateCourse,
            $this->addModule,
            $this->updateModule,
            $this->addLesson,
            $this->updateLesson,
            $this->moveLesson,
            $this->createQuiz,
            $this->updateQuiz,
            $this->addQuestion,
            $this->updateQuestion,
        ));
        $this->registries->importers->register(new MoodleBackupImporter(
            $ports->storage,
            $this->importMap,
            $ports->translator,
            $this->createCourse,
            $this->updateCourse,
            $this->addModule,
            $this->updateModule,
            $this->addLesson,
            $this->updateLesson,
            $this->createQuiz,
            $this->updateQuiz,
            $this->addQuestion,
            $this->updateQuestion,
        ));
        $this->registries->importers->register(new CommunityQuizImporter(
            $database,
            $this->importMap,
            $ports->translator,
            $this->createCourse,
            $this->updateCourse,
            $this->publishCourse,
            $this->setPrerequisites,
            $this->addModule,
            $this->updateModule,
            $this->reorderModules,
            $this->addLesson,
            $this->updateLesson,
            $this->moveLesson,
            $this->reorderLessons,
            $this->createQuiz,
            $this->updateQuiz,
            $this->addQuestion,
            $this->updateQuestion,
            $this->configureAssignment,
            $this->grantEntitlement,
        ));

        // The three WordPress source importers. Each reads its product's own
        // tables through DatabasePort::fromHostTable with the names taken from
        // a closed constant list, and each declares itself unverified against a
        // real install in its label and in its log, because none of the three
        // products is installed on either development site. They are
        // registered here, beside the shipped importers, rather than behind a
        // host check: an importer that is not offered because its product is
        // absent is what supports() already answers.
        foreach ([LearnDashImporter::class, TutorLmsImporter::class, LifterLmsImporter::class] as $wordPressImporter) {
            $this->registries->importers->register(new $wordPressImporter(
                $database,
                $this->importMap,
                $ports->translator,
                $this->createCourse,
                $this->updateCourse,
                $this->addModule,
                $this->updateModule,
                $this->addLesson,
                $this->updateLesson,
                $this->createQuiz,
                $this->updateQuiz,
                $this->addQuestion,
                $this->updateQuestion,
                $this->grantEntitlement,
                $this->enrolLearner,
                $this->recordLessonProgress,
            ));
        }

        // The three Joomla source importers. Like the WordPress three above,
        // each reads its product's own tables through
        // DatabasePort::fromHostTable with the names taken from a closed
        // constant list, and each declares itself unverified against a real
        // install in its label and in the first line of every run's log.
        //
        // They share one writer rather than each carrying its own. Reading is
        // where three foreign schemas differ and writing is where they must
        // not: one answer to what a re-run does, one answer to what an
        // unsupported type costs, one set of use cases, constructed once.
        $joomlaSourceWriter = new CurriculumWriter(
            $this->importMap,
            $this->createCourse,
            $this->updateCourse,
            $this->publishCourse,
            $this->addModule,
            $this->updateModule,
            $this->addLesson,
            $this->updateLesson,
            $this->createQuiz,
            $this->updateQuiz,
            $this->addQuestion,
            $this->updateQuestion,
            $this->grantEntitlement,
            $this->enrolLearner,
            $this->recordLessonProgress,
        );

        foreach ([ShikaImporter::class, GuruImporter::class, OSCampusImporter::class] as $joomlaImporter) {
            $this->registries->importers->register(
                new $joomlaImporter($database, $ports->translator, $joomlaSourceWriter),
            );
        }

        // The two free roster importers, and the two roster mappers behind
        // them. They are the first shipped declarers of RunsForAnOrganisation,
        // PreflightsBeforeWriting and StagedImporterInterface, which is what
        // turns the organisation import surface from a route with nothing to
        // run into the free build's "adds people by CSV" sentence.
        //
        // The mapper is registered on its own seam and the importer that
        // drives it on the Importer registry, under the same key. That is the
        // split the design asks for: a roster load is an importer and not a
        // second pipeline, and the format it reads is an anti-corruption
        // mapper a third party can register without writing an importer.
        foreach (
            [
                [new OrgMembersCsvMapper(), StandardOrgRoleMapper::ROLE_MEMBER, RoleMappingContext::VOCABULARY_UNKNOWN],
                [new OneRosterCsvMapper(), null, RoleMappingContext::VOCABULARY_ONEROSTER],
            ] as [$rosterMapper, $defaultRole, $vocabulary]
        ) {
            $this->registries->rosterMappers->register($rosterMapper);
            $this->registries->importers->register(new RosterImporter(
                $rosterMapper,
                $ports->storage,
                $this->importMap,
                $ports->translator,
                $orgAccessPolicy,
                $orgStore,
                $this->organisationMembers,
                $this->organisationUnits,
                $this->registries->orgRoleMappers,
                $this->registries->orgRoles,
                $ports->users,
                $this->organisationInvitations,
                $defaultRole,
                $vocabulary,
            ));
        }

        // Deferred work is a registry, not a switch in each adapter. A host's
        // queue runner resolves the row's type here, so a package that queues
        // a type of its own runs on both hosts without either adapter
        // changing, and a type queued with no handler is a registry miss at
        // boot rather than a failed queue row nobody reads.
        $this->registries->scheduledTasks->register(new ExpireEntitlementsTask($this->expireEntitlements));
        $this->registries->scheduledTasks->register(new RenderCertificateTask(
            $this->renderCertificate,
            $ports->storage,
            $this->verificationBaseUrl,
            $certificates,
            $clock,
        ));
        // Bulk reissue is two task types, not one: the fan-out resumes at the
        // template and each per-certificate task resumes at the certificate,
        // so one failure is one row rather than a whole cohort.
        $this->registries->scheduledTasks->register(
            new ReissueCertificatesTask($this->reissueCertificatesForTemplate),
        );
        $this->registries->scheduledTasks->register(new ReissueCertificateTask($this->reissueCertificate));
        // The reconciliation sweep. Completion is terminal, so a failed
        // issuance leaves an enrolment complete for ever with no certificate
        // and nothing else in the product would ever look for the gap.
        $this->registries->scheduledTasks->register(new BackfillCertificatesTask(new BackfillCertificates(
            $database,
            $this->issueCertificate,
            $clock,
            $this->settings,
            $this->entitlementRepository,
        )));
        $this->registries->scheduledTasks->register(
            new GenerateReportTask(
                $this->registries->reports,
                $ports->storage,
                $this->reportExports,
                $this->guard,
            ),
        );
        $this->registries->scheduledTasks->register(new RunImportTask($this->runQueuedImport));
        $this->registries->scheduledTasks->register(
            new RecomputeEnrolmentProgressTask($this->recomputeCourseProgress),
        );
        $this->registries->scheduledTasks->register(
            new RecomputeCourseProgressTask($this->recomputeCourseProgressForCourse),
        );
        // The four learning-flow jobs. Access never depends on any of them:
        // the unlock notice and the sweep both tolerate lateness, and the
        // engine evaluates on read.
        $this->registries->scheduledTasks->register(
            new UnlockNoticeTask($this->availability, $this->events, $database, $clock),
        );
        $this->registries->scheduledTasks->register(new NotificationFanoutTask($this->notificationFanout));
        $this->registries->scheduledTasks->register(new NotificationSendTask($this->notificationSender));
        $this->registries->scheduledTasks->register(new NotificationSweepTask(
            $this->registries->notificationTriggers,
            $this->notificationRules,
            $this->notificationEnqueue,
            $database,
            $clock,
            $siteTimezone === '' ? 'UTC' : $siteTimezone,
        ));


        // ── The free automation engine ──────────────────────────────────
        //
        // Nine free actions, three retry ladders and four failure policies, so
        // every registry this wave seats has at least one working free
        // provider and a free code path that reaches it. Nothing here is AI:
        // no action declares DeclaresCost, nothing resolves a model bridge and
        // nothing reads a credential.
        $this->automationRules = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository(
            $database,
            $clock,
        );
        $this->automationRuns = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRunRepository(
            $database,
            $clock,
        );
        $this->automationQueue = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationQueueRepository(
            $database,
            $clock,
        );
        $this->automationActionLog = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationActionLogRepository(
            $database,
            $clock,
        );
        $this->automationRuleTests = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleTestRepository(
            $database,
            $clock,
        );
        $this->automationLoops = new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationLoopGuard(
            $this->automationRuns,
            $clock,
            $this->settings,
        );
        $this->automationCascade = new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationCascade();
        $this->automationJobs = new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationJobs(
            $ports->scheduler,
            $clock,
        );
        $this->openAutomationRuns = new \Shondalai\VidyaLMS\Core\Application\Automation\OpenAutomationRuns(
            $this->automationRules,
            $this->automationRuns,
            $this->automationLoops,
            // The cascade is stamped from the step frame in force rather than
            // by the calling action, so a third-party action that opens runs
            // without inheriting anything is still bounded by the ceiling and
            // the chain.
            $this->automationCascade,
            $this->registries->notificationTriggers,
            $this->automationJobs,
        );
        // The outbox's automation half, filled now that the engine exists. A
        // committed enrolment can now open the automation runs its trigger
        // names, inside the same transaction the notification intent is
        // written in.
        $this->automationOutbox->bind($this->openAutomationRuns, $this->automationJobs);

        // The three ladders and the four dispositions, registered by name so
        // the save-time check that refuses an inert retry-plus-continue
        // pairing reads two registries rather than two hardcoded lists.
        $this->registries->retryPolicies->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\ExternalDefaultRetry());
        $this->registries->retryPolicies->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\GentleRetry());
        $this->registries->retryPolicies->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\NoRetry());
        $this->registries->failurePolicies->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\ParkFailurePolicy());
        $this->registries->failurePolicies->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\HaltFailurePolicy());
        $this->registries->failurePolicies->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\ContinueFailurePolicy());
        $this->registries->failurePolicies->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\ErrorBranchFailurePolicy());

        // notify delegates to the notification engine's own enqueue: there is
        // exactly one mailer path in the product, with one consent evaluation,
        // one quiet-hours evaluation and one frequency cap.
        $this->registries->automationActions->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\NotifyAction(
            $this->notificationEnqueue,
            $this->automationRules,
            $this->automationRuns,
        ));
        $this->registries->automationActions->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\EnrolAction(
            $this->grantEntitlement,
            $entitlements,
        ));
        $this->registries->automationActions->register(
            new \Shondalai\VidyaLMS\Core\Provider\Automation\UnenrolAction($this->revokeEntitlementBySourceRef, $entitlements),
        );
        $this->registries->automationActions->register(
            \Shondalai\VidyaLMS\Core\Provider\Automation\CohortMembershipAction::add($database, $clock),
        );
        $this->registries->automationActions->register(
            \Shondalai\VidyaLMS\Core\Provider\Automation\CohortMembershipAction::remove($database, $clock),
        );
        $this->registries->automationActions->register(
            \Shondalai\VidyaLMS\Core\Provider\Automation\LearnerTagAction::set($database, $clock),
        );
        $this->registries->automationActions->register(
            \Shondalai\VidyaLMS\Core\Provider\Automation\LearnerTagAction::clear($database, $clock),
        );
        $this->registries->automationActions->register(
            new \Shondalai\VidyaLMS\Core\Provider\Automation\CreateReviewTaskAction(),
        );
        $this->registries->automationActions->register(new \Shondalai\VidyaLMS\Core\Provider\Automation\EmitEventAction(
            $this->openAutomationRuns,
            $this->automationLoops,
        ));

        $this->automationFanout = new \Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationFanout(
            $this->automationRuns,
            $this->automationRules,
            $this->automationQueue,
            $this->registries->automationActions,
            $this->registries->retryPolicies,
            $this->registries->failurePolicies,
            $this->automationLoops,
            $clock,
            $this->guard,
            new ConditionsStillHold($this->availabilityComposer, $database),
            $this->registries->scheduleAnchors,
            $this->automationJobs,
            $siteTimezone === '' ? 'UTC' : $siteTimezone,
        );
        $this->automationStep = new \Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationStep(
            $this->automationQueue,
            $this->automationRules,
            $this->automationRuns,
            $this->automationActionLog,
            $this->registries->automationActions,
            $this->registries->retryPolicies,
            $this->registries->failurePolicies,
            $this->idempotencyStore,
            $clock,
            $this->guard,
            new ConditionsStillHold($this->availabilityComposer, $database),
            // The claim and the effect commit together, which is the order the
            // design publishes and the docblock claimed while the code did
            // neither: a step killed after its effect and before its outcome
            // ran the effect a second time once the lease expired.
            $database,
            $this->automationCascade,
        );
        $this->automationSweep = new \Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationSweep(
            $this->registries->notificationTriggers,
            $this->automationRules,
            $this->openAutomationRuns,
            $database,
            $clock,
            $siteTimezone === '' ? 'UTC' : $siteTimezone,
        );
        $this->previewAutomationRule = new \Shondalai\VidyaLMS\Core\Application\Automation\PreviewAutomationRule(
            $this->automationRules,
            $this->automationRuns,
            $this->automationQueue,
            $this->openAutomationRuns,
            $this->automationFanout,
            $this->automationStep,
            $this->registries->automationActions,
            $this->guard,
            $clock,
        );
        $this->manageAutomationRules = new \Shondalai\VidyaLMS\Core\Application\Automation\ManageAutomationRules(
            $this->automationRules,
            $this->automationRuleTests,
            new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationRuleValidator(
                $this->registries->automationActions,
                $this->registries->retryPolicies,
                $this->registries->failurePolicies,
                $database,
                // The trigger check runs on every save path and not only on
                // the wire, so a package, an importer or a course copy cannot
                // store and arm a rule on a trigger nobody answers.
                $this->registries->notificationTriggers,
            ),
            $this->registries->automationActions,
            $this->guard,
            // The reference rows, under owner_type automation_rule. The value
            // was added to tables.php with the argument that it makes a course
            // copy remap an automation's references, and nothing wrote one.
            $this->availabilityRules,
            $this->availabilityValidator,
        );
        $this->runStoredRuleTest = new \Shondalai\VidyaLMS\Core\Application\Automation\RunStoredRuleTest(
            $this->automationRuleTests,
            $this->automationRules,
            $this->previewAutomationRule,
            $this->guard,
            // The condition half of the expectation grammar, which was stored,
            // versioned and never evaluated.
            new ConditionsStillHold($this->availabilityComposer, $database),
            $clock,
        );

        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationSweepTask($this->automationSweep),
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationFanoutTask($this->automationFanout),
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationStepTask($this->automationStep),
        );

        // The wire side, built after the engine it exposes and holding none of
        // its rules.
        $this->automationReads =
            new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationReadModel($database);
        $automationPresenter = new \Shondalai\VidyaLMS\Core\Http\Presenter\AutomationPresenter();
        $this->automationCatalogue = new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationCatalogue(
            $this->registries->notificationTriggers,
            $this->registries->availabilityConditions,
            $this->registries->automationActions,
            $this->registries->retryPolicies,
            $this->registries->failurePolicies,
            $this->registries->scheduleAnchors,
            $this->guard,
            translator: $ports->translator,
        );
        $this->automationRuleAdmin = new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationRuleAdmin(
            $this->manageAutomationRules,
            $this->automationReads,
            $this->automationQueue,
            $this->registries->notificationTriggers,
            $this->registries->automationActions,
            $this->registries->retryPolicies,
            $this->registries->failurePolicies,
            $automationPresenter,
            $this->guard,
        );
        $this->automationRulePreview = new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationRulePreview(
            $this->previewAutomationRule,
            $this->automationReads,
            $this->registries->automationActions,
            $this->guard,
        );
        $this->automationRuleTesting = new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationRuleTesting(
            $this->manageAutomationRules,
            $this->runStoredRuleTest,
            $this->automationReads,
            $automationPresenter,
            $this->guard,
        );
        $this->automationRunLog = new \Shondalai\VidyaLMS\Core\Application\Automation\AutomationRunLog(
            $this->automationReads,
            $automationPresenter,
            $this->guard,
        );
        $this->releaseAutomationStep = new \Shondalai\VidyaLMS\Core\Application\Automation\ReleaseAutomationStep(
            $this->automationQueue,
            $this->automationRuns,
            $this->automationActionLog,
            $clock,
            $this->guard,
            $this->automationJobs,
        );

        // ── The free providers for all eight AI seams ───────────────────
        // The exit gate of the free AI build, and the moment the deliberate
        // deferral recorded in the Registries docblock is spent: every one of
        // the eight AI seams now has at least one working free provider and a
        // free code path that reaches it. Nothing registered here opens a
        // socket, holds a credential or names a vendor, and the one bridge
        // among them reaches no model by construction.
        AiProviders::registerInto(
            $this->registries,
            $database,
            $this->courseAccess,
            $this->addQuestion,
            $this->addModule,
            $this->addAuthoredLesson,
            $this->getAuthoringLesson,
            $this->updateLesson,
            $this->gradeAnswerManually,
        );

        // ── The AI surface, free on every build ─────────────────────────
        // Built after the registries it reads and holding none of their rules.
        // Every route it serves answers on a site that has bought nothing: the
        // placements render and explain honestly, the policy is drafted and
        // accepted, a run is logged and refused with a typed reason, a draft is
        // reviewed and decided about, and the learner can read what touched
        // their data. The one thing a free site has none of is an enabled
        // route, which is why every task answers not_configured rather than
        // failing at click time.
        $this->aiReads = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel($database);
        $this->aiAccess = new \Shondalai\VidyaLMS\Core\Application\Ai\AiAccess($this->guard, $this->courseAccess);
        $this->aiEnablement = new \Shondalai\VidyaLMS\Core\Application\Ai\AiEnablementState($providerSettings);
        $this->aiPolicyCentre = new \Shondalai\VidyaLMS\Core\Application\Ai\AiPolicyCentre(
            $this->aiReads,
            $this->aiEnablement,
            $this->aiAccess,
            $database,
            $clock,
        );
        $this->aiPlacementCatalogue = new \Shondalai\VidyaLMS\Core\Application\Ai\AiPlacementCatalogue(
            $this->registries,
            $this->aiEnablement,
            $this->aiPolicyCentre,
            $this->aiAccess,
            $database,
            $ports->translator,
        );
        $this->aiDraftReview = new \Shondalai\VidyaLMS\Core\Application\Ai\AiDraftReview(
            $this->aiReads,
            $this->aiAccess,
            $this->registries,
            $database,
            $clock,
            $this->featureProviders,
        );
        $this->aiRunLog = new \Shondalai\VidyaLMS\Core\Application\Ai\AiRunLog($this->aiReads, $this->aiAccess);
        $this->myAiActivity = new \Shondalai\VidyaLMS\Core\Application\Ai\GetMyAiActivity(
            $this->aiReads,
            $this->aiAccess,
            $this->registries,
            $clock,
            $ports->users,
            $ports->translator,
        );
        $this->aiRouteAdmin = new \Shondalai\VidyaLMS\Core\Application\Ai\AiRouteAdmin(
            $this->aiReads,
            $this->aiAccess,
            $this->registries,
            $database,
            $clock,
            $ports->translator,
        );
        $this->aiPolicyAdmin = new \Shondalai\VidyaLMS\Core\Application\Ai\AiPolicyAdmin(
            $this->aiReads,
            $this->aiAccess,
            $this->registries,
            $database,
            $clock,
        );
        $this->aiStopControl = new \Shondalai\VidyaLMS\Core\Application\Ai\AiStopControl(
            $this->aiEnablement,
            $this->aiAccess,
            $this->registries,
            $this->aiReads,
            $providerSettings,
            $clock,
            $ports->translator,
            $this->featureProviders,
        );
        // The usage report is a registered report rather than a screen query,
        // so it declares its own required capability and a site wanting a
        // different cut registers a report instead of waiting for a route.
        $this->registries->reports->register(
            new \Shondalai\VidyaLMS\Core\Provider\Report\AiUsageReport(
                $this->aiReads,
                $this->registries,
                $ports->translator,
            ),
        );
        $this->aiUsageReport = new \Shondalai\VidyaLMS\Core\Application\Ai\GetAiUsageReport(
            $this->registries,
            $this->guard,
            $this->aiReads,
            $clock,
        );

        $this->aiRunStore = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiRunRepository(
            $database,
            $clock,
        );
        $this->aiDraftStore = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiDraftRepository(
            $database,
            $clock,
        );
        $this->aiJobStore = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiJobRepository(
            $database,
            $clock,
        );
        $this->aiProvenanceStore =
            new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiProvenanceRepository($database);
        $this->aiJobMapStore =
            new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiJobMapRepository($database);

        // The salt every pseudonym on this site is minted with, resolved once
        // and never derived from a public value.
        $siteRef = (new \Shondalai\VidyaLMS\Core\Application\Ai\AiSiteRef(
            $this->settings,
            $ports->random,
        ))->value();

        $this->aiPipeline = new \Shondalai\VidyaLMS\Core\Application\Ai\AiRunPipeline([
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\ResolvePlacementStage(
                $this->registries->aiTasks,
                $this->registries->aiPlacements,
            ),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\EvaluatePoliciesStage(
                $this->registries->aiPolicies,
                new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\AiDecisionContextFactory(
                    $this->aiRunStore,
                    $this->aiEnablement,
                ),
            ),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\AssembleFactsStage(
                $this->registries->aiFactProviders,
                $database,
                $clock,
            ),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\ResolveGroundingStage($database),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\RedactStage($this->registries->aiRedactors, $siteRef),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\NarrowSchemaStage(
                new \Shondalai\VidyaLMS\Core\Application\Ai\AiSchemaNarrower(),
            ),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\EstimateAndReserveStage(
                $this->aiRunStore,
                $database,
                $siteRef,
                \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\EstimateAndReserveStage::DEFAULT_RETENTION_DAYS,
                $this->registries->modelBridges,
                $this->registries->aiPolicies,
                new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\AiDecisionContextFactory(
                    $this->aiRunStore,
                    $this->aiEnablement,
                ),
            ),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\IterateRouteStage(
                $this->aiRunStore,
                $siteRef,
                null,
                new \Shondalai\VidyaLMS\Core\Application\Ai\AiBridgeCallerFactory(
                    $this->registries->modelBridges,
                    $this->registries->aiRedactors,
                    new \Shondalai\VidyaLMS\Core\Application\Ai\AiSchemaNarrower(),
                    new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiAttemptLedger($this->aiRunStore),
                    new \Shondalai\VidyaLMS\Core\Application\Ai\InMemoryReservationLedger(),
                    $clock,
                    $siteRef,
                ),
            ),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\RecordAttemptStage($this->aiRunStore),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\ValidateOutputStage(),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\SettleStage($this->aiRunStore),
            new \Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\WriteDraftsStage($this->aiDraftStore),
        ], $clock, $ports->random);

        $this->startAiRun = new \Shondalai\VidyaLMS\Core\Application\Ai\StartAiRun(
            $this->aiPipeline,
            $this->aiAccess,
            $this->aiEnablement,
        );
        $this->estimateAiRun = new \Shondalai\VidyaLMS\Core\Application\Ai\EstimateAiRun(
            $this->aiPipeline,
            $this->aiAccess,
        );
        $this->aiJobRunner = new \Shondalai\VidyaLMS\Core\Application\Ai\AiJobRunner(
            $this->aiJobStore,
            $this->aiPipeline,
            $this->registries->aiRecipes,
            $this->aiEnablement,
            $this->aiJobMapStore,
        );
        $this->queueAiJob = new \Shondalai\VidyaLMS\Core\Application\Ai\QueueAiJob(
            $this->aiJobStore,
            $this->registries->aiTasks,
            $this->registries->aiRecipes,
            $ports->scheduler,
            $this->aiAccess,
            $clock,
        );
        $this->runQueuedAiJob = new \Shondalai\VidyaLMS\Core\Application\Ai\RunQueuedAiJob(
            $this->aiJobStore,
            $this->aiJobRunner,
            $this->aiAccess,
            $orgAccessPolicy,
        );
        $this->acceptAiDraft = new \Shondalai\VidyaLMS\Core\Application\Ai\AcceptAiDraft(
            $this->aiDraftStore,
            $this->aiRunStore,
            $this->aiProvenanceStore,
            $this->registries->aiDraftAcceptors,
            $this->aiAccess,
            $database,
            $clock,
        );
        $this->recallAiRun = new \Shondalai\VidyaLMS\Core\Application\Ai\RecallAiRun(
            $this->aiRunStore,
            $this->aiProvenanceStore,
            $this->aiAccess,
            $database,
            $clock,
        );
        $this->registerAiTasks($database, $clock);


        $this->recordDefaultProviders();
        $this->subscribeListeners($lessonProgress, $activityLog);
    }

    /**
     * The five vidyalms.ai.* scheduled task types.
     *
     * The job drain is keyed per job; the chunker is keyed per course, because
     * re-indexing answers an edit and not a clock; the other three are daily
     * periodic sweeps. Every one of them is free and every one is reachable on a
     * site with no model configured at all, which is what stops a key that
     * appeared only with a paid package failing to resolve on a site that later
     * removed one.
     */
    private function registerAiTasks(
        \Shondalai\VidyaLMS\Core\Port\Database\DatabasePort $database,
        \Shondalai\VidyaLMS\Core\Port\Clock\ClockPort $clock
    ): void {
        // The five vidyalms.ai.* task types. The job drain is keyed per job; the
        // chunker is keyed per course, because re-indexing answers an edit and not
        // a clock; the other three are daily periodic sweeps.
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\RunAiJobTask($this->runQueuedAiJob),
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AiChunkTask(
                new \Shondalai\VidyaLMS\Core\Application\Ai\AiGroundingIndexer($database, $clock),
            ),
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AiRetentionTask(
                new \Shondalai\VidyaLMS\Core\Application\Ai\AiRetentionSweep(
                    $this->aiRunStore,
                    $this->aiDraftStore,
                    $clock,
                ),
            ),
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AiStaleDraftsTask(
                new \Shondalai\VidyaLMS\Core\Application\Ai\AiStaleDraftSweep($this->aiDraftStore, $clock),
            ),
        );
        $this->registries->scheduledTasks->register(
            new \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AiReconcileTask(
                new \Shondalai\VidyaLMS\Core\Application\Ai\AiUsageReconciliation($this->registries->modelBridges),
            ),
        );
    }

    /**
     * Runs one queued job through the registered handler for its type.
     *
     * The host's runner owns claiming the row, retry, backoff and the log; the
     * type to handler mapping is the core's, so both hosts and every package
     * agree about it. Null means no handler is registered for that type, which
     * a runner reports as a configuration problem rather than a failure to
     * retry.
     *
     * @param array<string, mixed> $payload
     */
    public function runScheduledTask(string $type, int $jobId, array $payload, Principal $principal): ?string
    {
        if ($this->runtimeFailure !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException($this->runtimeFailure);
        }
        if (!$this->registries->scheduledTasks->has($type)) {
            return null;
        }

        return $this->registries->scheduledTasks
            ->get($type)
            ->run(new ScheduledTaskRun($jobId, $payload, $principal));
    }


    /**
     * The host services this kernel was booted with.
     *
     * Published because the kernel-ready seam is a contract rather than an
     * adapter convention: a listener that receives only the registries can
     * register a provider that computes, and cannot register one that reads
     * a row. Every extension point whose providers are repository-backed
     * (placement, item analysis, pool predicates, integrity kinds) needs a
     * database, a clock and a translator, so a seam that hands over
     * registries alone forces each package to invent its own hand-off and
     * each host to invent its own payload. KernelReady is the payload; this
     * is what makes it buildable.
     *
     * It is the same object the adapter constructed, not a copy: a package
     * that resolves Ports from the host container and one that takes it from
     * here must see one database connection, or a transaction opened through
     * one is invisible to the other.
     */
    public function ports(): Ports
    {
        return $this->ports;
    }

    /**
     * Routes a package outside the core contributes to the same /v1 kernel.
     *
     * Pro and third-party packages add endpoints from a kernel-ready listener
     * rather than by editing this class, which is what keeps the free build's
     * bytes identical whether or not Pro is installed. Contributions made
     * before the kernel is built are queued and registered immediately after
     * the core's own routes; contributions made afterwards register straight
     * away, so a package that boots late still lands. A method and pattern
     * already claimed throws, and the exception names both.
     */
    public function addRoutes(Route ...$routes): void
    {
        if ($this->apiKernel !== null) {
            foreach ($routes as $route) {
                $this->apiKernel->register($route);
            }

            return;
        }

        foreach ($routes as $route) {
            $this->contributedRoutes[] = $route;
        }
    }
    private ?string $runtimeFailure = null;

    /** Keep host administration available while an installed provider cannot safely run. */
    public function disableRuntime(string $reason): void
    {
        $this->runtimeFailure = $reason;
        $this->apiKernel()->use(new class ($reason) implements \Shondalai\VidyaLMS\Core\Http\Middleware {
            public function __construct(private readonly string $reason) {}
            public function process(\Shondalai\VidyaLMS\Core\Http\ApiRequest $request, callable $next): \Shondalai\VidyaLMS\Core\Http\ApiResponse
            {
                return new \Shondalai\VidyaLMS\Core\Http\ApiResponse(503, ['detail' => $this->reason], ['Cache-Control' => 'no-store', 'Retry-After' => '300']);
            }
        });
    }

    public function apiKernel(): ApiKernel
    {
        if ($this->apiKernel !== null) {
            return $this->apiKernel;
        }

        $kernel = new ApiKernel($this->ports->logger);
        $idempotent = new IdempotentExecution($this->idempotencyStore);
        foreach (
            [
            ['GET', '/manage/organisations/{id}/privacy', 'catalogue'],
            ['GET', '/manage/organisations/{id}/privacy/export', 'export'],
            // The retention policy the organisation controls, and the one
            // refusal it may meet: a period shorter than the site's own access
            // log floor. The audited party does not set the audit log's
            // retention, and the refused attempt is recorded before it is
            // thrown.
            ['PUT', '/manage/organisations/{id}/retention-policy', 'retention'],
            ['POST', '/manage/organisations/{id}/members/{member_id}/erase', 'erase'],
            ['GET', '/manage/organisations/{id}/events/export', 'audit'],
            ] as [$method, $path, $operation]
        ) {
            $kernel->register(new Route($method, $path, new \Shondalai\VidyaLMS\Core\Http\Handler\OrganisationPrivacyHandler(
                $operation,
                $this->organisationPrivacy,
                $this->organisationAuditExport,
                $idempotent,
                $this->ports->database,
                $this->organisationLearningEvents,
            )));
        }
        \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRoutes::register(
            $kernel,
            $this->organisations,
            $this->organisationMembers,
            $this->organisationAccess,
            $idempotent,
            $this->ports->database,
            $this->organisationLearningEvents,
            $this->myOrganisationLearning,
        );
        \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningRoutes::register(
            $kernel,
            $this->organisationLearning,
            $this->organisationSeats,
            $this->organisationAccess,
            $idempotent,
            $this->ports->database,
            $this->organisationLearningEvents,
            $this->transferSeat,
        );
        \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationUnitRoutes::register(
            $kernel,
            $this->organisationUnits,
            $this->organisationAccess,
            $idempotent,
            $this->ports->database,
        );
        // The catalogue surface: the organisation's own selection from the
        // releases above. Free, and reachable on a stock build, because the
        // two free sources are registered.
        \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationCatalogueRoutes::register(
            $kernel,
            $this->organisationCatalogue,
        );
        // The compliance authoring surface. It is what gives the four shipped
        // compliance tables a writer, and therefore what makes a rule package
        // exercisable after it is installed.
        \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationComplianceRoutes::register(
            $kernel,
            $this->organisationCompliance,
        );
        // The organisation import surface. Without it the free build's own
        // sentence about adding people by CSV had no route at all: every
        // import read required site MANAGE, which an organisation
        // administrator deliberately does not have.
        \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationImportRoutes::register(
            $kernel,
            $this->organisationImports,
            $idempotent,
            $this->ports->clock,
        );
        // The identity surface: connections, the links they produce, domain
        // claims and join rules. Free, and reachable on a stock build: the
        // host-group dialect is registered, so an organisation can claim a
        // local issuer and write mapping rules on day one without any paid
        // package installed.
        \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationIdentityRoutes::register(
            $kernel,
            $this->organisationConnections,
            $this->organisationJoining,
        );
        if ($this->organisationInvitations !== null) {
            \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationInvitationRoutes::register(
                $kernel,
                $this->organisationInvitations,
                $this->organisationAccess,
                $idempotent,
                $this->ports->database,
            );
        }

        $kernel->register(new Route('GET', '/courses', new GetCatalogueHandler($this->getCatalogue)));
        $kernel->register(new Route(
            'GET',
            '/courses/{slug}',
            new GetCourseHandler(
                $this->getCourseBySlug,
                $this->registries->commerceSources,
                $this->ports->translator,
                new CommercePrices($this->ports->logger),
            ),
        ));
        $kernel->register(new Route(
            'GET',
            '/courses/{id}/curriculum',
            new GetCurriculumHandler($this->getCurriculum, $this->listMyEnrolments, $this->getMyCourseProgress),
        ));
        $kernel->register(new Route(
            'GET',
            '/lessons/{id}',
            new GetLessonHandler($this->getLessonForLearner, $this->ports->storage),
        ));
        $kernel->register(new Route('POST', '/enrolments', new SelfEnrolHandler($this->selfEnrol, $idempotent)));
        $kernel->register(new Route('GET', '/me/enrolments', new ListMyEnrolmentsHandler($this->listMyEnrolments)));
        $kernel->register(new Route(
            'GET',
            '/me/progress',
            new GetMyProgressHandler($this->listMyEnrolments, $this->getMyCourseProgress),
        ));
        $kernel->register(new Route('GET', '/me/certificates', new ListMyCertificatesHandler(
            $this->listMyCertificates,
            $this->ports->clock,
            $this->credentialBaseUrl,
            $this->certificateCreditRepository,
        )));
        // The learner's own copy, as a signed expiring URL: private files go
        // through the storage port and the only URL the core ever emits for
        // one is signed and expiring.
        $kernel->register(new Route(
            'GET',
            '/me/certificates/{id}/download',
            new DownloadMyCertificateHandler(
                $this->certificateRepository,
                $this->ports->storage,
                $this->guard,
                $this->ports->clock,
            ),
        ));
        $kernel->register(new Route(
            'GET',
            '/me/credit-identities',
            new CreditIdentitiesHandler($this->creditSchemeIdentities, $this->registries->creditSchemes, $idempotent),
        ));
        $kernel->register(new Route(
            'PUT',
            '/me/credit-identities',
            new CreditIdentitiesHandler(
                $this->creditSchemeIdentities,
                $this->registries->creditSchemes,
                $idempotent,
                true,
            ),
        ));
        $kernel->register(new Route(
            'POST',
            '/lessons/{id}/progress',
            new RecordLessonProgressHandler($this->recordLessonProgress, $idempotent),
        ));
        $kernel->register(new Route(
            'POST',
            '/quizzes/{id}/attempts',
            new StartAttemptHandler($this->startAttempt, $this->quizRepository, $idempotent, $this->getAttemptForLearner),
        ));
        $kernel->register(new Route('GET', '/attempts/{id}', new GetAttemptHandler($this->getAttemptForLearner)));
        $kernel->register(new Route('PUT', '/attempts/{id}/answers', new SaveAnswersHandler($this->saveAnswers, $idempotent, $this->getAttemptForLearner)));
        $kernel->register(new Route(
            'POST',
            '/attempts/{id}/submit',
            new SubmitAttemptHandler($this->submitAttempt, $this->getAttemptForLearner, $idempotent),
        ));
        $kernel->register(new Route(
            'POST',
            '/assignments/{id}/submissions',
            new SubmitAssignmentHandler($this->submitAssignment, $idempotent),
        ));
        $kernel->register(new Route(
            'GET',
            '/assignments/{id}/submissions',
            new \Shondalai\VidyaLMS\Core\Http\Handler\GetMyAssignmentHandler($this->getMyAssignment),
        ));
        $kernel->register(new Route(
            'POST',
            '/submissions/{id}/grade',
            new GradeSubmissionHandler($this->gradeSubmission, $this->gradeOutcomeReader, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/certificates/{code}/verify',
            new VerifyCertificateHandler($this->verifyCertificate, $this->settings, $this->issuerIdentity),
        ));
        foreach (['GET', 'PUT'] as $method) {
            $kernel->register(new Route(
                $method,
                '/me/credentials/publication',
                new \Shondalai\VidyaLMS\Core\Http\Handler\CredentialPublicationHandler($this->settings, $this->guard),
            ));
        }
        // The two machine-readable credential routes. Both are behind the
        // rate limiter and the noindex header by prefix, and both exist in
        // the free build even though it signs nothing and publishes no status
        // list, because a credential issued today carries these URLs inside
        // it and they have to keep meaning the same thing afterwards.
        $kernel->register(new Route(
            'GET',
            '/credentials/{uuid}',
            new GetCredentialHandler($this->getCredential),
        ));
        $kernel->register(new Route(
            'GET',
            '/credentials/status/{listId}',
            new GetCredentialStatusListHandler(
                $this->credentialStatusRepository,
                $this->ports->clock,
                $this->statusListBaseUrl,
                $this->credentialAssembly,
            ),
        ));
        $kernel->register(new Route(
            'POST',
            '/import',
            new StartImportHandler($this->queueImportJob, $this->runImportJob, $idempotent),
        ));
        $kernel->register(new Route('GET', '/import', new ListImportJobsHandler($this->listImportJobs, $this->ports->clock)));
        // Before GET /import/{id}, because route matching is first
        // registration wins with no precedence of a literal over a parameter:
        // the other order serves this path to the job handler as job id
        // "importers".
        $kernel->register(new Route(
            'GET',
            '/import/importers',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ListImportersHandler($this->listImporters),
        ));
        $operations = new \Shondalai\VidyaLMS\Core\Application\Platform\InspectOperations(
            $this->ports->database,
            $this->ports->scheduler,
            $this->ports->clock,
            $this->guard,
        );
        foreach (['' => 'overview', '/jobs' => 'jobs', '/jobs/{id}' => 'job'] as $suffix => $view) {
            $kernel->register(new Route(
                'GET',
                '/manage/operations' . $suffix,
                new \Shondalai\VidyaLMS\Core\Http\Handler\OperationsHandler($operations, $view)
            ));
        }
        // ── The report routes, and their order is load bearing ───────────
        //
        // Route matching is first-registration-wins with no literal-over-
        // parameter precedence, so /manage/reports/overview is registered
        // strictly BEFORE /manage/reports/{key}. Swap these two and every
        // overview request reaches the registry with the key "overview", which
        // nothing registers, and answers the miss shape instead of the report
        // the operations screen draws itself from.
        //
        // The alias is kept rather than deprecated: the contract has published
        // its response shape since before the registry route existed and the
        // generated SDK's operation is what the screen calls.
        $kernel->register(new Route(
            'GET',
            '/manage/reports/overview',
            new \Shondalai\VidyaLMS\Core\Http\Handler\SiteLearningReportHandler(
                new \Shondalai\VidyaLMS\Core\Application\Platform\GetSiteLearningReport(
                    $this->registries->reports,
                    $this->guard,
                ),
            )
        ));
        // The 202 export pair. POST is registered before the parameterised GET
        // for tidiness only: the methods differ, so neither can shadow the
        // other.
        $kernel->register(new Route(
            'POST',
            '/manage/reports/{key}/exports',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportExportHandler(
                $this->startReportExport,
                $this->getOrganisationReport,
                $idempotent,
            ),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/report-runs/{id}',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportRunHandler($this->getReportRun),
        ));
        // The gradebook's three reads and its one write. They are registered
        // beside the report routes rather than with the course authoring ones
        // because the gradebook is a reporting surface: it answers what a
        // course is marked out of, where /manage/courses/{id}/gradebook answers
        // what is waiting for a human.
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/gradebook/items',
            new \Shondalai\VidyaLMS\Core\Http\Handler\GradebookHandler(
                $this->readGradebook,
                \Shondalai\VidyaLMS\Core\Http\Handler\GradebookHandler::MODE_ITEMS,
            ),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/gradebook/grades',
            new \Shondalai\VidyaLMS\Core\Http\Handler\GradebookHandler(
                $this->readGradebook,
                \Shondalai\VidyaLMS\Core\Http\Handler\GradebookHandler::MODE_CELLS,
            ),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/gradebook/changes',
            new \Shondalai\VidyaLMS\Core\Http\Handler\GradebookHandler(
                $this->readGradebook,
                \Shondalai\VidyaLMS\Core\Http\Handler\GradebookHandler::MODE_CHANGES,
            ),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/gradebook/grades/{id}/regrade',
            new \Shondalai\VidyaLMS\Core\Http\Handler\RegradeGradeCellHandler(
                $this->regradeGradeCell,
                $idempotent,
                $this->ports->database,
            ),
        ));
        // The saved schedules. The runner closure is what keeps the task and
        // the route one implementation: pressing Run and the clock firing walk
        // the same code, so a schedule that works at 03:00 works when somebody
        // tests it and the other way round.
        $runSchedule = function (
            \Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule $schedule
        ): array {
            $task = $this->registries->scheduledTasks->get(
                \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ScheduleReportsTask::KEY,
            );

            return $task instanceof \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ScheduleReportsTask
                ? $task->serveNow($schedule)
                : [];
        };
        $kernel->register(new Route(
            'GET',
            '/manage/report-schedules',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler(
                $this->manageReportSchedules,
                $idempotent,
                $runSchedule,
                $this->ports->database,
                \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler::MODE_LIST,
            ),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/report-schedules',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler(
                $this->manageReportSchedules,
                $idempotent,
                $runSchedule,
                $this->ports->database,
                \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler::MODE_CREATE,
            ),
        ));
        $kernel->register(new Route(
            'PATCH',
            '/manage/report-schedules/{id}',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler(
                $this->manageReportSchedules,
                $idempotent,
                $runSchedule,
                $this->ports->database,
                \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler::MODE_UPDATE,
            ),
        ));
        $kernel->register(new Route(
            'DELETE',
            '/manage/report-schedules/{id}',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler(
                $this->manageReportSchedules,
                $idempotent,
                $runSchedule,
                $this->ports->database,
                \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler::MODE_DELETE,
            ),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/report-schedules/{id}/run',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler(
                $this->manageReportSchedules,
                $idempotent,
                $runSchedule,
                $this->ports->database,
                \Shondalai\VidyaLMS\Core\Http\Handler\ReportScheduleHandler::MODE_RUN,
            ),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/learners/{user_id}/timeline',
            new \Shondalai\VidyaLMS\Core\Http\Handler\LearnerTimelineHandler($this->getLearnerTimeline),
        ));
        // The learner's own copy of the same read, at a personal address. The
        // manage route stays for a reports reader; this one binds no user_id
        // at all, so an app never has to build a /manage/ URL for its own user.
        $kernel->register(new Route(
            'GET',
            '/me/timeline',
            new \Shondalai\VidyaLMS\Core\Http\Handler\MyTimelineHandler($this->getLearnerTimeline),
        ));
        // The catalogue a manage screen renders its picker and its filter forms
        // from. Without it parameterSchema() had no caller anywhere and a
        // screen could not discover that a registered report existed.
        $kernel->register(new Route(
            'GET',
            '/manage/reports',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportCatalogueHandler($this->listReportDefinitions),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/reports/{key}',
            new \Shondalai\VidyaLMS\Core\Http\Handler\SiteReportHandler($this->getSiteReport),
        ));
        // The organisation catalogue. ListReportDefinitions has always taken
        // the flag and filtered organisation-scoped providers out of the site
        // list, and nothing ever served the other half: every organisation
        // report was absent from GET /manage/reports and present only in a
        // call nobody made, so a registry-driven picker could not discover one
        // and a screen could only offer them by naming them.
        //
        // It is registered before /manage/organisations/{id}/reports/{key},
        // for tidiness only: the paths differ in segment count, so neither can
        // shadow the other.
        $kernel->register(new Route(
            'GET',
            '/manage/organisations/{id}/reports',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportCatalogueHandler(
                $this->listReportDefinitions,
                true,
                $this->organisationAccessPolicy,
            ),
        ));
        // The organisation in the path and never in a parameter, per the API
        // contract's rule for every organisation-scoped read.
        $kernel->register(new Route(
            'GET',
            '/manage/organisations/{id}/reports/{key}',
            new \Shondalai\VidyaLMS\Core\Http\Handler\OrganisationReportHandler($this->getOrganisationReport),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/organisations/{id}/reports/{key}/exports',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ReportExportHandler(
                $this->startReportExport,
                $this->getOrganisationReport,
                $idempotent,
                true,
            ),
        ));
        // Learning and access evidence can support a shop's dispute process.
        $kernel->register(new Route(
            'GET',
            '/manage/learners/{user_id}/courses/{course_id}/access-evidence',
            new \Shondalai\VidyaLMS\Core\Http\Handler\AccessEvidenceHandler(
                $this->collectAccessEvidence,
            ),
        ));
        $kernel->register(new Route('GET', '/import/{id}', new GetImportJobHandler($this->getImportJob, $this->ports->clock)));
        // The site console's half of the row report. A migration that belongs
        // to no organisation still produces row problems, and without these
        // two they would be written and never readable.
        $kernel->register(new Route(
            'GET',
            '/import/{id}/problems',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ImportRowProblemsHandler($this->importRowProblems),
        ));
        $kernel->register(new Route(
            'GET',
            '/import/{id}/failed-rows',
            new \Shondalai\VidyaLMS\Core\Http\Handler\ImportRowProblemsHandler($this->importRowProblems, true),
        ));

        // ── The management surface, free on every build ──────────────────
        $kernel->register(new Route('GET', '/lesson-types', new ListLessonTypesHandler($this->listProviderTypes, $this->presenters)));
        $kernel->register(new Route('GET', '/question-types', new ListQuestionTypesHandler(
            $this->listProviderTypes,
            $this->presenters,
            $this->registries->scoringPolicies,
        )));

        $kernel->register(new Route('GET', '/manage/courses', new ListManagedCoursesHandler($this->listManagedCourses, $this->presenters)));
        $kernel->register(new Route(
            'GET',
            '/manage/course-categories',
            new ListCourseCategoriesHandler($this->listCourseCategories, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/course-categories/{id}',
            new GetCourseCategoryHandler($this->getCourseCategory, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/courses',
            new CreateCourseHandler($this->createCourse, $this->getManagedCourse, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}',
            new GetManagedCourseHandler($this->getManagedCourse, $this->presenters),
        ));
        $kernel->register(new Route(
            'PATCH',
            '/manage/courses/{id}',
            new UpdateCourseHandler($this->updateCourse, $this->getManagedCourse, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/courses/{id}/publish',
            new PublishCourseHandler($this->publishCourse, $this->getManagedCourse, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/courses/{id}/archive',
            new ArchiveCourseHandler($this->archiveCourse, $this->getManagedCourse, $idempotent, $this->presenters),
        ));
        foreach (['unpublish', 'restore', 'trash'] as $action) {
            $kernel->register(new Route(
                $action === 'trash' ? 'DELETE' : 'POST',
                '/manage/courses/{id}' . ($action === 'trash' ? '' : '/' . $action),
                new ChangeCourseLifecycleHandler($this->changeCourseLifecycle, $idempotent, $action),
            ));
        }
        $kernel->register(new Route(
            'PUT',
            '/manage/courses/{id}/prerequisites',
            new SetPrerequisitesHandler($this->setPrerequisites, $this->courseRepository, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/instructors',
            new ListInstructorsHandler($this->listInstructors, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/courses/{id}/instructors',
            new AssignInstructorHandler($this->assignInstructor, $this->ports->users, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'DELETE',
            '/manage/courses/{id}/instructors/{user_id}',
            new RemoveInstructorHandler($this->removeInstructor, $idempotent),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/curriculum',
            new GetAuthoringCurriculumHandler($this->getAuthoringCurriculum, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/courses/{id}/modules',
            new AddModuleHandler($this->addModule, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'PUT',
            '/manage/courses/{id}/modules/order',
            new ReorderModulesHandler($this->reorderModules, $idempotent),
        ));
        $kernel->register(new Route(
            'PATCH',
            '/manage/modules/{id}',
            new UpdateModuleHandler($this->updateModule, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'DELETE',
            '/manage/modules/{id}',
            new ArchiveModuleHandler($this->archiveModule, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/modules/{id}/lessons',
            new AddLessonHandler($this->addAuthoredLesson, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'PUT',
            '/manage/modules/{id}/lessons/order',
            new ReorderLessonsHandler($this->reorderLessons, $idempotent),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/lessons/{id}',
            new GetAuthoringLessonHandler($this->getAuthoringLesson, $this->presenters),
        ));
        $kernel->register(new Route(
            'PATCH',
            '/manage/lessons/{id}',
            new UpdateLessonHandler($this->updateLesson, $this->getAuthoringLesson, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'DELETE',
            '/manage/lessons/{id}',
            new ArchiveLessonHandler($this->archiveLesson, $this->getAuthoringLesson, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/lessons/{id}/move',
            new MoveLessonHandler($this->moveLesson, $this->getAuthoringLesson, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'PUT',
            '/manage/lessons/{id}/assignment',
            new ConfigureAssignmentHandler($this->configureAssignment, $idempotent, $this->presenters),
        ));

        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/quizzes',
            new ListQuizzesHandler($this->listQuizzesForCourse, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/courses/{id}/quizzes',
            new CreateQuizHandler($this->createQuiz, $this->getQuizAuthoring, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/quizzes/{id}',
            new GetQuizAuthoringHandler($this->getQuizAuthoring, $this->presenters),
        ));
        $kernel->register(new Route(
            'PUT',
            '/manage/quizzes/{id}',
            new UpdateQuizHandler($this->updateQuiz, $this->getQuizAuthoring, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/quizzes/{id}/settings-schema',
            new GetQuizSettingsSchemaHandler($this->getQuizSettingsSchema),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/quizzes/{id}/regrade',
            new RegradeQuizHandler($this->regradeQuiz, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/attempts/{id}/regrade',
            new RegradeAttemptHandler($this->regradeAttempt, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/attempts/{id}/integrity-events',
            new RecordIntegrityEventHandler($this->recordIntegrityEvent, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/attempts/{id}/integrity-events',
            new ListIntegrityEventsHandler($this->listIntegrityEvents, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/attempts/{id}/xapi',
            new GetAttemptXapiHandler($this->buildXapiStatements),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/reports/item-analysis',
            new GetItemAnalysisHandler($this->getItemAnalysisReport),
        ));
        // Every other registered report, by its own key, so a second report
        // is a registration rather than a core route, a core handler and a
        // contract operation. Item analysis keeps its named route because
        // the contract already declares its response shape; it is one caller
        // of the registry rather than the only way in.
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/reports/{key}',
            new GetCourseReportHandler($this->getCourseReport),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/stimuli',
            new ListStimuliHandler($this->listStimuli, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/courses/{id}/stimuli',
            new CreateStimulusHandler($this->createStimulus, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/stimuli/{id}',
            new GetStimulusHandler($this->getStimulus, $this->presenters),
        ));
        $kernel->register(new Route(
            'PUT',
            '/manage/stimuli/{id}',
            new UpdateStimulusHandler($this->updateStimulus, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'DELETE',
            '/manage/stimuli/{id}',
            new RemoveStimulusHandler($this->removeStimulus, $idempotent),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/quizzes/{id}/questions',
            new AddQuestionHandler($this->addQuestion, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'PUT',
            '/manage/quizzes/{id}/questions/order',
            new ReorderQuestionsHandler($this->reorderQuestions, $idempotent),
        ));
        $kernel->register(new Route(
            'PUT',
            '/manage/questions/{id}',
            new UpdateQuestionHandler($this->updateQuestion, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'DELETE',
            '/manage/questions/{id}',
            new RemoveQuestionHandler($this->removeQuestion, $idempotent),
        ));

        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/gradebook',
            new GetGradebookHandler($this->getGradebook, $this->ports->storage, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/attempts/{id}/answers/{question_id}/grade',
            new GradeAnswerHandler(
                $this->gradeAnswerManually,
                $this->manualGradingStatus,
                $this->gradeOutcomeReader,
                $idempotent,
                $this->presenters,
            ),
        ));

        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/learners',
            new ListCourseLearnersHandler($this->listCourseLearners, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/courses/{id}/learners',
            new EnrolLearnerHandler($this->enrolLearnerManually, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/learners/{user_id}',
            new GetLearnerCourseProgressHandler($this->getLearnerCourseProgress, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/entitlements/{id}/revoke',
            new RevokeEntitlementHandler($this->revokeEntitlement, $this->accessGrantView, $idempotent, $this->presenters),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/entitlements/{id}/extend',
            new ExtendEntitlementHandler($this->extendEntitlement, $idempotent, $this->presenters),
        ));

        $kernel->register(new Route('GET', '/admin/dashboard', new GetAdminDashboardHandler($this->siteOverview, $this->presenters)));
        $kernel->register(new Route(
            'GET',
            '/admin/enrolments',
            new ListAdminEnrolmentsHandler($this->listSiteEnrolments, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/admin/learners',
            new ListAdminLearnersHandler($this->listLearners, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/admin/learners/{user_id}',
            new GetLearnerRecordHandler($this->getLearnerRecord, $this->verificationBaseUrl, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/admin/certificates',
            new ListCertificatesHandler($this->listCertificates, $this->verificationBaseUrl, $this->presenters),
        ));
        $kernel->register(new Route(
            'GET',
            '/admin/certificates/{id}',
            new GetCertificateHandler(
                $this->certificateRepository,
                $this->certificateRecords,
                $this->guard,
                $this->ports->clock,
                $this->verificationBaseUrl,
                $this->presenters,
                $this->certificateCreditRepository,
                $this->credentialStatusRepository,
                $this->statusListBaseUrl,
            ),
        ));
        $kernel->register(new Route(
            'POST',
            '/admin/certificates/{id}/revoke',
            new RevokeCertificateHandler(
                $this->revokeCertificate,
                $this->certificateRecords,
                $this->ports->clock,
                $idempotent,
                $this->verificationBaseUrl,
                $this->presenters,
            ),
        ));
        // Suspend and reinstate beside revoke, because a reversible hold is a
        // different decision from a permanent withdrawal and answering
        // "revoked" to a payment dispute is a defamatory answer to a
        // temporary question.
        $kernel->register(new Route(
            'POST',
            '/admin/certificates/{id}/suspend',
            new SuspendCertificateHandler(
                $this->suspendCertificate,
                $this->certificateRecords,
                $this->ports->clock,
                $idempotent,
                $this->verificationBaseUrl,
                $this->presenters,
                true,
            ),
        ));
        $kernel->register(new Route(
            'POST',
            '/admin/certificates/{id}/reinstate',
            new SuspendCertificateHandler(
                $this->suspendCertificate,
                $this->certificateRecords,
                $this->ports->clock,
                $idempotent,
                $this->verificationBaseUrl,
                $this->presenters,
                false,
            ),
        ));
        $kernel->register(new Route(
            'POST',
            '/admin/certificates/{id}/reissue',
            new ReissueCertificateHandler(
                $this->reissueCertificate,
                $this->certificateRecords,
                $this->ports->clock,
                $idempotent,
                $this->verificationBaseUrl,
                $this->presenters,
                $this->certificateCreditRepository,
            ),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/certificate-templates/{id}/reissue',
            new ReissueTemplateHandler($this->reissueCertificatesForTemplate, $idempotent, $this->ports->database, $this->guard),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/certificate-templates/{id}/reissue',
            new \Shondalai\VidyaLMS\Core\Http\Handler\CertificateReissueStatusHandler(
                new \Shondalai\VidyaLMS\Core\Application\Credentials\GetCertificateReissueStatus($this->ports->scheduler, $this->guard),
            ),
        ));
        $kernel->register(new Route(
            'DELETE',
            '/manage/certificate-templates/{id}/reissue',
            new ReissueTemplateHandler($this->reissueCertificatesForTemplate, $idempotent, $this->ports->database, $this->guard, true),
        ));
        // The certificate template authoring surface, three levels of one
        // aggregate. The template level and the page level are addressed
        // under their own paths rather than through one fat PUT, because a
        // designer saves one moved element far more often than it saves a
        // whole template and a single write per drag would make every drag a
        // full-aggregate write.
        $templateWrite = new \Shondalai\VidyaLMS\Core\Http\Handler\CertificateTemplateWrite(
            $idempotent,
            $this->ports->database,
            $this->guard,
        );
        $templates = new CertificateTemplatesHandler(
            $this->listCertificateTemplates,
            $this->createCertificateTemplate,
            $this->presenters,
            $templateWrite,
        );
        foreach (
            [
            ['GET', '/manage/certificate-blueprints', 'catalogue'],
            ['POST', '/manage/certificate-blueprints/{key}/clone', 'blueprint'],
            ['POST', '/manage/certificate-templates/{id}/publish', 'publish'],
            ['POST', '/manage/certificate-templates/{id}/default', 'default'],
            ['POST', '/manage/certificate-templates/{id}/clone', 'clone'],
            ] as [$method, $path, $operation]
        ) {
            $kernel->register(new Route(
                $method,
                $path,
                new \Shondalai\VidyaLMS\Core\Http\Handler\CertificateStudioHandler(
                    $this->certificateStudio,
                    $templateWrite,
                    $this->presenters,
                    $operation,
                )
            ));
        }
        foreach (['GET', 'PUT'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/courses/{id}/certificate-policy',
                new \Shondalai\VidyaLMS\Core\Http\Handler\CourseCertificatePolicyHandler(
                    $this->courseCertificatePolicy,
                    $idempotent,
                    $this->ports->database,
                )
            ));
        }
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/certificate-templates',
            new \Shondalai\VidyaLMS\Core\Http\Handler\CourseCertificatePolicyHandler(
                $this->courseCertificatePolicy,
                $idempotent,
                $this->ports->database,
                true,
            )
        ));
        $kernel->register(new Route('GET', '/manage/certificate-templates', $templates));
        $kernel->register(new Route('POST', '/manage/certificate-templates', $templates));

        $template = new CertificateTemplateHandler(
            $this->getCertificateTemplate,
            $this->updateCertificateTemplate,
            $this->archiveCertificateTemplate,
            $this->presenters,
            $templateWrite,
        );
        $kernel->register(new Route('GET', '/manage/certificate-templates/{id}', $template));
        $kernel->register(new Route('PATCH', '/manage/certificate-templates/{id}', $template));
        $kernel->register(new Route('DELETE', '/manage/certificate-templates/{id}', $template));

        $templatePages = new CertificateTemplatePagesHandler(
            $this->listCertificateTemplatePages,
            $this->addCertificateTemplatePage,
            $this->reorderCertificateTemplatePages,
            $this->presenters,
            $templateWrite,
        );
        $kernel->register(new Route('GET', '/manage/certificate-templates/{id}/pages', $templatePages));
        $kernel->register(new Route('POST', '/manage/certificate-templates/{id}/pages', $templatePages));
        $kernel->register(new Route('PUT', '/manage/certificate-templates/{id}/pages/order', $templatePages));

        $templatePage = new CertificateTemplatePageHandler(
            $this->updateCertificateTemplatePage,
            $this->deleteCertificateTemplatePage,
            $this->presenters,
            $templateWrite,
        );
        $kernel->register(new Route('PATCH', '/manage/certificate-template-pages/{id}', $templatePage));
        $kernel->register(new Route('DELETE', '/manage/certificate-template-pages/{id}', $templatePage));

        $templateElements = new CertificateTemplateElementsHandler(
            $this->listCertificateTemplateElements,
            $this->addCertificateTemplateElement,
            $this->reorderCertificateTemplateElements,
            $this->presenters,
            $templateWrite,
        );
        $kernel->register(new Route('GET', '/manage/certificate-template-pages/{id}/elements', $templateElements));
        $kernel->register(new Route('POST', '/manage/certificate-template-pages/{id}/elements', $templateElements));
        $kernel->register(new Route(
            'PUT',
            '/manage/certificate-template-pages/{id}/elements/order',
            $templateElements,
        ));

        $templateElement = new CertificateTemplateElementHandler(
            $this->updateCertificateTemplateElement,
            $this->deleteCertificateTemplateElement,
            $this->presenters,
            $templateWrite,
        );
        $kernel->register(new Route('PATCH', '/manage/certificate-template-elements/{id}', $templateElement));
        $kernel->register(new Route('DELETE', '/manage/certificate-template-elements/{id}', $templateElement));

        $kernel->register(new Route(
            'POST',
            '/manage/certificate-templates/{id}/preview',
            new PreviewCertificateTemplateHandler($this->previewCertificateTemplate, $this->presenters),
        ));

        // The element palette, enumerated from the registry rather than from
        // a constant: a third-party element appears here the day it
        // registers, with core files edited zero.
        $kernel->register(new Route(
            'GET',
            '/manage/certificate-templates/{id}/element-types',
            new ListCertificateElementTypesHandler(
                $this->registries->certificateElements,
                $this->certificateTemplateRepository,
                $this->certificateTokens,
                $this->courseCreditRepository,
                $this->guard,
                $this->ports->translator,
            ),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/credit-schemes',
            new ListCreditSchemesHandler($this->registries->creditSchemes, $this->guard),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/courses/{id}/credits',
            new CourseCreditsHandler(
                $this->courseCreditRepository,
                $this->registries->creditSchemes,
                $this->guard,
                $idempotent,
            ),
        ));
        $kernel->register(new Route(
            'PUT',
            '/manage/courses/{id}/credits',
            new CourseCreditsHandler(
                $this->courseCreditRepository,
                $this->registries->creditSchemes,
                $this->guard,
                $idempotent,
                true,
            ),
        ));
        $platformSettings = new GetPlatformSettingsHandler($this->getPlatformSettings, $this->presenters);
        $kernel->register(new Route('GET', '/admin/settings', $platformSettings));
        // The one writable record on the screen. See the handler for why the
        // read-only rule has an exception here and nowhere else.
        $kernel->register(new Route('PUT', '/admin/settings', $platformSettings));
        $kernel->register(new Route('GET', '/admin/users', new SearchUsersHandler($this->searchUsers, $this->presenters)));

        // ── Availability, free on every build ────────────────────────────
        //
        // The rule route is one templated path rather than five concrete
        // ones, which follows the report route's precedent and makes a sixth
        // gated subject type a handler change rather than a contract change.
        $availabilityRules = new AvailabilityRuleHandler($this->manageAvailabilityRule, $idempotent);
        $availabilityGrants = new AvailabilityGrantsHandler($this->manageAvailabilityGrants, $idempotent);

        $kernel->register(new Route(
            'GET',
            '/manage/availability/conditions',
            new ListAvailabilityConditionsHandler($this->availabilityCatalogue),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/availability/preview',
            new PreviewAvailabilityHandler($this->previewAvailabilityRule, $idempotent),
        ));
        $kernel->register(new Route(
            'DELETE',
            '/manage/availability/grants/{id}',
            new RevokeAvailabilityGrantHandler($this->manageAvailabilityGrants, $idempotent),
        ));
        $kernel->register(new Route('GET', '/manage/courses/{id}/availability/grants', $availabilityGrants));
        $kernel->register(new Route('POST', '/manage/courses/{id}/availability/grants', $availabilityGrants));

        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/{subject_type}/{subject_id}/availability',
                $availabilityRules,
            ));
        }

        // ── Notifications, free on every build ───────────────────────────
        $studio = fn (string $resource): NotificationTemplateStudioHandler => new NotificationTemplateStudioHandler(
            $resource,
            $this->notificationTemplateStudio,
            $idempotent,
            $this->notificationTemplateTestDelivery,
        );
        $templateIdentity = '/manage/notifications/templates/{template_key}/{locale}/{channel_key}';
        $kernel->register(new Route('GET', $templateIdentity, $studio(NotificationTemplateStudioHandler::DETAIL)));
        $kernel->register(new Route('PUT', $templateIdentity . '/draft', $studio(NotificationTemplateStudioHandler::DRAFT)));
        $kernel->register(new Route('DELETE', $templateIdentity . '/draft', $studio(NotificationTemplateStudioHandler::DRAFT)));
        $kernel->register(new Route('POST', $templateIdentity . '/publish', $studio(NotificationTemplateStudioHandler::PUBLISH)));
        $kernel->register(new Route('POST', $templateIdentity . '/preview', $studio(NotificationTemplateStudioHandler::PREVIEW)));
        $kernel->register(new Route('POST', $templateIdentity . '/test-send', $studio(NotificationTemplateStudioHandler::TEST_SEND)));
        $admin = fn (string $resource): NotificationAdminHandler => new NotificationAdminHandler(
            $resource,
            $this->notificationAdmin,
            $this->notificationCatalogue,
            $idempotent,
        );

        $kernel->register(new Route(
            'GET',
            '/manage/notifications/catalogue',
            $admin(NotificationAdminHandler::RESOURCE_CATALOGUE),
        ));

        foreach (['GET', 'POST'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/notifications/rules',
                $admin(NotificationAdminHandler::RESOURCE_RULES),
            ));
        }

        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/notifications/rules/{id}',
                $admin(NotificationAdminHandler::RESOURCE_RULE),
            ));
        }

        $kernel->register(new Route('GET', '/manage/notifications/queue', $admin(NotificationAdminHandler::RESOURCE_QUEUE)));
        $kernel->register(new Route(
            'POST',
            '/manage/notifications/queue/{id}/cancel',
            $admin(NotificationAdminHandler::RESOURCE_QUEUE_CANCEL),
        ));

        $kernel->register(new Route(
            'GET',
            '/manage/notifications/templates',
            $studio(NotificationTemplateStudioHandler::LIST),
        ));
        foreach (['GET', 'PUT'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/notifications/policies',
                $admin(NotificationAdminHandler::RESOURCE_POLICIES),
            ));
        }

        foreach (['GET', 'POST'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/notifications/suppressions',
                $admin(NotificationAdminHandler::RESOURCE_SUPPRESSIONS),
            ));
        }

        $kernel->register(new Route(
            'GET',
            '/manage/notifications/consents',
            $admin(NotificationAdminHandler::RESOURCE_CONSENTS),
        ));

        // ── Automations, free on every build ────────────────────────────
        // The rule builder's own surface. It is separate from the
        // notification routes above and from the notifications host screen,
        // because an automation is a rule over one action registry rather
        // than a message: sharing either would make one screen two products.
        $automation = fn (string $resource): \Shondalai\VidyaLMS\Core\Http\Handler\Automation\AutomationRulesHandler =>
            new \Shondalai\VidyaLMS\Core\Http\Handler\Automation\AutomationRulesHandler(
                $resource,
                $this->automationRuleAdmin,
                $this->automationCatalogue,
                $this->automationRulePreview,
                $this->automationRuleTesting,
                $this->automationRunLog,
                $idempotent,
                $this->ports->database,
                $this->releaseAutomationStep,
            );
        $automationHandler = \Shondalai\VidyaLMS\Core\Http\Handler\Automation\AutomationRulesHandler::class;

        $kernel->register(new Route(
            'GET',
            '/manage/automations/catalogue',
            $automation($automationHandler::RESOURCE_CATALOGUE),
        ));

        foreach (['GET', 'POST'] as $method) {
            $kernel->register(new Route($method, '/manage/automation-rules', $automation($automationHandler::RESOURCE_RULES)));
        }

        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/automation-rules/{id}',
                $automation($automationHandler::RESOURCE_RULE),
            ));
        }

        $kernel->register(new Route(
            'POST',
            '/manage/automation-rules/{id}/preview',
            $automation($automationHandler::RESOURCE_PREVIEW),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/automation-rules/{id}/arm',
            $automation($automationHandler::RESOURCE_ARM),
        ));
        $kernel->register(new Route(
            'POST',
            '/manage/automation-rules/{id}/disarm',
            $automation($automationHandler::RESOURCE_DISARM),
        ));

        foreach (['GET', 'POST'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/automation-rules/{id}/tests',
                $automation($automationHandler::RESOURCE_TESTS),
            ));
        }

        $kernel->register(new Route(
            'POST',
            '/manage/automation-rules/{id}/tests/{test_id}/run',
            $automation($automationHandler::RESOURCE_TEST_RUN),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/automation-rules/{id}/runs',
            $automation($automationHandler::RESOURCE_RUNS),
        ));
        $kernel->register(new Route(
            'GET',
            '/manage/automation-rules/{id}/runs/{run_id}/actions',
            $automation($automationHandler::RESOURCE_RUN_ACTIONS),
        ));
        // The exit from awaiting_approval and parked. Without it a step gated
        // before its effect waited for ever: due() read only pending, the
        // lease reclaim walked only claimed and executing, and the sole
        // transition out was cancelling the whole rule.
        $kernel->register(new Route(
            'POST',
            '/manage/automation-rules/{id}/runs/{run_id}/actions/{step_index}/release',
            $automation($automationHandler::RESOURCE_RUN_ACTION_RELEASE),
        ));

        // ── AI, free on every build ─────────────────────────────────────
        // The whole surface is free. A site that never buys Pro keeps the
        // drafts, the provenance, the dry run, the stop control, the policies,
        // the redaction and the learner's right to know; what it has none of is
        // an enabled route, so every task refuses `not_configured` rather than
        // the routes answering 404.
        $ai = fn (string $resource): \Shondalai\VidyaLMS\Core\Http\Handler\Ai\AiHandler =>
            new \Shondalai\VidyaLMS\Core\Http\Handler\Ai\AiHandler(
                $resource,
                $this->aiPlacementCatalogue,
                $this->aiPolicyCentre,
                $this->aiDraftReview,
                $this->aiRunLog,
                $this->myAiActivity,
                $idempotent,
                $this->ports->database,
            );
        $aiHandler = \Shondalai\VidyaLMS\Core\Http\Handler\Ai\AiHandler::class;

        // The four entry points that reach the pipeline. They are their own
        // handler because everything else on this surface answers from rows,
        // and the estimate deliberately takes no idempotency key: it writes a
        // previewed run, spends nothing and calls no bridge.
        $aiEntry = fn (string $resource): \Shondalai\VidyaLMS\Core\Http\Handler\Ai\AiRunEntryHandler =>
            new \Shondalai\VidyaLMS\Core\Http\Handler\Ai\AiRunEntryHandler(
                $resource,
                $this->estimateAiRun,
                $this->startAiRun,
                $this->queueAiJob,
                $this->acceptAiDraft,
                $this->aiDraftReview,
                $this->aiReads,
                $idempotent,
                $this->ports->database,
                $this->organisationAccess,
            );
        $aiEntryHandler = \Shondalai\VidyaLMS\Core\Http\Handler\Ai\AiRunEntryHandler::class;

        $kernel->register(new Route('POST', '/ai/estimate', $aiEntry($aiEntryHandler::RESOURCE_ESTIMATE)));
        $kernel->register(new Route('POST', '/ai/runs', $aiEntry($aiEntryHandler::RESOURCE_RUN)));
        $kernel->register(new Route('POST', '/ai/jobs', $aiEntry($aiEntryHandler::RESOURCE_JOB)));
        // The only writer of an artefact in the whole design, and only a human
        // principal reaches it.
        $kernel->register(new Route(
            'POST',
            '/ai/drafts/{id}/accept',
            $aiEntry($aiEntryHandler::RESOURCE_ACCEPT),
        ));
        $kernel->register(new Route('GET', '/ai/placements', $ai($aiHandler::RESOURCE_PLACEMENTS)));
        $kernel->register(new Route('GET', '/ai/policy', $ai($aiHandler::RESOURCE_POLICY)));
        $kernel->register(new Route('PUT', '/ai/policy/acceptance', $ai($aiHandler::RESOURCE_POLICY_ACCEPTANCE)));
        $kernel->register(new Route('GET', '/ai/runs/{id}', $ai($aiHandler::RESOURCE_RUN)));
        $kernel->register(new Route('GET', '/ai/jobs', $ai($aiHandler::RESOURCE_JOBS)));
        $kernel->register(new Route('GET', '/ai/jobs/{id}', $ai($aiHandler::RESOURCE_JOB)));
        $kernel->register(new Route('GET', '/ai/drafts', $ai($aiHandler::RESOURCE_DRAFTS)));

        foreach (['GET', 'PATCH'] as $method) {
            $kernel->register(new Route($method, '/ai/drafts/{id}', $ai($aiHandler::RESOURCE_DRAFT)));
        }

        $kernel->register(new Route('POST', '/ai/drafts/{id}/reject', $ai($aiHandler::RESOURCE_DRAFT_REJECT)));
        $kernel->register(new Route('POST', '/ai/drafts/{id}/discard', $ai($aiHandler::RESOURCE_DRAFT_DISCARD)));
        $kernel->register(new Route(
            'GET',
            '/ai/provenance/{subject_type}/{id}',
            $ai($aiHandler::RESOURCE_PROVENANCE),
        ));
        // The learner's right to know. It reads the run subject rows and never
        // a draft payload, which is what keeps a draft unreachable by any
        // learner read path.
        $kernel->register(new Route('GET', '/me/ai-activity', $ai($aiHandler::RESOURCE_MY_ACTIVITY)));

        $manageAi = fn (string $resource): \Shondalai\VidyaLMS\Core\Http\Handler\Ai\ManageAiHandler =>
            new \Shondalai\VidyaLMS\Core\Http\Handler\Ai\ManageAiHandler(
                $resource,
                $this->aiStopControl,
                $this->aiRouteAdmin,
                $this->aiPolicyAdmin,
                $this->aiUsageReport,
                $this->aiRunLog,
                $this->recallAiRun,
                $idempotent,
                $this->ports->database,
            );
        $manageAiHandler = \Shondalai\VidyaLMS\Core\Http\Handler\Ai\ManageAiHandler::class;

        foreach (['GET', 'PUT'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/ai/enablement',
                $manageAi($manageAiHandler::RESOURCE_ENABLEMENT),
            ));
        }

        // The Article 14 stop. Set and cleared through the same row, so the
        // audit reads as one control rather than two switches.
        foreach (['POST', 'DELETE'] as $method) {
            $kernel->register(new Route($method, '/manage/ai/stop', $manageAi($manageAiHandler::RESOURCE_STOP)));
        }

        foreach (['GET', 'POST'] as $method) {
            $kernel->register(new Route($method, '/manage/ai/routes', $manageAi($manageAiHandler::RESOURCE_ROUTES)));
        }

        foreach (['PATCH', 'DELETE'] as $method) {
            $kernel->register(new Route($method, '/manage/ai/routes/{id}', $manageAi($manageAiHandler::RESOURCE_ROUTE)));
        }

        foreach (['GET', 'POST'] as $method) {
            $kernel->register(new Route(
                $method,
                '/manage/ai/policies',
                $manageAi($manageAiHandler::RESOURCE_POLICIES),
            ));
        }

        $kernel->register(new Route(
            'PATCH',
            '/manage/ai/policies/{version}',
            $manageAi($manageAiHandler::RESOURCE_POLICY),
        ));
        $kernel->register(new Route('GET', '/manage/ai/usage', $manageAi($manageAiHandler::RESOURCE_USAGE)));
        $kernel->register(new Route('GET', '/manage/ai/runs', $manageAi($manageAiHandler::RESOURCE_RUNS)));
        $kernel->register(new Route('GET', '/manage/ai/runs/{id}', $manageAi($manageAiHandler::RESOURCE_RUN)));
        $kernel->register(new Route(
            'POST',
            '/manage/ai/runs/{id}/recall',
            $manageAi($manageAiHandler::RESOURCE_RECALL),
        ));

        $providerSettings = new ProviderSettingsHandler($this->manageProviderSettings, $idempotent);
        $kernel->register(new Route('GET', '/manage/providers/{registry_key}', $providerSettings));
        $kernel->register(new Route(
            'PUT',
            '/manage/providers/{registry_key}/{provider_key}/settings',
            $providerSettings,
        ));

        // The learner and machine routes. The unsubscribe pair and the
        // feedback route answer with no session, which is why they are the
        // routes the rate limiter and the noindex header cover.
        $preferences = new MyNotificationPreferencesHandler($this->notificationPreferenceCentre, $idempotent);
        $kernel->register(new Route('GET', '/me/notification-preferences', $preferences));
        $kernel->register(new Route('PUT', '/me/notification-preferences', $preferences));

        $unsubscribe = new UnsubscribeHandler($this->unsubscribe);
        $kernel->register(new Route('GET', '/notifications/unsubscribe/{token}', $unsubscribe));
        $kernel->register(new Route('POST', '/notifications/unsubscribe/{token}', $unsubscribe));

        $kernel->register(new Route(
            'POST',
            '/notifications/feedback/{channelKey}',
            new DeliveryFeedbackHandler($this->recordDeliveryFeedback),
        ));

        // The four personal-page rules, published as a prefix list an adapter
        // reads rather than reimplements. Both hosts wrote the same four in
        // prose and in code for the AI activity view while the route itself
        // set none of them.
        $kernel->use(new \Shondalai\VidyaLMS\Core\Http\Middleware\PersonalPageMiddleware());
        $kernel->use(new NoIndexMiddleware(self::UNAUTHENTICATED_PREFIXES));
        $kernel->use(new RateLimitMiddleware(
            $this->ports->cache,
            $this->ports->clock,
            self::UNAUTHENTICATED_PREFIXES,
        ));

        // Bound authenticated probes before resolving tenancy or writing audit rows.
        $kernel->use(new RateLimitMiddleware(
            $this->ports->cache,
            $this->ports->clock,
            ['/manage/organisations'],
            limit: 120,
        ));

        // The tenancy pair resolves the active organisation and records reads
        // under it. Neither refuses anything. The path id validated
        // against the caller's own membership rows is still the authority on
        // every organisation route, and a middleware that refused as well
        // would be a second authorisation with a second error vocabulary.
        $kernel->use(new \Shondalai\VidyaLMS\Core\Http\Middleware\OrgScopeMiddleware(
            $this->activeOrganisation,
            $this->organisationAccess,
            $this->organisationStore,
            $this->settings,
            $this->ports->random,
        ));
        $kernel->use(new \Shondalai\VidyaLMS\Core\Http\Middleware\OrganisationAccessLogMiddleware(
            $this->activeOrganisation,
            $this->adminAccessLog,
            $this->ports->logger,
        ));

        // Contributions queued before the kernel existed land here, after the
        // core's own routes and under the same duplicate check.
        foreach ($this->contributedRoutes as $route) {
            $kernel->register($route);
        }

        $this->contributedRoutes = [];

        return $this->apiKernel = $kernel;
    }

    private function recordDefaultProviders(): void
    {
        $points = [
            'lesson_type' => $this->registries->lessonTypes->keys(),
            'question_type' => $this->registries->questionTypes->keys(),
            'scoring_policy' => $this->registries->scoringPolicies->keys(),
            'interaction_primitive' => $this->registries->interactionPrimitives->keys(),
            'attempt_policy' => $this->registries->attemptPolicies->keys(),
            'definition_upgrader' => $this->registries->definitionUpgraders->keys(),
            'item_mapper' => $this->registries->itemMappers->keys(),
            'question_preset' => $this->registries->questionPresets->keys(),
            // The three quiz-level seams. They are on the ledger for the
            // reason every other point is: a stored key that stops resolving
            // has to be able to name the package it came from, and the quiz
            // settings form is the screen that says so.
            'quiz_composition' => $this->registries->quizCompositions->keys(),
            'aggregation_method' => $this->registries->aggregationMethods->keys(),
            'negative_marking' => $this->registries->negativeMarking->keys(),
            'enrolment_rule' => $this->registries->enrolmentRules->keys(),
            'commerce_source' => $this->registries->commerceSources->keys(),
            'notification_channel' => $this->registries->notificationChannels->keys(),
            'report' => $this->registries->reports->keys(),
            'certificate_renderer' => $this->registries->certificateRenderers->keys(),
            'importer' => $this->registries->importers->keys(),
            // The two seams the free build fills and the ledger did not
            // know about. Pro already records its own publication checks and
            // integrity event kinds and subtracts the free build's from
            // them, so leaving these off meant a free key looked like a key
            // nobody owned: an admin removing Pro was told a check or a
            // signal kind had no package, and the removal placeholder had
            // nothing to name. Recorded under the same point names Pro uses,
            // because packageOf() answers per point and two spellings of one
            // point are two answers.
            'publication_check' => $this->registries->publicationChecks->keys(),
            'integrity_event' => $this->registries->integrityEventKinds->keys(),
            // The three credential seams, on the ledger for the sharpest
            // version of the same reason. An element key is stored on
            // certificate_template_elements and a format key on
            // certificates.credential_format, and the question "which
            // templates use the QR element" has to be answerable before a
            // package is removed rather than after, when the only evidence
            // left is a placeholder on somebody's certificate.
            'certificate_element' => $this->registries->certificateElements->keys(),
            'credential_format' => $this->registries->credentialFormats->keys(),
            'credit_scheme' => $this->registries->creditSchemes->keys(),
            'privacy_contributor' => $this->registries->privacyContributors->keys(),
            // The two reporting seams whose keys are stored, and only those
            // two. report_runs.format_key and report_runs.delivery_key are
            // real columns holding a provider key, so the question "which runs
            // were written by the package I am about to remove" has to be
            // answerable before the removal rather than after, when the only
            // evidence left is an artefact nothing can re-render.
            //
            // grade_aggregation and statement_forwarder are deliberately absent
            // and it is not an oversight: neither key is stored anywhere yet.
            // grade_categories.aggregation_key arrives with the gradebook and a
            // forwarder is chosen at fan-out and written down nowhere, so a
            // ledger row for either would be recording ownership of a key no
            // row references.
            'export_format' => $this->registries->exportFormats->keys(),
            'report_delivery' => $this->registries->reportDeliveries->keys(),
        ];

        // Guarded by a signature of the key set, so the forty-six upserts
        // happen when the set actually moves (an install, an upgrade, a
        // package attaching) and not before every learner request.
        $this->featureProviders->sync(self::PACKAGE, $points, $this->questionTypeConformance());
    }

    /**
     * What each registered question type declared about its accessibility
     * conformance, so the ledger can answer which installed types are
     * unverified. A type that declared nothing is flagged, because declaring
     * nothing is exactly the state the flag reports.
     *
     * @return array<string, array{0: ?string, 1: bool}>
     */
    private function questionTypeConformance(): array
    {
        $declared = [];

        foreach ($this->registries->questionTypes->keys() as $key) {
            $declared[QuestionTypes::EXTENSION_POINT . ':' . $key] = [
                $this->registries->questionTypes->conformanceResultOf($key),
                $this->registries->questionTypes->isFlagged($key),
            ];
        }

        return $declared;
    }

    /**
     * A sibling of the verification route under the same API base.
     *
     * The core knows no URLs and this does not pretend otherwise: it derives
     * one public route from another the host already supplied, so a site that
     * configures the verification base gets a dereferenceable credential id
     * without configuring a second one. A host that publishes credentials
     * elsewhere passes its own and this is never consulted.
     */
    private static function siblingRoute(string $verificationBaseUrl, string $segment): string
    {
        $trimmed = rtrim($verificationBaseUrl, '/');

        if ($trimmed === '') {
            return '';
        }

        $slash = strrpos($trimmed, '/');

        return $slash === false ? '' : substr($trimmed, 0, $slash + 1) . $segment;
    }

    /**
     * The scheme and host of an address the host already gave us, which is
     * the site's own address when the host supplied none of its own. It is a
     * derivation from another kernel option and never from the request, which
     * is the whole rule.
     */
    /**
     * A host-supplied map of route family to ISO-8601 duration, filtered to
     * the pairs that are actually two strings. A malformed duration is not
     * dropped here: DatabaseIdempotencyStore refuses it at construction, so a
     * typo fails the boot rather than the first webhook.
     *
     * @return array<string, string>
     */
    private static function durationMap(mixed $option): array
    {
        if (!\is_array($option)) {
            return [];
        }

        $map = [];

        /** @var mixed $ttl */
        foreach ($option as $family => $ttl) {
            if (\is_string($family) && $family !== '' && \is_string($ttl) && $ttl !== '') {
                $map[$family] = $ttl;
            }
        }

        return $map;
    }

    private static function originOf(string $url): string
    {
        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (!\is_string($scheme) || !\is_string($host) || $scheme === '' || $host === '') {
            return '';
        }

        $port = $parts['port'] ?? null;

        return $scheme . '://' . $host . (\is_int($port) ? ':' . $port : '');
    }

    private function subscribeListeners(
        LessonProgressRepository $lessonProgress,
        DatabaseActivityLogRepository $activityLog,
    ): void {
        $completeQuizLesson = new CompleteQuizLessonOnAttempt(
            $lessonProgress,
            $this->recomputeCourseProgress,
            $this->events,
            $this->ports->clock,
        );
        $this->events->subscribe(AttemptSubmitted::class, $completeQuizLesson);
        $this->events->subscribe(AttemptGraded::class, $completeQuizLesson);

        $completeAssignmentLesson = new CompleteAssignmentLessonOnGrade(
            $lessonProgress,
            $this->recomputeCourseProgress,
            $this->events,
            $this->ports->clock,
        );
        $this->events->subscribe(SubmissionGraded::class, $completeAssignmentLesson);

        // The assignment half of the gradebook, filled by a listener rather
        // than by an edit to the grading use case. The quiz half is written on
        // the progress recalculation's own path, because only that path knows
        // which attempts count and which quizzes a learner can still reach.
        $this->events->subscribe(
            SubmissionGraded::class,
            new \Shondalai\VidyaLMS\Core\Application\Grades\GradebookListener(
                $this->recordGrade,
                $this->ports->logger,
            ),
        );

        // The statement forwarder seam's free code path. The core still builds
        // statements and publishes them and holds no LRS, no endpoint, no
        // credential and no queue; this is the listener that hands what it
        // built to whatever registered to receive it, which is what makes the
        // free `log` provider a provider something calls rather than a
        // registration nothing reaches.
        $this->events->subscribe(
            \Shondalai\VidyaLMS\Core\Domain\Events\XapiStatementBuilt::class,
            new \Shondalai\VidyaLMS\Core\Application\Assessment\ForwardBuiltStatements(
                $this->registries->statementForwarders,
                $this->ports->logger,
            ),
        );

        // Issuance requires an active entitlement. Completion is terminal and
        // the event cannot re-fire, and expiry suspends an enrolment without
        // deleting progress, so a completion outlives the entitlement behind
        // it by design and the check has to be at the point of issue.
        $this->events->subscribe(
            CourseCompleted::class,
            new IssueCertificateOnCourseCompletion($this->issueCertificate, $this->entitlementRepository),
        );

        (new ActivityLogProjector($activityLog))->subscribe($this->events);
        (new SendTransactionalMail(
            $this->registries->notificationTriggers,
            $this->notificationEnqueue,
            $this->notificationJobs,
        ))->subscribe($this->events);
    }
}
