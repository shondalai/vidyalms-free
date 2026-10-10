<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Surface;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiDisclosureMode;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiSurfaceInterface;

/**
 * The six screen families the free build publishes as anchors.
 *
 * A surface is a registered provider rather than a closed enum, because the
 * first helper on a certificate designer, a cohort screen, an organisation
 * admin screen or any screen a third-party package ships would otherwise be
 * an enum case inside packages/core.
 *
 * One class with six named factories rather than six near-identical classes,
 * following the shape the automation actions already use for their paired
 * keys: everything that differs between these six is data, and a surface
 * whose behaviour differed would be a different class rather than a sixth
 * factory here.
 *
 * **The default disclosure mode belongs to the surface and not to the
 * placement**, because the audience is a property of the screen. A learner
 * player discloses before every interaction whatever helper is mounted in it,
 * and a placement able to lower that would be a placement able to opt out of
 * the disclosure obligation by registration.
 */
final class CoreAiSurface implements AiSurfaceInterface
{
    public const INSTRUCTOR_WORKBENCH = 'instructor_workbench';
    public const QUESTION_BANK = 'question_bank';
    public const GRADEBOOK = 'gradebook';
    public const IMPORT_REVIEW = 'import_review';
    public const ADMIN = 'admin';
    public const LEARNER_PLAYER = 'learner_player';

    private function __construct(
        private readonly string $key,
        private readonly string $scopeLevel,
        private readonly AiDisclosureMode $disclosure,
    ) {
    }

    /** The authoring screen family: curriculum, lesson editor, bank header, item editor. */
    public static function instructorWorkbench(): self
    {
        return new self(self::INSTRUCTOR_WORKBENCH, 'course', AiDisclosureMode::AtFirstUse);
    }

    /**
     * The standalone bank screen.
     *
     * The free build anchors no placement here, and that is the point rather
     * than an omission: banks are a paid capability, so shipping the surface
     * free is what lets a bank helper anchor without an edit to a free
     * provider. A surface with no free placement renders no panel and errors
     * on nothing.
     */
    public static function questionBank(): self
    {
        return new self(self::QUESTION_BANK, 'site', AiDisclosureMode::AtFirstUse);
    }

    /** The marking screens, which are learner-data screens. */
    public static function gradebook(): self
    {
        return new self(self::GRADEBOOK, 'course', AiDisclosureMode::AtFirstUse);
    }

    public static function importReview(): self
    {
        return new self(self::IMPORT_REVIEW, 'course', AiDisclosureMode::AtFirstUse);
    }

    public static function admin(): self
    {
        return new self(self::ADMIN, 'site', AiDisclosureMode::AtFirstUse);
    }

    /**
     * The one learner-facing family, and the only one whose floor is
     * EveryInteraction: the disclosure obligation asks for it before the
     * first turn, and a person who scrolled past it once has not been told
     * twice.
     */
    public static function learnerPlayer(): self
    {
        return new self(self::LEARNER_PLAYER, 'item', AiDisclosureMode::EveryInteraction);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'ai.surface.' . $this->key . '.label';
    }

    public function scopeLevel(): string
    {
        return $this->scopeLevel;
    }

    public function defaultDisclosureMode(): AiDisclosureMode
    {
        return $this->disclosure;
    }

    /**
     * Every surface the free build registers, in registration order.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::instructorWorkbench(),
            self::questionBank(),
            self::gradebook(),
            self::importReview(),
            self::admin(),
            self::learnerPlayer(),
        ];
    }
}
