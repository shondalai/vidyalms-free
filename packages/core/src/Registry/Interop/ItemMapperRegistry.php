<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Interop;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\MapperContext;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * Mappers keyed by (format, direction).
 *
 * Empty in the free build. The miss is that an unresolved mapper is simply
 * absent: the format is not offered, rather than the import failing halfway
 * through.
 *
 * @extends AbstractProviderRegistry<ItemMapperInterface>
 */
final class ItemMapperRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return ItemMapperInterface::class;
    }

    public static function keyFor(string $format, string $direction): string
    {
        return $format . ':' . $direction;
    }

    public function resolve(string $format, string $direction = MapperContext::DIRECTION_IMPORT): ?ItemMapperInterface
    {
        try {
            return $this->get(self::keyFor($format, $direction));
        } catch (UnknownProviderException) {
            return null;
        }
    }

    /**
     * The formats this site can read or write, for the import picker.
     *
     * @return list<string>
     */
    public function formats(string $direction): array
    {
        $formats = [];

        foreach ($this->all() as $mapper) {
            if ($mapper->direction() === $direction) {
                $formats[] = $mapper->format();
            }
        }

        return array_values(array_unique($formats));
    }
}
