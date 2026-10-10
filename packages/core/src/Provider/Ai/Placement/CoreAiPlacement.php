<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Placement;

use Shondalai\VidyaLMS\Core\Provider\Ai\Surface\CoreAiSurface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDisclosure;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDisclosureMode;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRenderMode;
use Shondalai\VidyaLMS\Core\Registry\Ai\DescribesAiPlacement;
use Shondalai\VidyaLMS\Core\Registry\Ai\TaskDescriptor;
use Shondalai\VidyaLMS\Core\Registry\Context\PlacementContext;

/**
 * The nine shipped helper positions, as data rather than as nine classes.
 *
 * What differs between them is a surface, an anchor, a render mode, the draft
 * kinds the panel can render and the fields a field-fill helper may write. A
 * placement whose *behaviour* differed would be its own class; none of these
 * nine does, and nine files repeating one predicate is how one of them
 * eventually drifts.
 *
 * **The predicate never names a task key**, which is the whole extensibility
 * argument. A placement naming keys would make adding the eighteenth paid
 * task, or any third-party task, to the shipped question bank panel an edit
 * to a free provider inside the free bundle. Over a descriptor, a task
 * reaches a shipped panel with zero edits: it declares the placement key
 * through TaskMetadataInterface, and the panel's predicate either accepts the
 * shape or does not.
 *
 * **offersFor() reads the permissions that resolved rather than a task
 * list.** Permission is per (placement, task) pair, `ai.<placement>.<task>`,
 * so the grants the access spine already resolved for this caller are exactly
 * the per-request answer, and a placement holding a registry would be a
 * placement able to offer a task nobody granted.
 */
final class CoreAiPlacement implements AiPlacementInterface, DescribesAiPlacement
{
    public const WORKBENCH_CURRICULUM = 'workbench_curriculum';
    public const WORKBENCH_LESSON_EDITOR = 'workbench_lesson_editor';
    public const WORKBENCH_QUESTION_BANK = 'workbench_question_bank';
    public const WORKBENCH_QUESTION_EDITOR = 'workbench_question_editor';
    public const GRADEBOOK_FEEDBACK = 'gradebook_feedback';
    public const GRADEBOOK_GROUPING = 'gradebook_grouping';
    public const IMPORT_REVIEW = 'import_review';
    public const ADMIN_NARRATIVE = 'admin_narrative';
    public const PLAYER_EXPLAIN_MISTAKE = 'player_explain_mistake';

    /** The permission prefix a (placement, task) grant is spelled with. */
    public const PERMISSION_PREFIX = 'ai.';

    /** @var list<string> */
    private readonly array $acceptedDraftKinds;

    /** @var list<string> */
    private readonly array $fillableFields;

    /**
     * @param list<string> $acceptedDraftKinds the shapes this panel can render, empty meaning any
     * @param list<string> $fillableFields     the only fields a FieldFill helper may write
     */
    private function __construct(
        private readonly string $key,
        private readonly string $surfaceKey,
        private readonly string $anchor,
        private readonly AiRenderMode $renderMode,
        private readonly string $scopeLevel,
        private readonly AiDisclosureMode $disclosureMode,
        array $acceptedDraftKinds,
        array $fillableFields,
    ) {
        $this->acceptedDraftKinds = $acceptedDraftKinds;
        $this->fillableFields = $fillableFields;
    }

    /** Placement 1: the shape of the course. */
    public static function workbenchCurriculum(): self
    {
        return new self(
            self::WORKBENCH_CURRICULUM,
            CoreAiSurface::INSTRUCTOR_WORKBENCH,
            'workbench.curriculum.header',
            AiRenderMode::Panel,
            'course',
            AiDisclosureMode::AtFirstUse,
            ['course_outline', 'module', 'findings'],
            [],
        );
    }

    /** Placement 2: publish, edit or discard this lesson body. */
    public static function workbenchLessonEditor(): self
    {
        return new self(
            self::WORKBENCH_LESSON_EDITOR,
            CoreAiSurface::INSTRUCTOR_WORKBENCH,
            'workbench.lesson.editor',
            AiRenderMode::Panel,
            'course',
            AiDisclosureMode::AtFirstUse,
            ['lesson', 'alt_text', 'narrative', 'findings'],
            [],
        );
    }

    /** Placement 3: which items are fit to be asked. Opens a review queue. */
    public static function workbenchQuestionBank(): self
    {
        return new self(
            self::WORKBENCH_QUESTION_BANK,
            CoreAiSurface::INSTRUCTOR_WORKBENCH,
            'workbench.bank.header',
            AiRenderMode::QueueOpener,
            'course',
            AiDisclosureMode::AtFirstUse,
            ['question', 'bank_entry', 'rubric', 'mapping_suggestion', 'findings'],
            [],
        );
    }

    /**
     * Placement 4: the wording of this item.
     *
     * It fills form fields and never saves; the human presses Save question.
     * The item's own key is deliberately absent from the fillable list, which
     * is what makes "a suggestion never changes the key without an explicit
     * confirmation" a contract rather than a screen note: there is no
     * confirmation flow to walk around, because the helper cannot bind the
     * field at all.
     */
    public static function workbenchQuestionEditor(): self
    {
        return new self(
            self::WORKBENCH_QUESTION_EDITOR,
            CoreAiSurface::INSTRUCTOR_WORKBENCH,
            'workbench.question-editor.field',
            AiRenderMode::FieldFill,
            'item',
            // Every interaction, not at first use. This is the one FieldFill
            // panel in the design and its disclosure is an inline marker on
            // each filled field: a marker shown once at the top of a session
            // does not tell an author which of the four fields in front of
            // them was written by a model.
            AiDisclosureMode::EveryInteraction,
            ['question_fields', 'findings'],
            // `stem` is a deliberate addition to the design's two offers, and
            // it is inside the redline rather than an exception to it: a stem
            // is the item's own question text and carries no mark and no gate.
            // ReservedFields refuses the fields that do, and the registry now
            // enforces that refusal on a placement as well as on a task.
            ['feedback', 'distractors', 'stem'],
        );
    }

    /**
     * Placement 5: what the instructor says to the learner.
     *
     * The list is one field, and the score field appears in no placement's
     * list anywhere in the product.
     */
    public static function gradebookFeedback(): self
    {
        return new self(
            self::GRADEBOOK_FEEDBACK,
            CoreAiSurface::GRADEBOOK,
            'workbench.gradebook.feedback',
            AiRenderMode::FieldFill,
            'item',
            AiDisclosureMode::AtFirstUse,
            ['feedback_text', 'findings'],
            ['feedback_to_learner'],
        );
    }

    /** Placement 6: grade a cluster once instead of a hundred times. */
    public static function gradebookGrouping(): self
    {
        return new self(
            self::GRADEBOOK_GROUPING,
            CoreAiSurface::GRADEBOOK,
            'workbench.gradebook.grouping',
            AiRenderMode::InlineProposal,
            'item',
            AiDisclosureMode::AtFirstUse,
            ['grouping_proposal', 'findings'],
            [],
        );
    }

    /** Placement 7: which mapping to register. Suggestions only. */
    public static function importReview(): self
    {
        return new self(
            self::IMPORT_REVIEW,
            CoreAiSurface::IMPORT_REVIEW,
            'import.review.header',
            AiRenderMode::Panel,
            'course',
            AiDisclosureMode::AtFirstUse,
            ['mapping_suggestion', 'findings'],
            [],
        );
    }

    /** Placement 8: what the administrator tells a stakeholder. */
    public static function adminNarrative(): self
    {
        return new self(
            self::ADMIN_NARRATIVE,
            CoreAiSurface::ADMIN,
            'admin.report.header',
            AiRenderMode::Panel,
            'site',
            AiDisclosureMode::AtFirstUse,
            ['narrative', 'findings'],
            [],
        );
    }

    /**
     * Placement 9: the learner's own revision plan, and the only
     * learner-facing position in the design.
     *
     * It ships disabled, its disclosure floor is EveryInteraction, and its
     * accepted draft kinds are empty on purpose: the task behind it produces
     * no draft row at all, so nothing here is ever stored as an artefact.
     */
    public static function playerExplainMistake(): self
    {
        return new self(
            self::PLAYER_EXPLAIN_MISTAKE,
            CoreAiSurface::LEARNER_PLAYER,
            'player.attempt-review',
            AiRenderMode::InlineProposal,
            'item',
            AiDisclosureMode::EveryInteraction,
            ['findings'],
            [],
        );
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::workbenchCurriculum(),
            self::workbenchLessonEditor(),
            self::workbenchQuestionBank(),
            self::workbenchQuestionEditor(),
            self::gradebookFeedback(),
            self::gradebookGrouping(),
            self::importReview(),
            self::adminNarrative(),
            self::playerExplainMistake(),
        ];
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'ai.placement.' . $this->key . '.label';
    }

    public function surfaceKey(): string
    {
        return $this->surfaceKey;
    }

    public function description(): string
    {
        return 'ai.placement.' . $this->key . '.description';
    }

    public function anchor(): string
    {
        return $this->anchor;
    }

    public function renderMode(): AiRenderMode
    {
        return $this->renderMode;
    }

    /**
     * The static half a picker is built from: would this panel ever offer a
     * task of this shape.
     *
     * Three questions and no task key among them. Did the task say it belongs
     * on this surface; is its draft kind one this panel can render; and would
     * a field-fill helper have anywhere to put the answer.
     */
    public function accepts(TaskDescriptor $task, PlacementContext $context): bool
    {
        if (!$task->fitsSurface($this->surfaceKey)) {
            return false;
        }

        if ($this->acceptedDraftKinds !== [] && !\in_array($task->draftKind ?? 'findings', $this->acceptedDraftKinds, true)) {
            return false;
        }

        // A FieldFill panel with nothing to fill is a panel that would draw a
        // control and then refuse to bind whatever it produced.
        return $this->renderMode !== AiRenderMode::FieldFill || $this->fillableFields !== [];
    }

    public function fillableFields(): array
    {
        return $this->fillableFields;
    }

    public function scopeLevel(): string
    {
        return $this->scopeLevel;
    }

    public function disclosure(): AiDisclosure
    {
        return AiDisclosure::of(
            $this->disclosureMode,
            'ai.disclosure.' . $this->key . '.before_interaction',
            'ai.disclosure.' . $this->key . '.at_read_time',
            'ai.disclosure.' . $this->key . '.at_review',
        );
    }

    /**
     * The task keys actually offered here now.
     *
     * They are read off the grants that resolved, because the grant is named
     * `ai.<placement>.<task>` and therefore already answers the per-request
     * question. A placement holding a task registry would be a placement able
     * to offer something nobody granted, which is the one thing this method
     * exists to prevent.
     */
    public function offersFor(PlacementContext $context): array
    {
        $prefix = self::PERMISSION_PREFIX . $this->key . '.';
        $offered = [];

        foreach ($context->permissions as $permission) {
            if (str_starts_with($permission, $prefix)) {
                $offered[] = substr($permission, \strlen($prefix));
            }
        }

        sort($offered);

        return array_values(array_unique($offered));
    }
}
