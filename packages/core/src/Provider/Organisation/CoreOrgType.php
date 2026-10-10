<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Registry\OrgType\OrgTypeInterface;

/**
 * One of the free build's organisation types.
 *
 * A label and never a gate. The order is declaration order rather than
 * alphabetical, so the five general shapes lead and the six OneRoster values
 * follow them in the sequence a district recognises.
 */
final class CoreOrgType implements OrgTypeInterface
{
    public function __construct(
        private readonly string $name,
        private readonly int $position,
    ) {
    }

    public function key(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'org.type.' . $this->name;
    }

    public function order(): int
    {
        return $this->position;
    }
}
