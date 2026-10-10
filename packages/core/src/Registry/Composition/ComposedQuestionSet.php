<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Composition;

/**
 * The question set one attempt is served, and the name of what produced it.
 *
 * The key travels onto the attempt's frozen policy snapshot, so an attempt
 * always says which composition built its form. Without it a site that
 * installs slot-based composition halfway through a cohort has two
 * populations of attempts and no way to tell them apart, which breaks both
 * the appeal and the item analysis.
 */
final class ComposedQuestionSet
{
    /** @var list<ComposedQuestion> */
    public readonly array $questions;

    /**
     * @param list<ComposedQuestion> $questions
     */
    public function __construct(public readonly string $compositionKey, array $questions)
    {
        $this->questions = $questions;
    }

    public function isEmpty(): bool
    {
        return $this->questions === [];
    }
}
