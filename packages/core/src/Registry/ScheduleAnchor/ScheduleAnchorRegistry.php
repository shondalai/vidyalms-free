<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<ScheduleAnchorInterface>
 */
final class ScheduleAnchorRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'schedule_anchor';

    protected function providerInterface(): string
    {
        return ScheduleAnchorInterface::class;
    }

    /**
     * The union of the fact keys every registered anchor declares.
     *
     * A condition that dispatches to this registry asks here rather than
     * naming keys of its own, so registering an anchor that needs a new fact
     * set widens the preload with no edit to any condition. A condition's
     * requiredFacts() is payload independent by design, so the union is the
     * only honest answer it can give.
     *
     * @return list<string>
     */
    public function requiredFacts(): array
    {
        $keys = [];

        foreach ($this->all() as $anchor) {
            if (!$anchor instanceof AnchorRequiresFacts) {
                continue;
            }

            foreach ($anchor->requiredFacts() as $factKey) {
                $keys[$factKey] = true;
            }
        }

        return array_keys($keys);
    }
}
