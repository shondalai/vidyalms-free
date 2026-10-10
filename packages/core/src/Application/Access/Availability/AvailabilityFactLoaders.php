<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * The loaders the composer runs in phase one, keyed by fact key.
 *
 * It is not a provider registry, because a fact key is not an extension
 * point a stored row ever names: nothing is keyed to a loader, a missing
 * loader is a save-time refusal rather than a degraded render, and there is
 * no ledger question to ask about one.
 */
final class AvailabilityFactLoaders
{
    /** @var array<string, AvailabilityFactLoaderInterface> */
    private array $loaders = [];

    public function register(AvailabilityFactLoaderInterface $loader): void
    {
        $this->loaders[$loader->factKey()] = $loader;
    }

    public function has(string $factKey): bool
    {
        return isset($this->loaders[$factKey]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->loaders);
    }

    /**
     * @param list<string> $factKeys
     * @param list<int>    $courseIds
     * @param list<string> $subjectKeys the render set's subjects as "type:id"
     *
     * @return array<string, mixed>
     */
    public function loadAll(array $factKeys, AvailabilityContext $context, array $courseIds, array $subjectKeys = []): array
    {
        $facts = [];

        foreach ($factKeys as $factKey) {
            $loader = $this->loaders[$factKey] ?? null;

            if ($loader === null) {
                continue;
            }

            $facts[$factKey] = $loader instanceof SubjectAwareFactLoader
                ? $loader->loadForSubjects($context, $courseIds, $subjectKeys)
                : $loader->load($context, $courseIds);
        }

        return $facts;
    }
}
