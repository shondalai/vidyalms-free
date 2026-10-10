<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

/**
 * The element declares the storage paths it draws, so a media sweep can see
 * a file a template still uses.
 *
 * Default when absent: a media sweep cannot see the element's files, which is
 * how a logo gets deleted out from under a template nobody thought was using
 * it.
 */
interface RequiresAsset
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string> logical storage paths
     */
    public function assetPaths(array $payload): array;
}
