<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;

/**
 * A fact that says whose data it just resolved, rather than leaving the
 * runner to guess from the shape of the value.
 *
 * `resolvesToUsers()` declares that a fact is about many people; it does not
 * say which people, and the runner's fallback convention could only recover
 * them from a value that happened to be a list of ints or of rows carrying a
 * `user_id`. The one shipped many-cardinality fact answers neither shape: a
 * response set is keyed by attempt, because a learner with two attempts wrote
 * two answers and the grouping proposal is confirmed against attempts. So a
 * grouping run over a hundred learners wrote no `ai_run_subjects` row at all,
 * and every one of those hundred learners was told "no runs" by the surface
 * this design offers as its Article 15 answer.
 *
 * A convention that a value has to be shaped to satisfy is a convention a
 * vendor fact will get wrong silently. Declaring the subjects is explicit, is
 * checked by a conformance test over every registered many-cardinality fact,
 * and lets the value keep whatever shape the task actually needs.
 *
 * The context comes back with the value because the people are usually one
 * join away from it: a response set knows its attempt ids and the user id is
 * on the attempt row.
 */
interface NamesFactSubjects
{
    /**
     * The user ids this resolved value is about, in any order and possibly
     * empty when the value itself is empty.
     *
     * @return list<int>
     */
    public function subjectsIn(mixed $value, AiFactContext $context): array;
}
