<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Response;

/**
 * The presentation surface an interaction primitive is rendered on. Eight
 * primitives crossed with three surfaces generate every predefined QTI
 * interaction, which is why the six graphic interactions every surveyed
 * product ships as six implementations are three primitives here with the
 * surface set to media.
 */
enum Surface: string
{
    case Flow = 'flow';
    case Inline = 'inline';
    case Media = 'media';
}
