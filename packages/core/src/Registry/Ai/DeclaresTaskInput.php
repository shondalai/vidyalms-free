<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A task that takes authored options.
 *
 * **Absent means** the task takes no authored options and the panel draws a
 * Run button only, which is the honest reading for every deterministic check:
 * a check over a draft has nothing to ask the author.
 */
interface DeclaresTaskInput
{
    /**
     * The JSON Schema the authored options are validated against before the
     * run is authorised, in the subset DefinitionSchemaCheck understands.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;
}
