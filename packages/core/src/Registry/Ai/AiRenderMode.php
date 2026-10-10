<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * How a placement draws itself where its anchor puts it.
 *
 * A mount that read only the surface key would receive `instructor_workbench`
 * four times and have to map a placement key to a DOM slot by hand, which
 * falsifies the claim that the screen code is written once. The anchor names
 * the slot and this names the shape drawn in it.
 */
/*
 * **Why this is a closed enum when surfaceKey() deliberately is not.**
 *
 * AiPlacementInterface argues that a surface is a provider key rather than an
 * enum case, because as an enum the first helper on a certificate designer or
 * on any screen a third-party package ships would be a case inside
 * packages/core. The same argument reaches this type: the first vendor
 * placement wanting a fifth presentation, a diff view or a wizard step, is
 * exactly that core edit.
 *
 * It is closed anyway, and the difference is what reads it. A surface key is
 * read by the placement registry and by nothing else, so an unknown one
 * degrades to a panel that does not draw. A render mode is read by the generic
 * MOUNT, which has to know how to bind what it is given: a mode the bundle
 * cannot render is a control drawn with no behaviour behind it, and there is
 * no safe default to degrade to. So the four are the mount contract's own
 * vocabulary, versioned alongside it, and a fifth presentation is a mount
 * contract change rather than a registration.
 */
enum AiRenderMode: string
{
    /** A panel of its own, opened from a control beside the anchor. */
    case Panel = 'panel';

    /** A helper that fills declared fields on the form it sits in, and nothing else. */
    case FieldFill = 'field_fill';

    /** A control that opens a review queue rather than producing anything inline. */
    case QueueOpener = 'queue_opener';

    /** A proposal rendered in place, next to what it would change. */
    case InlineProposal = 'inline_proposal';
}
