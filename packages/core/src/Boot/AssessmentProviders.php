<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Boot;

use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Provider\Aggregation\HighestAggregationMethod;
use Shondalai\VidyaLMS\Core\Provider\Aggregation\LatestAggregationMethod;
use Shondalai\VidyaLMS\Core\Provider\AttemptPolicy\DeferredFeedbackAttemptPolicy;
use Shondalai\VidyaLMS\Core\Provider\AttemptPolicy\ManualAttemptPolicy;
use Shondalai\VidyaLMS\Core\Provider\Integrity\BrowserSignalKind;
use Shondalai\VidyaLMS\Core\Provider\Interaction\ArtifactPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\AssociatePrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\OrderPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\PointPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\ScalarPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\SelectPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\SignalPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\TextPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Publication\GuessNeutralBalanceCheck;
use Shondalai\VidyaLMS\Core\Provider\Publication\MinChoicesCheck;
use Shondalai\VidyaLMS\Core\Provider\Publication\NegativeMarkingCheck;
use Shondalai\VidyaLMS\Core\Provider\Publication\OrderingInKeyOrderCheck;
use Shondalai\VidyaLMS\Core\Provider\Publication\PositionalOptionCheck;
use Shondalai\VidyaLMS\Core\Provider\QuestionPreset\AliasPreset;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\FillBlankQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\MultipleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\OrderingQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\ShortAnswerQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\SingleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\TrueFalseQuestionType;
use Shondalai\VidyaLMS\Core\Provider\Scoring\FreeScoringPolicies;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ReportingMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Registries;

/**
 * Everything the free build registers into the six assessment registries, in
 * one place.
 *
 * One list rather than two, because the catalogue generator has to register
 * exactly what the kernel registers or the generated artefact describes a
 * site nobody runs. Keeping the list here means the generator needs no
 * database and no host, and the two cannot drift.
 *
 * The order the free six are registered in is deliberately not the picker
 * order: presentation order is declared through ReportingMetadataInterface,
 * because a decoration through replace() keeps the displaced key's position
 * and would otherwise pin the free six at positions one to six and split the
 * selection family across the list.
 */
final class AssessmentProviders
{
    private function __construct()
    {
    }

    public static function registerInto(Registries $registries, TranslatorPort $translator): void
    {
        self::registerScoringPolicies($registries);
        self::registerPrimitives($registries, $translator);
        self::registerAttemptPolicies($registries, $translator);
        self::registerAggregationMethods($registries, $translator);
        self::registerQuestionTypes($registries, $translator);
        self::registerPresets($registries, $translator);
        self::registerPublicationChecks($registries, $translator);
        self::registerIntegrityEventKinds($registries, $translator);
    }

    /**
     * The eight browser signals, one provider each.
     *
     * Free because integrity_events is a free table and the route that
     * writes it is a core route. A proctoring integration registers its own
     * kind here with its own suppression answer and its own payload
     * allowlist; nothing about that is a core edit.
     */
    private static function registerIntegrityEventKinds(Registries $registries, TranslatorPort $translator): void
    {
        foreach (BrowserSignalKind::all($translator) as $kind) {
            $registries->integrityEventKinds->register($kind);
        }
    }

    /**
     * The checks the free build runs on the draft to ready transition.
     *
     * Every one of these is over free content: a free marking rule, a free
     * type, or a free presentation feature. The paid package registers the
     * slot-, pool- and rubric-aware ones into the same registry, which is
     * what keeps the seam owned by neither package.
     */
    private static function registerPublicationChecks(Registries $registries, TranslatorPort $translator): void
    {
        $checks = [
            new NegativeMarkingCheck($translator),
            new MinChoicesCheck($translator),
            new PositionalOptionCheck($translator),
            new GuessNeutralBalanceCheck($translator),
            new OrderingInKeyOrderCheck($translator),
        ];

        foreach ($checks as $check) {
            $registries->publicationChecks->register($check);
        }
    }

    /**
     * The marking rules come first, because every question type resolves one
     * through this registry rather than welding a formula into grade().
     */
    private static function registerScoringPolicies(Registries $registries): void
    {
        FreeScoringPolicies::registerInto($registries->scoringPolicies);
    }

    /**
     * All eight, in the free build, even though the free six stretch only
     * three of them. A seam only the paid build uses is a seam nobody tests.
     */
    private static function registerPrimitives(Registries $registries, TranslatorPort $translator): void
    {
        // The two cardinality messages a learner reads in the error summary
        // are built here, so a primitive takes the translator like every
        // other provider that owns copy.
        $primitives = [
            new SelectPrimitive($translator),
            new OrderPrimitive($translator),
            new AssociatePrimitive($translator),
            new TextPrimitive($translator),
            new ScalarPrimitive($translator),
            new PointPrimitive($translator),
            new ArtifactPrimitive($translator),
            new SignalPrimitive($translator),
        ];

        foreach ($primitives as $primitive) {
            $registries->interactionPrimitives->register($primitive);
        }
    }

    private static function registerAttemptPolicies(Registries $registries, TranslatorPort $translator): void
    {
        $registries->attemptPolicies->register(new DeferredFeedbackAttemptPolicy($translator));
        $registries->attemptPolicies->register(new ManualAttemptPolicy($translator));
    }

    /**
     * The two free aggregation methods.
     *
     * Highest is the default and is what the free build has always reported
     * as a learner's best percentage; latest is the other rule a site can
     * express without arithmetic over a set. Average, first, lowest, average
     * of the best n and a decaying average are registrations rather than
     * migrations, which is the whole reason the aggregator is a provider.
     *
     * The negative marking registry deliberately gets nothing. The seam
     * exists so a rule can be added without a core edit, and the free build
     * offering none is a decision the design argues for at length rather
     * than a gap.
     */
    private static function registerAggregationMethods(Registries $registries, TranslatorPort $translator): void
    {
        $registries->aggregationMethods->register(new HighestAggregationMethod($translator));
        $registries->aggregationMethods->register(new LatestAggregationMethod($translator));
    }

    private static function registerQuestionTypes(Registries $registries, TranslatorPort $translator): void
    {
        $policies = $registries->scoringPolicies;

        $types = [
            new SingleChoiceQuestionType($translator, $policies),
            new MultipleChoiceQuestionType($translator, $policies),
            new TrueFalseQuestionType($translator, $policies),
            new FillBlankQuestionType($translator, $policies),
            new ShortAnswerQuestionType($translator, $policies),
            new OrderingQuestionType($translator, $policies),
        ];

        foreach ($types as $type) {
            $registries->questionTypes->register($type);
        }
    }

    /**
     * Six presets: one alias of each free type, so the picker's family
     * headings and an importer's alias lookup both have a source in the free
     * build rather than only once a paid package arrives.
     *
     * A preset creates no provider key, stores no value in questions.type and
     * adds no code path.
     */
    private static function registerPresets(Registries $registries, TranslatorPort $translator): void
    {
        $selection = ReportingMetadataInterface::GROUP_SELECTION;
        $textEntry = ReportingMetadataInterface::GROUP_TEXT_ENTRY;
        $association = ReportingMetadataInterface::GROUP_ASSOCIATION;

        // A label is a catalogue key, never an English literal: it reaches the
        // author's picker through GET /question-types, so it is copy and copy
        // goes through TranslatorPort like every other label the free build
        // registers.
        $presets = [
            ['single_choice', $selection, 'match_correct', [
                'cq:single',
                'moodle:multichoice_single',
            ]],
            ['multiple_choice', $selection, 'guess_neutral', [
                'cq:multiple',
                'moodle:multichoice_multi',
                'ncsbn:sata',
            ]],
            ['true_false', $selection, 'match_correct', [
                'cq:truefalse',
                'moodle:truefalse',
            ]],
            ['short_answer', $textEntry, 'text_match', [
                'cq:shortanswer',
                'moodle:shortanswer',
            ]],
            ['fill_blank', $textEntry, 'per_target', [
                'cq:fillblank',
            ]],
            // No aliases. The design's preset table assigns moodle:ordering,
            // canvas:ordering, d2l:ordering and cq:sequence to the
            // sequencing preset and cq:ranking to the ranking preset, both
            // of which carry a marking rule this bare alias does not, and an
            // alias selects exactly one import target. Claiming them here
            // made the answer depend on registration order the moment those
            // presets exist, and the whole point of an alias is that it does
            // not. Nothing free reads an alias: every mapper is a Pro
            // provider, so a site that can resolve one has the specific
            // preset too.
            ['ordering', $association, 'kendall_tau', []],
        ];

        foreach ($presets as [$typeKey, $group, $policy, $aliases]) {
            $registries->questionPresets->register(new AliasPreset(
                $typeKey,
                NeutralStrings::QUESTION_PRESET_PREFIX . $typeKey,
                $group,
                $typeKey,
                $policy,
                [],
                $aliases,
                null,
                $translator,
            ));
        }
    }
}
