<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * When the disclosure panel is drawn.
 *
 * A learner-facing surface answers EveryInteraction because Article 50(1)
 * asks for the disclosure before the first turn and a person who has scrolled
 * past it once has not been told twice. An authoring surface answers
 * AtFirstUse. None is a real answer for a surface whose audience is the
 * person operating the tool on their own material, and it is an answer a
 * surface has to give rather than one it inherits by silence.
 */
enum AiDisclosureMode: string
{
    case None = 'none';
    case AtFirstUse = 'at_first_use';
    case EveryInteraction = 'every_interaction';
}
