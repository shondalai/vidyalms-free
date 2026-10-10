<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

final class EntityNotFoundException extends DomainException
{
    public static function for(string $entity, int|string $identifier): self
    {
        return new self(sprintf('%s "%s" was not found.', $entity, (string) $identifier));
    }
}
