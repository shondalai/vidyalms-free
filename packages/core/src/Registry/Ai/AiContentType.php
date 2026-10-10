<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * How a task's output is meant to be read, which is a contract and not
 * bookkeeping.
 *
 * Three of the outputs a person reads soonest are free strings derived from
 * untrusted content, and none of them previously carried any statement about
 * how it renders. So the type is declared, the absence means the safest
 * reading, and a structural test asserts that no sanitised_html string
 * reaches a store or a render without the human-authored sanitiser and that
 * no plain_text output is rendered as markup.
 */
enum AiContentType: string
{
    /**
     * Never rendered as markup, never stored as HTML. The default an author
     * gets by writing nothing.
     */
    case PlainText = 'plain_text';

    /** Passes the human-authored content sanitiser before storage or render. */
    case SanitisedHtml = 'sanitised_html';

    /** A decoded document validated against the task's declared output schema. */
    case Structured = 'structured';
}
