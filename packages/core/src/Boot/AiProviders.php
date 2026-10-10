<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Boot;

use Shondalai\VidyaLMS\Core\Application\Ai\AiSubjectCourse;
use Shondalai\VidyaLMS\Core\Application\Assessment\AddQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\GradeAnswerManually;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddAuthoredLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetAuthoringLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Provider\Ai\Acceptor\AltTextAcceptor;
use Shondalai\VidyaLMS\Core\Provider\Ai\Acceptor\CurriculumAcceptor;
use Shondalai\VidyaLMS\Core\Provider\Ai\Acceptor\FeedbackTextAcceptor;
use Shondalai\VidyaLMS\Core\Provider\Ai\Acceptor\QuestionAcceptor;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\BankSiblingDigestsFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\BankSiblingVectorsFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\ItemContentFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\ItemStatisticFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\RecordedMarkFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\ReportDatasetFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\ResponseBodyFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\SubmissionFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\NullModelBridge;
use Shondalai\VidyaLMS\Core\Provider\Ai\Placement\CoreAiPlacement;
use Shondalai\VidyaLMS\Core\Provider\Ai\Placement\ReservedFields;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\AttemptStatePolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\ContentScopePolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PerRunCallsPolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PerRunCostPolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PlacementEnabledPolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PolicyAcceptedPolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\RouteAvailablePolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\SubjectScopePolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\WindowedCeilingPolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Recipe\StructuralBuildRecipe;
use Shondalai\VidyaLMS\Core\Provider\Ai\StrictRedactor;
use Shondalai\VidyaLMS\Core\Provider\Ai\Surface\CoreAiSurface;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckAccessibility;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckAnswerLeak;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckCitationCoverage;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckInjectionMarkers;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckItemWritingFlaws;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckNearDuplicate;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckReadability;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckSchemaValidity;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\GroupEquivalentAnswers;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\OutlineFromHeadings;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\QuestionTemplateAig;
use Shondalai\VidyaLMS\Core\Registry\Registries;

/**
 * The free providers for all eight AI seams, registered in one place.
 *
 * **This is the exit gate of the AI build's free stage, and it is an
 * assertion rather than an intention.** The eleven seams were seated empty in
 * an earlier wave with the divergence written into the shipped bytes: it was
 * the one place the every-registry-ships-a-free-provider rule was deferred
 * rather than met, and deliberately so, because widening an interface is free
 * only while nothing implements it. The interfaces are widened and closed now,
 * so the deferral is spent, and every one of the eight AI seams gets at least
 * one working free provider reached by a free test.
 *
 * What that buys a site that never pays for anything: the drafts, the
 * provenance, the dry run, the stop control, the policies, the redaction and
 * the learner's right to know, all of it exercised end to end by eleven
 * deterministic tasks over a bridge that reaches no model. **No provider here
 * opens a socket, holds a credential or names a vendor.**
 *
 * The order matters in exactly one place and it is enforced rather than
 * commented: the fact registry and the reserved-field scan are bound to the
 * task registry **before** any task registers, because a task declaring a
 * fact nothing supplies is a registration-time refusal and a rule that only
 * applies to whoever registers last is not a rule.
 */
final class AiProviders
{
    private function __construct()
    {
    }

    public static function registerInto(
        Registries $registries,
        DatabasePort $database,
        CourseAccess $courseAccess,
        AddQuestion $addQuestion,
        AddModule $addModule,
        AddAuthoredLesson $addAuthoredLesson,
        GetAuthoringLesson $getAuthoringLesson,
        UpdateLesson $updateLesson,
        GradeAnswerManually $gradeAnswerManually,
    ): void {
        self::registerFacts($registries);
        self::registerBridges($registries);
        self::registerRedactors($registries);
        self::registerSurfaces($registries);
        self::registerPlacements($registries);
        self::registerPolicies($registries, $database, $courseAccess);
        self::registerAcceptors(
            $registries,
            $addQuestion,
            $addModule,
            $addAuthoredLesson,
            $getAuthoringLesson,
            $updateLesson,
            $gradeAnswerManually,
        );

        // Tasks last, and the two guards bound first, so the free tasks meet
        // the same registration-time refusals a third-party task will.
        $registries->aiTasks->validateFactsAgainst($registries->aiFactProviders);
        $registries->aiTasks->refuseReservedFieldsWith(
            static fn (array $schema): array => ReservedFields::boundBy($schema),
        );

        self::registerTasks($registries);
        self::registerRecipes($registries);
    }

    /**
     * The eighteen shipped facts.
     *
     * A vendor needing a nineteenth ships one, which is the whole reason a
     * fact is a registered provider rather than a name the core knows.
     */
    private static function registerFacts(Registries $registries): void
    {
        foreach (ItemStatisticFact::all() as $fact) {
            $registries->aiFactProviders->register($fact);
        }

        foreach (ItemContentFact::all($registries->questionTypes) as $fact) {
            $registries->aiFactProviders->register($fact);
        }

        foreach (SubmissionFact::all() as $fact) {
            $registries->aiFactProviders->register($fact);
        }

        foreach (RecordedMarkFact::all() as $fact) {
            $registries->aiFactProviders->register($fact);
        }

        foreach (ResponseBodyFact::all() as $fact) {
            $registries->aiFactProviders->register($fact);
        }

        $registries->aiFactProviders->register(new ReportDatasetFact());
        $registries->aiFactProviders->register(new BankSiblingDigestsFact());

        // Registered free even though it always answers the empty set on a
        // free build. Leaving it out would make the duplicate check's own
        // declaration unsatisfiable, and the check would be refused at
        // registration and disappear from the free build entirely.
        $registries->aiFactProviders->register(new BankSiblingVectorsFact());
    }

    private static function registerBridges(Registries $registries): void
    {
        $registries->modelBridges->register(new NullModelBridge());
    }

    private static function registerRedactors(Registries $registries): void
    {
        $registries->aiRedactors->register(new StrictRedactor());
    }

    private static function registerSurfaces(Registries $registries): void
    {
        foreach (CoreAiSurface::all() as $surface) {
            $registries->aiSurfaces->register($surface);
        }
    }

    private static function registerPlacements(Registries $registries): void
    {
        // Bound before the first placement registers, for the same reason the
        // task registry's scan is: a rule that only applies to whoever
        // registers last is not a rule.
        $registries->aiPlacements->refuseReservedFieldsWith(
            static fn (string $field): bool => ReservedFields::isReserved($field),
        );

        foreach (CoreAiPlacement::all() as $placement) {
            $registries->aiPlacements->register($placement);
        }
    }

    /**
     * The eleven free ceilings and gates.
     *
     * All eleven are free rather than paid, and the reason is short: a
     * ceiling that exists only in the paid build is not a ceiling.
     */
    private static function registerPolicies(
        Registries $registries,
        DatabasePort $database,
        CourseAccess $courseAccess,
    ): void {
        $registries->aiPolicies->register(new PolicyAcceptedPolicy($database));
        $registries->aiPolicies->register(
            new PlacementEnabledPolicy($registries->aiPlacements, $registries->aiTasks),
        );
        $registries->aiPolicies->register(new RouteAvailablePolicy($database));
        $registries->aiPolicies->register(new PerRunCostPolicy($database));
        $registries->aiPolicies->register(new PerRunCallsPolicy());

        foreach (WindowedCeilingPolicy::all($database) as $policy) {
            $registries->aiPolicies->register($policy);
        }

        $registries->aiPolicies->register(new AttemptStatePolicy($database));
        $registries->aiPolicies->register(new ContentScopePolicy($courseAccess));
        // The scope half and the subject half are two policies rather than one,
        // because they answer different questions and a site counting refusals
        // needs to know which of the two refused.
        $registries->aiPolicies->register(new SubjectScopePolicy(new AiSubjectCourse($database), $courseAccess));
    }

    /**
     * The six free acceptors.
     *
     * Bank entries, rubrics and translations are the paid three, and the
     * published miss says what a site sees without them: the draft cannot be
     * accepted, the review screen names the package that would supply the
     * acceptor, and nothing is written.
     */
    private static function registerAcceptors(
        Registries $registries,
        AddQuestion $addQuestion,
        AddModule $addModule,
        AddAuthoredLesson $addAuthoredLesson,
        GetAuthoringLesson $getAuthoringLesson,
        UpdateLesson $updateLesson,
        GradeAnswerManually $gradeAnswerManually,
    ): void {
        $registries->aiDraftAcceptors->register(new QuestionAcceptor($addQuestion));

        foreach (CurriculumAcceptor::all($addModule, $addAuthoredLesson) as $acceptor) {
            $registries->aiDraftAcceptors->register($acceptor);
        }

        $registries->aiDraftAcceptors->register(new AltTextAcceptor($getAuthoringLesson, $updateLesson));
        $registries->aiDraftAcceptors->register(new FeedbackTextAcceptor($gradeAnswerManually));
    }

    /**
     * The eleven deterministic tasks: eight checks and three producers.
     *
     * A check is a function from a draft to a list of findings, which is
     * exactly a task with a findings output and no extension point, so they
     * register here rather than in a seventh registry. They appear in the
     * same picker, are gated by the same policies and produce provenance
     * through the same path as anything a paid package ships.
     */
    private static function registerTasks(Registries $registries): void
    {
        $registries->aiTasks->register(new CheckSchemaValidity($registries->questionTypes));
        $registries->aiTasks->register(new CheckItemWritingFlaws());
        $registries->aiTasks->register(new CheckNearDuplicate());
        $registries->aiTasks->register(new CheckCitationCoverage());
        $registries->aiTasks->register(new CheckReadability());
        $registries->aiTasks->register(new CheckAnswerLeak());
        $registries->aiTasks->register(new CheckAccessibility());
        $registries->aiTasks->register(new CheckInjectionMarkers());
        $registries->aiTasks->register(new OutlineFromHeadings());
        $registries->aiTasks->register(new QuestionTemplateAig());
        $registries->aiTasks->register(new GroupEquivalentAnswers());
    }

    private static function registerRecipes(Registries $registries): void
    {
        $registries->aiRecipes->register(new StructuralBuildRecipe());
    }
}
