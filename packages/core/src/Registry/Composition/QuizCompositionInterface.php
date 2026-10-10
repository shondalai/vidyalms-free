<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Composition;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * How a quiz decides which questions one attempt is served.
 *
 * The free build answers the quiz's own published questions, which is what
 * every quiz has always done. A paid package registers a composition that
 * resolves an ordered slot tree, runs the selection pass and then the
 * ordering pass, draws from pools and pins each draw to a version, and
 * nothing in the core changes to allow it: StartAttempt asks the registry
 * for a set and freezes whatever it gets.
 *
 * The provider claims a quiz rather than being named by a column. A column
 * would have to be written by the package that owns the feature, on quizzes
 * the package may never have seen, and it would then name a provider the
 * site can lose; claiming keeps the free default correct for every quiz
 * that has no slots, whichever packages come and go.
 */
interface QuizCompositionInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * Higher runs first. The free default sits at zero and always applies,
     * so it is the last claim standing and a quiz can never end up with no
     * composition at all.
     */
    public function priority(): int;

    /**
     * Whether this composition owns the given quiz. Asked in priority order
     * and only until one says yes, so a claim must be cheap: the slot
     * composition asks whether the quiz has slot rows and nothing more.
     */
    public function claims(QuizCompositionContext $context): bool;

    /**
     * The served set, in served order. Selection and ordering are the
     * composition's business; the manifest freezes what comes back and
     * never reorders it.
     */
    public function compose(QuizCompositionContext $context): ComposedQuestionSet;
}
