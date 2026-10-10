<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Composition;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * The compositions this site can build an attempt's form with.
 *
 * The miss is defined here, like every other registry's. A frozen manifest
 * names the composition that produced it, so an attempt can carry a key the
 * site no longer has; reading such an attempt resolves nothing and reports
 * the key as it was recorded, because the served set is already frozen and
 * nothing needs recomposing. A quiz whose paid composition has gone starts
 * its next attempt under the free default rather than refusing, which is the
 * same contract the attempt behaviour registry keeps.
 *
 * @extends AbstractProviderRegistry<QuizCompositionInterface>
 */
final class QuizCompositionRegistry extends AbstractProviderRegistry
{
    public const PUBLISHED_QUESTIONS = 'quiz_questions';

    protected function providerInterface(): string
    {
        return QuizCompositionInterface::class;
    }

    /**
     * The composition that claims this quiz, in declared priority order. The
     * free default claims everything at priority zero, so this always
     * answers.
     */
    public function resolveFor(QuizCompositionContext $context): QuizCompositionInterface
    {
        foreach ($this->byPriority() as $composition) {
            if ($composition->claims($context)) {
                return $composition;
            }
        }

        return $this->get(self::PUBLISHED_QUESTIONS);
    }

    /**
     * A composition by key, or the free default when the key names a
     * provider this site no longer has.
     */
    public function resolve(?string $key): QuizCompositionInterface
    {
        if ($key !== null && $key !== '') {
            try {
                return $this->get($key);
            } catch (UnknownProviderException) {
                // Fall through to the free default below.
            }
        }

        return $this->get(self::PUBLISHED_QUESTIONS);
    }

    /**
     * @return list<QuizCompositionInterface>
     */
    private function byPriority(): array
    {
        $compositions = $this->all();

        // A stable sort, so two compositions declaring the same priority
        // keep registration order rather than swapping between requests.
        usort(
            $compositions,
            static fn (QuizCompositionInterface $a, QuizCompositionInterface $b): int
                => $b->priority() <=> $a->priority(),
        );

        return $compositions;
    }
}
