<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

/**
 * How an element names itself to the palette.
 *
 * Presentation order is declared, never inherited, for the reason the
 * question type picker already carries: replace() keeps the displaced key's
 * position, so decorating a shipped element would otherwise pin the free
 * seventeen at the top of the list and split their families across it.
 *
 * Default when absent: ungrouped and last, which is the safe reading an
 * author gets by writing nothing.
 */
interface ElementMetadataInterface
{
    public const GROUP_CONTENT = 'content';
    public const GROUP_IDENTITY = 'identity';
    public const GROUP_DATES = 'dates';
    public const GROUP_OUTCOME = 'outcome';
    public const GROUP_VERIFICATION = 'verification';
    public const GROUP_MEDIA = 'media';
    public const GROUP_DECORATION = 'decoration';
    public const GROUP_COMPLIANCE = 'compliance';
    public const GROUP_ESCAPE_HATCH = 'escape_hatch';

    public function group(): string;

    public function order(): int;
}
