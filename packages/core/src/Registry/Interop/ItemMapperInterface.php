<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Interop;

use Shondalai\VidyaLMS\Core\Registry\Context\MapperContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The anti-corruption layer between a foreign item format and this model.
 *
 * A reader parses a foreign item into a CanonicalItem and the binding on the
 * target type writes that into the type's own definition; an exporter runs
 * the same path backwards. Mappers return warnings, never throw, because a
 * lossy import that lands with a visible warning list is far more useful to
 * a migrating teacher than a hard failure.
 *
 * The free build registers no mappers; the registry and this interface ship
 * so the seam is defined and tested before anything uses it, and so a
 * third-party mapper needs no core edit.
 */
interface ItemMapperInterface extends ProviderInterface
{
    /** The wire format, for example "qti3", "moodlexml", "gift". */
    public function format(): string;

    /** MapperContext::DIRECTION_IMPORT or DIRECTION_EXPORT. */
    public function direction(): string;

    /**
     * @param array<string, mixed> $foreign a parsed foreign item
     */
    public function toCanonical(array $foreign, MapperContext $context): MappingResult;

    /**
     * @return MappingResult carrying the foreign representation
     */
    public function fromCanonical(CanonicalItem $item, MapperContext $context): MappingResult;
}
