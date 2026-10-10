<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * How this task's strings are meant to be rendered and stored.
 *
 * **Absent means** AiContentType::PlainText, so the safe reading is the one
 * an author gets by writing nothing: the string is never rendered as markup
 * and never reaches a store as HTML.
 */
interface DeclaresContentType
{
    public function contentType(): AiContentType;
}
