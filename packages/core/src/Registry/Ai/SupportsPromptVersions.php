<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A task whose prompt and result shapes are versioned.
 *
 * **Absent means** `[1]`: one prompt version and one readable result version.
 * A resolvable key carrying an unsupported result version is a registry miss
 * and is treated identically to an unresolvable one, because a payload
 * written by a paid decoration and read by the free provider it displaced is
 * the same failure as a key nothing answers.
 */
interface SupportsPromptVersions
{
    public function promptVersion(): int;

    /**
     * @return list<int>
     */
    public function supportedResultVersions(): array;
}
