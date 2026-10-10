<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Aggregation;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * The aggregation methods this site can report a quiz grade with.
 *
 * The miss is defined here. quizzes.attempt_aggregation is a real column
 * holding a provider key, so it can name a method the site no longer has.
 * An unresolved method falls back to the free default, highest, rather than
 * leaving a learner with no grade at all, and the caller is told which it
 * got so a screen can say what the quiz is set to and what it is running
 * under.
 *
 * @extends AbstractProviderRegistry<AggregationMethodInterface>
 */
final class AggregationMethodRegistry extends AbstractProviderRegistry
{
    public const HIGHEST = 'highest';
    public const LATEST = 'latest';

    protected function providerInterface(): string
    {
        return AggregationMethodInterface::class;
    }

    public function resolve(?string $key): AggregationMethodInterface
    {
        if ($key !== null && $key !== '') {
            try {
                return $this->get($key);
            } catch (UnknownProviderException) {
                // Fall through to the free default below.
            }
        }

        return $this->get(self::HIGHEST);
    }

    public function isSubstituting(?string $key): bool
    {
        return $key !== null && $key !== '' && !$this->has($key);
    }
}
