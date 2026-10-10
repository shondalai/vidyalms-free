<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Integrity;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * The integrity observations this site records.
 *
 * The miss is a refusal on the write and a plain row on the read. A kind
 * nothing recognises is not recorded, because an audit table that accepts
 * any string a client sends is not an audit table; a kind already recorded
 * whose package has since gone still reads back, with its stored kind shown
 * verbatim, because deleting evidence to tidy a registry would be the worse
 * failure by far.
 *
 * @extends AbstractProviderRegistry<IntegrityEventKindInterface>
 */
final class IntegrityEventKindRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return IntegrityEventKindInterface::class;
    }
}
