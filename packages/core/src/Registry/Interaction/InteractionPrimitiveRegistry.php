<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Interaction;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * The eight primitives. The free build registers all of them and Pro adds
 * none; Pro composes them.
 *
 * The miss: an unresolved primitive means an unresolved type, because a type
 * names its primitive. Callers resolve through resolve(), which hands back
 * null rather than throwing, and the type resolution path turns that into
 * the same placeholder route an unresolvable key takes.
 *
 * @extends AbstractProviderRegistry<InteractionPrimitiveInterface>
 */
final class InteractionPrimitiveRegistry extends AbstractProviderRegistry
{
    public const SELECT = 'select';
    public const ORDER = 'order';
    public const ASSOCIATE = 'associate';
    public const TEXT = 'text';
    public const SCALAR = 'scalar';
    public const POINT = 'point';
    public const ARTIFACT = 'artifact';
    public const SIGNAL = 'signal';

    protected function providerInterface(): string
    {
        return InteractionPrimitiveInterface::class;
    }

    public function resolve(?string $key): ?InteractionPrimitiveInterface
    {
        if ($key === null || $key === '') {
            return null;
        }

        try {
            return $this->get($key);
        } catch (UnknownProviderException) {
            return null;
        }
    }
}
