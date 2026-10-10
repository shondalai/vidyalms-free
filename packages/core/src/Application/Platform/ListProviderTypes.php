<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionPreset\QuestionPresetInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionPreset\QuestionPresetRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ReportingMetadataInterface;

/**
 * What this site can author, straight from the registries.
 *
 * Nothing here is gated: a free build answers the five lesson types and six
 * question types it registers, and a site with Pro answers more from the same
 * routes. The authoring pickers list exactly this, which is why a Pro type
 * simply does not appear on a free site rather than appearing greyed out.
 *
 * Requires teach or manage, the capabilities that open an authoring screen.
 */
final class ListProviderTypes
{
    public function __construct(
        private readonly LessonTypeRegistry $lessonTypes,
        private readonly QuestionTypeRegistry $questionTypes,
        private readonly AccessGuard $guard,
        private readonly QuestionPresetRegistry $questionPresets = new QuestionPresetRegistry(),
    ) {
    }

    /**
     * @return list<LessonTypeInterface> in registration order
     */
    public function lessonTypes(Principal $principal): array
    {
        $this->requireAuthoring($principal);

        return $this->lessonTypes->all();
    }

    /**
     * The registered question types, sorted by their declared group and
     * order rather than by registration order.
     *
     * Registration order is not picker order. replace() keeps a displaced
     * key's position, so decorating the free six would pin them at positions
     * one to six and split the selection family across the list; and the
     * free build already registers fill_blank before short_answer while
     * every table in the design lists them the other way round.
     *
     * @return list<QuestionTypeInterface>
     */
    public function questionTypes(Principal $principal): array
    {
        $this->requireAuthoring($principal);

        $types = $this->questionTypes->all();

        usort($types, static function (QuestionTypeInterface $a, QuestionTypeInterface $b): int {
            $left = [
                $a instanceof ReportingMetadataInterface ? $a->group() : '',
                $a instanceof ReportingMetadataInterface ? $a->order() : 0,
                $a->key(),
            ];
            $right = [
                $b instanceof ReportingMetadataInterface ? $b->group() : '',
                $b instanceof ReportingMetadataInterface ? $b->order() : 0,
                $b->key(),
            ];

            return $left <=> $right;
        });

        return $types;
    }

    /**
     * The presets the picker offers over those types. Empty is a legitimate
     * answer: a preset stores nothing and no row references one.
     *
     * @return list<QuestionPresetInterface>
     */
    public function questionPresets(Principal $principal): array
    {
        $this->requireAuthoring($principal);

        return $this->questionPresets->all();
    }

    private function requireAuthoring(Principal $principal): void
    {
        $this->guard->requireAuthenticated($principal);

        if (
            !$this->guard->can($principal, Capability::TEACH)
            && !$this->guard->can($principal, Capability::MANAGE)
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw NotAuthorizedException::forCapability(Capability::TEACH);
        }
    }
}
