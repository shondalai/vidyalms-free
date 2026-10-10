<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry;

use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftAcceptorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRecipeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRedactorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiSurfaceRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatRegistry;
use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Composition\QuizCompositionRegistry;
use Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader\DefinitionUpgraderRegistry;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Integrity\IntegrityEventKindRegistry;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;
use Shondalai\VidyaLMS\Core\Registry\Interop\ItemMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LatePolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\ComplianceRule\ComplianceRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\FieldDisclosure\FieldDisclosureRegistry;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchRegistry;
use Shondalai\VidyaLMS\Core\Registry\NegativeMarking\NegativeMarkingRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgJoinRule\OrgJoinRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgMembershipTermination\OrgMembershipTerminationRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper\OrgRoleMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgType\OrgTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgUnitMove\OrgUnitMoveRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgVisibility\OrgVisibilityRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\SeatEventReason\SeatEventReasonRegistry;
use Shondalai\VidyaLMS\Core\Registry\SeatExpiryDisposition\SeatExpiryDispositionRegistry;
use Shondalai\VidyaLMS\Core\Registry\SeatOverAllocation\SeatOverAllocationRegistry;
use Shondalai\VidyaLMS\Core\Registry\SeatReclaim\SeatReclaimRegistry;
use Shondalai\VidyaLMS\Core\Registry\SeatScope\SeatScopeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\DeliveryFeedbackMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationCheckRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionPreset\QuestionPresetRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuizSetting\QuizSettingGroupRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Rubric\RubricRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRegistry;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverRegistry;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatRegistry;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationRegistry;
use Shondalai\VidyaLMS\Core\Registry\GradeDisplay\GradeDisplayRegistry;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryRegistry;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwarderRegistry;

/**
 * Every extension point of the core in one place. The free build fills each
 * with a working default at boot; Pro and third-party packages register more
 * through the same objects. No feature flags, no licence checks: the free
 * bytes are identical in both builds.
 *
 * Six of these carry the assessment design: the scoring policies a marking
 * rule is chosen from, the interaction primitives every type is composed of,
 * the attempt behaviours a quiz runs under, the definition upgraders a
 * format evolves through, the item mappers a foreign format crosses, and the
 * presets the picker shows. Every one of them lives in the free build even
 * where the free six barely stretch it, for the reason ADR 0009 gives for
 * the type registry: a seam only Pro uses is a seam nobody tests.
 */
final class Registries
{
    public function __construct(
        public readonly QuestionTypeRegistry $questionTypes = new QuestionTypeRegistry(),
        public readonly LessonTypeRegistry $lessonTypes = new LessonTypeRegistry(),
        public readonly EnrolmentRuleRegistry $enrolmentRules = new EnrolmentRuleRegistry(),
        public readonly CommerceSourceRegistry $commerceSources = new CommerceSourceRegistry(),
        public readonly ReportRegistry $reports = new ReportRegistry(),
        public readonly NotificationChannelRegistry $notificationChannels = new NotificationChannelRegistry(),
        public readonly CertificateRendererRegistry $certificateRenderers = new CertificateRendererRegistry(),
        public readonly ImporterRegistry $importers = new ImporterRegistry(),
        public readonly AiTaskRegistry $aiTasks = new AiTaskRegistry(),
        public readonly ScheduledTaskRegistry $scheduledTasks = new ScheduledTaskRegistry(),
        public readonly ScoringPolicyRegistry $scoringPolicies = new ScoringPolicyRegistry(),
        public readonly InteractionPrimitiveRegistry $interactionPrimitives = new InteractionPrimitiveRegistry(),
        public readonly AttemptPolicyRegistry $attemptPolicies = new AttemptPolicyRegistry(),
        public readonly DefinitionUpgraderRegistry $definitionUpgraders = new DefinitionUpgraderRegistry(),
        public readonly ItemMapperRegistry $itemMappers = new ItemMapperRegistry(),
        public readonly QuestionPresetRegistry $questionPresets = new QuestionPresetRegistry(),
        // The three quiz-level seams. Composition decides which questions an
        // attempt is served, aggregation decides which attempt is reported,
        // and the negative marking registry decides what a wrong answer
        // costs. All three are read by routes the free build serves, so a
        // site that installs nothing still answers them from what it has.
        public readonly QuizCompositionRegistry $quizCompositions = new QuizCompositionRegistry(),
        public readonly AggregationMethodRegistry $aggregationMethods = new AggregationMethodRegistry(),
        public readonly NegativeMarkingRegistry $negativeMarking = new NegativeMarkingRegistry(),
        /**
         * The checks run on the draft to ready transition.
         *
         * In the free build because several of the design's blocks are over
         * free types and free features, and because a third party adding a
         * check over a free type must not have to depend on a paid package
         * to reach the seam.
         */
        public readonly PublicationCheckRegistry $publicationChecks = new PublicationCheckRegistry(),
        /**
         * The advisory observations this site records about an attempt.
         *
         * In the core because integrity_events is a free table and the route
         * that writes it is a core route: a registry the paid package owned
         * meant the payload allowlist and the per-kind suppression rule
         * never ran on any install, and adding a first-party kind was a core
         * edit to a const array.
         */
        public readonly IntegrityEventKindRegistry $integrityEventKinds = new IntegrityEventKindRegistry(),
        /**
         * Rubrics, at most one provider. The contract declares
         * per-criterion marking on a core route, so the seam is core's; the
         * entities are a paid package's, and a site with no provider marks
         * on points exactly as it always did.
         */
        public readonly RubricRegistry $rubrics = new RubricRegistry(),
        /**
         * The settings groups a package adds to the quiz authoring form.
         *
         * The settings route was registry driven inside a field and
         * hardcoded around it: its five groups and their field lists were a
         * fixed method, so a package could contribute an option to one of
         * the four provider-keyed fields the core declares and could not
         * contribute a setting of its own without editing this package.
         */
        public readonly QuizSettingGroupRegistry $quizSettingGroups = new QuizSettingGroupRegistry(),
        /**
         * The learning-flow seams, seated in one edit rather than one at a
         * time, so this shipped file is touched once and the seams are
         * stable before anything registers into them.
         *
         * Two of them exist specifically to stop duplication elsewhere.
         * The anchor registry is shared by availability conditions, the
         * cohort schedule resolver and notification schedules, so there is
         * one anchor vocabulary in the product rather than three. The token
         * registry is shared by the notification templates and the
         * certificate designer, so an author learns one placeholder
         * language and a package that adds a placeholder adds it to both at
         * once.
         *
         * Every one of them ships with at least one working free provider
         * and a free code path that reaches it. A seam only the paid build
         * uses is a seam nobody tests, and a free provider nothing calls is
         * the same failure one step further in.
         */
        public readonly AvailabilityConditionRegistry $availabilityConditions = new AvailabilityConditionRegistry(),
        public readonly ScheduleAnchorRegistry $scheduleAnchors = new ScheduleAnchorRegistry(),
        public readonly LatePolicyRegistry $latePolicies = new LatePolicyRegistry(),
        public readonly NotificationTriggerRegistry $notificationTriggers = new NotificationTriggerRegistry(),
        public readonly NotificationPolicyRegistry $notificationPolicies = new NotificationPolicyRegistry(),
        public readonly AudienceResolverRegistry $audiences = new AudienceResolverRegistry(),
        public readonly DeliveryFeedbackMapperRegistry $deliveryFeedbackMappers = new DeliveryFeedbackMapperRegistry(),
        public readonly TokenResolverRegistry $tokenResolvers = new TokenResolverRegistry(),
        /**
         * The credential seams, seated in the same edit as the AI ones below
         * so this shipped file is touched once.
         *
         * All three ship with working free providers and free code paths that
         * reach them: seventeen elements drawn by the free elements renderer,
         * two credential formats served under content negotiation, and the
         * contact hours scheme written into certificate_credits at issue from
         * the course's own authored credit. The miss directions differ by
         * domain and are published on the standards page: a missing element
         * renders a visible placeholder naming the package, a missing format
         * is simply absent from negotiation, and a missing credit scheme
         * leaves the row stored and rendered as a raw amount with no
         * validation and no export.
         */
        public readonly CertificateElementRegistry $certificateElements = new CertificateElementRegistry(),
        public readonly CredentialFormatRegistry $credentialFormats = new CredentialFormatRegistry(),
        public readonly CreditSchemeRegistry $creditSchemes = new CreditSchemeRegistry(),
        /**
         * When a credential stops being in force. Here rather than in the
         * paid package because it is resolved at issue by free code on every
         * site: expiry_mode is a free column, the three shapes its vocabulary
         * names ship as free providers, and the answer is frozen into the
         * snapshot with everything else. A paid shape is then a registration
         * the same free call site resolves, rather than a key that falls
         * through to no expiry at issue and acquires one from a sweep a day
         * later.
         */
        public readonly ExpiryPolicyRegistry $expiryPolicies = new ExpiryPolicyRegistry(),
        /**
         * The AI and automation seams, seated before anything registers into
         * them and in this same edit for the same reason: the shipped file is
         * touched once and the seams are stable first.
         *
         * Eleven and not five. A stored provider key with no registry behind
         * it is a hardcoded list pretending to be a seam, and retry and
         * failure policies are stored keys on an automation action row, so
         * both are registries rather than const ladders nobody can replace.
         *
         * The model bridge sits here rather than in Ports because a bridge is
         * a package concern exactly as a notification channel is: Ports
         * documents itself as the complete set of services an adapter must
         * supply to boot the core, and a model is not one of them. An adapter
         * written before the AI wave therefore keeps booting untouched.
         *
         * These were seated empty, which was the one place the free-provider
         * rule was deferred rather than met, and deliberately: the free
         * providers for every one of them belonged to the automation and AI
         * stages, and seating the seams first is what let those stages ship
         * without a second edit to a file every package receives.
         *
         * **That deferral is spent.** The automation stage filled three of
         * them with nine actions, three retry ladders and four failure
         * dispositions, and the AI stage filled the other eight with one
         * recipe, nine placements, six surfaces, the null bridge, eleven
         * policies, the strict redactor, eighteen fact providers and six
         * draft acceptors. Every one of the eleven now meets the rule the
         * standards page states for every extension point in the product: at
         * least one working free provider and a free code path that reaches
         * it. Two tests assert it on a booted kernel rather than on this
         * comment, and a seam losing its last free provider fails them.
         */
        public readonly AiRecipeRegistry $aiRecipes = new AiRecipeRegistry(),
        public readonly AiPlacementRegistry $aiPlacements = new AiPlacementRegistry(),
        public readonly AiSurfaceRegistry $aiSurfaces = new AiSurfaceRegistry(),
        public readonly ModelBridgeRegistry $modelBridges = new ModelBridgeRegistry(),
        public readonly AiPolicyRegistry $aiPolicies = new AiPolicyRegistry(),
        public readonly AiRedactorRegistry $aiRedactors = new AiRedactorRegistry(),
        public readonly AiFactProviderRegistry $aiFactProviders = new AiFactProviderRegistry(),
        public readonly AiDraftAcceptorRegistry $aiDraftAcceptors = new AiDraftAcceptorRegistry(),
        public readonly AutomationActionRegistry $automationActions = new AutomationActionRegistry(),
        public readonly RetryPolicyRegistry $retryPolicies = new RetryPolicyRegistry(),
        public readonly FailurePolicyRegistry $failurePolicies = new FailurePolicyRegistry(),
        /**
         * Not one of the eleven above, and it needs saying because it sits
         * beneath their docblock and inherited its reasoning by position.
         *
         * It arrived from the organisation work in a separate edit after the
         * AI seams were seated, so nothing about it is AI: it is the seam a
         * package registers a data-subject export and erasure contributor
         * into, and its miss direction is its own. Reading the block above as
         * governing it would say a privacy contributor is seated empty on
         * purpose and filled by an AI stage, and all three halves of that are
         * false. Free contributors are registered at boot today.
         *
         * It also spends the touch-once claim the AI block makes. That claim
         * was that the shipped Registries file is edited once per wave so a
         * constructor every adapter and every paid package receives does not
         * change one property at a time; the file has since been widened by
         * the organisation spine and by this row, so the honest statement is
         * that the claim held for the eleven and is spent, not that the file
         * is never touched again.
         */
        public readonly PrivacyContributorRegistry $privacyContributors = new PrivacyContributorRegistry(),
        /**
         * The eighteen organisation seams, seated in one edit for the third
         * and last time this file is widened by a wave.
         *
         * Two of them already existed and were not registries. An org role and
         * a seat reclaim policy shipped as bespoke classes in Domain that threw
         * LogicException, had no replace(), did not extend
         * AbstractProviderRegistry and were constructed as local variables
         * inside the kernel, so no package could reach either of them from a
         * KernelReady listener. That failed the zero-core-edits test on every
         * registration the organisations design names, and it is why the
         * standards page had to publish two extension points as living outside
         * the pattern. They are here now, on the same terms as everything above
         * them, and the bespoke classes are gone.
         *
         * Four ship with free providers and a free route reaching each one. The
         * four org roles are resolved on every organisation read and drive the
         * role catalogue. The four reclaim policies are validated when a pool
         * is saved and asked when a seat is reclaimed. The eleven org types are
         * resolved when an organisation is read, which is where an unresolved
         * type renders as its own key, the published normal path. The one join
         * rule is asked before an invitation is accepted, and removing it stops
         * every acceptance, which is the published fail-closed direction rather
         * than a fallback to admitting everybody.
         *
         * The other fourteen are seated empty, with the shape of their one
         * declared method deliberately minimal, exactly as the AI seams were:
         * their free providers belong to the waves that build their callers,
         * and seating the seams first is what lets those waves ship without a
         * second edit to a file every adapter and every paid package receives.
         * Each interface publishes its own miss direction where its author
         * works, and the three families are the same three the standards page
         * names: a gate fails closed, a product surface renders and says what
         * is missing, and everything else takes a stated substitution.
         */
        public readonly OrgRoleRegistry $orgRoles = new OrgRoleRegistry(),
        public readonly OrgTypeRegistry $orgTypes = new OrgTypeRegistry(),
        public readonly OrgCatalogueSourceRegistry $orgCatalogueSources = new OrgCatalogueSourceRegistry(),
        public readonly OrgJoinRuleRegistry $orgJoinRules = new OrgJoinRuleRegistry(),
        public readonly OrgVisibilityRegistry $orgVisibility = new OrgVisibilityRegistry(),
        public readonly OrgMembershipTerminationRegistry $orgMembershipTermination = new OrgMembershipTerminationRegistry(),
        public readonly OrgUnitMoveRegistry $orgUnitMove = new OrgUnitMoveRegistry(),
        public readonly SeatScopeRegistry $seatScopes = new SeatScopeRegistry(),
        public readonly SeatReclaimRegistry $seatReclaim = new SeatReclaimRegistry(),
        public readonly SeatOverAllocationRegistry $seatOverAllocation = new SeatOverAllocationRegistry(),
        public readonly SeatExpiryDispositionRegistry $seatExpiryDisposition = new SeatExpiryDispositionRegistry(),
        public readonly SeatEventReasonRegistry $seatEventReasons = new SeatEventReasonRegistry(),
        public readonly InvitationMatchRegistry $invitationMatch = new InvitationMatchRegistry(),
        public readonly IdentityMapperRegistry $identityMappers = new IdentityMapperRegistry(),
        public readonly ProvisioningSourceRegistry $provisioningSources = new ProvisioningSourceRegistry(),
        public readonly RosterMapperRegistry $rosterMappers = new RosterMapperRegistry(),
        /**
         * The role translation seam, seated beside the roster one because a
         * roster load is where a foreign role word arrives and nowhere else
         * is. It is a separate seat from OrgRole because the two answer
         * different questions: OrgRole answers what a role does here, and
         * this answers which of those a foreign word meant. A customer whose
         * district encodes its own role numbers registers a mapper and
         * touches no core file.
         *
         * Miss direction: fail closed. No registered mapper translating a
         * token is the same outcome as a mapper that could not: the row is
         * skipped with a row problem naming the token, no default role is
         * substituted, and no membership is written.
         */
        public readonly OrgRoleMapperRegistry $orgRoleMappers = new OrgRoleMapperRegistry(),
        public readonly ComplianceRuleRegistry $complianceRules = new ComplianceRuleRegistry(),
        public readonly FieldDisclosureRegistry $fieldDisclosure = new FieldDisclosureRegistry(),
        public readonly ExportFormatRegistry $exportFormats = new ExportFormatRegistry(),
        public readonly ReportDeliveryRegistry $reportDeliveries = new ReportDeliveryRegistry(),
        public readonly GradeAggregationRegistry $gradeAggregations = new GradeAggregationRegistry(),
        public readonly GradeDisplayRegistry $gradeDisplays = new GradeDisplayRegistry(),
        public readonly StatementForwarderRegistry $statementForwarders = new StatementForwarderRegistry(),
    ) {
    }
}
