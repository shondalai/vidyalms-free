<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Context\AiRedactionContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The only path from a resolved payload to anything that may cross a model
 * boundary.
 *
 * A redactor is handed the resolved payload rather than the request, because
 * a redactor handed only chunk keys and option values is redacting nothing:
 * it cannot produce the manifest of what left the site, and the manifest is
 * what the learner right-to-know view and the dry run render.
 */
interface AiRedactorInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The resolved payload, reduced to what may cross the boundary, with the
     * manifest of what that was.
     *
     * The context carries the **keys** of the facts the task declared and
     * never their values, because the values are already in the payload and
     * a second copy would make the manifest ambiguous about which one left
     * the site.
     */
    public function redact(AiTaskPayload $payload, AiRedactionContext $context): RedactedPayload;
}
