<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * A bridge that constrains decoding to a declared schema.
 *
 * **Absent means** the runner validates after the fact and the run is flagged
 * `schema_unenforced`; the bridge may not claim constrained decoding.
 *
 * The keyword list is what AiSchemaNarrower narrows against, and the narrowed
 * schema is decoding guidance rather than the gate: DefinitionSchemaCheck
 * stays the authoritative validator, so a bridge that supports a smaller
 * keyword set weakens the guidance and never the check.
 */
interface ProducesStructuredOutput
{
    /**
     * The JSON Schema keywords this bridge actually enforces. Everything
     * outside the list is dropped by the narrower and named in
     * droppedConstraints, which is what the review screen shows.
     *
     * @return list<string>
     */
    public function supportedSchemaKeywords(): array;
}
