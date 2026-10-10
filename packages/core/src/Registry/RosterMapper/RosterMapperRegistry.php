<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\RosterMapper;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<RosterMapperInterface>
 */
final class RosterMapperRegistry extends AbstractProviderRegistry
{
    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    protected function providerInterface(): string
    {
        return RosterMapperInterface::class;
    }

    /**
     * The one published spelling of a roster key, matching ItemMapperRegistry
     * exactly so a mapper author who has written one has written both.
     *
     * It is a static method rather than a convention in three documents
     * because three renderings of one key is what a third-party author reads
     * before getting it wrong, and because a reader and a writer of one format
     * registered under one bare format name would throw a duplicate on a paid
     * site and take the rest of that package's registrations down with it.
     */
    public static function keyFor(string $format, string $direction): string
    {
        return $format . ':' . $direction;
    }
}
