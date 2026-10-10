<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

/**
 * How a condition names itself to the authoring palette.
 *
 * Presentation order is declared, never inherited: replace() keeps the
 * displaced key's position, so treating registration order as presentation
 * order pins a decorated free provider at the top of the list and splits its
 * family. That mistake is recorded once in this codebase already and is not
 * made again here.
 *
 * advisory() is true for a condition reading a learner-editable fact. Those
 * are not security controls, and the picker says so rather than leaving it to
 * a docblock, so nobody builds a paywall on a profile field.
 */
interface ConditionMetadataInterface
{
    public const GROUP_SCHEDULE = 'schedule';
    public const GROUP_PROGRESS = 'progress';
    public const GROUP_AUDIENCE = 'audience';
    public const GROUP_GATE = 'gate';
    public const GROUP_CREDENTIAL = 'credential';

    public function group(): string;

    /** Sort order within the palette, ascending; ties break on label. */
    public function order(): int;

    public function advisory(): bool;

    /**
     * The JSON Schema of the unwrapped payload at currentShape(). The form
     * renders it; nothing in the core interprets it.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array;
}
