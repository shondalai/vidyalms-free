<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * Which stored definition versions this provider can read.
 *
 * This is the interface that stops decoration silently regrading a site the
 * day Pro is removed, and it closes the case the registry-miss call sites
 * cannot see. Pro decorates the free six through replace(), so removing Pro
 * re-registers the free provider under the same key and get('fill_blank')
 * resolves: no exception is ever thrown for the six most-used keys. The free
 * provider then reads a Pro-shaped definition, finds none of the fields it
 * expects, and awards zero with a normal score status and no warning. Data
 * survives; marks do not.
 *
 * A row whose questions.definition_version exceeds what the resolved
 * provider declares is therefore treated exactly as an unresolvable key:
 * placeholder presentation, raw 0, score status notscored, an explanatory
 * row, and never a grading formula that cannot read it.
 *
 * The default when a provider does not implement this is [1], which is the
 * conservative reading: a provider that says nothing is assumed to
 * understand only the original shape.
 */
interface DefinitionVersionAwareInterface
{
    /**
     * @return list<int> every stored version this provider can validate,
     *                   present and grade, ascending
     */
    public function supportedDefinitionVersions(): array;
}
