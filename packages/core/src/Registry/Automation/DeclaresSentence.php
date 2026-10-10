<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * The one line the builder shows for this action, with numbered placeholders.
 *
 * Absent means the builder falls back to label(), which on a free action is a
 * translation key rather than a sentence, so an author reads
 * `vidyalms.automation.action.set_tag` where the design promised "tag the
 * learner {1}".
 *
 * The placeholders are numbered rather than named on purpose: a sentence is
 * translated and a translator reorders clauses, so `{1}` and `{2}` survive a
 * word order that named tokens inside prose do not.
 */
interface DeclaresSentence
{
    /**
     * The sentence template, as a language key or a literal, with `{1}`,
     * `{2}` and so on for the authored params in the order paramsSchema()
     * declares them.
     */
    public function sentence(): string;
}
