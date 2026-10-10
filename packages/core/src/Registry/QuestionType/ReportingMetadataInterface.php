<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * How a type names itself to the picker, to xAPI and to a QTI export.
 *
 * Registration order is not the author's picker order, and treating it as
 * one was a mistake: replace() keeps the displaced key's position, so
 * decorating the free six pins them at positions one to six and splits the
 * selection family across the list. The free build already breaks the
 * contract, registering fill_blank before short_answer while every table in
 * the design lists them the other way round.
 *
 * Presentation order is therefore declared, never inherited, and
 * GET /question-types returns key, label, group and order rather than
 * passing keys() through. That is the only arrangement in which a
 * third-party type lands in the right family without a core edit.
 */
interface ReportingMetadataInterface
{
    public const GROUP_SELECTION = 'selection';
    public const GROUP_TEXT_ENTRY = 'text_entry';
    public const GROUP_ASSOCIATION = 'association';
    public const GROUP_GRAPHIC = 'graphic';
    public const GROUP_NUMERIC = 'numeric';
    public const GROUP_ARTIFACT = 'artifact';
    public const GROUP_COMPOSITE = 'composite';
    public const GROUP_CONTROL = 'control';

    /** One of the eight GROUP_* families. */
    public function group(): string;

    /** Sort order within the picker, ascending; ties break on label. */
    public function order(): int;

    /** The xAPI interaction type, or "other" when none fits. */
    public function xapiInteractionType(): string;

    /** The QTI element name, or null when the type has no export. */
    public function qtiElement(): ?string;
}
