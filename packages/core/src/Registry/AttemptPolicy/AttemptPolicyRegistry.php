<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\AttemptPolicy;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * The attempt behaviours this site can run.
 *
 * The miss is defined here. A behaviour key is written into a real
 * attempts.behaviour column and into the frozen policy snapshot, so it
 * arrives through an in-flight attempt whose snapshot names a behaviour the
 * site no longer has. An unresolved attempt policy falls back to
 * deferred_feedback and records the substitution on the attempt row, rather
 * than throwing out of the learner's attempt.
 *
 * @extends AbstractProviderRegistry<AttemptPolicyInterface>
 */
final class AttemptPolicyRegistry extends AbstractProviderRegistry
{
    public const DEFERRED_FEEDBACK = 'deferred_feedback';
    public const MANUAL = 'manual';

    protected function providerInterface(): string
    {
        return AttemptPolicyInterface::class;
    }

    /**
     * The behaviour named, or the free fallback. The caller is told which it
     * got through AttemptPolicySet::substitutedFrom().
     */
    public function resolve(?string $key): AttemptPolicyInterface
    {
        if ($key !== null && $key !== '') {
            try {
                return $this->get($key);
            } catch (UnknownProviderException) {
                // Fall through to the free default below.
            }
        }

        return $this->get(self::DEFERRED_FEEDBACK);
    }

    public function isSubstituting(?string $key): bool
    {
        return $key !== null && $key !== '' && !$this->has($key);
    }
}
