<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;

/**
 * The action checks its own params at save time.
 *
 * Absent means the params are accepted as written and fail at execution, which
 * is the outcome a builder hint alone always produces: a rule reaching the
 * table through the API, an importer or a course copy stores params that can
 * never succeed and nothing notices until the rule fires. *
 * The name collides deliberately with the availability namesake under
 * Registry\Availability. PHP namespaces keep the two apart and an author who
 * has met one has met the other, but a provider class implementing both an
 * availability condition and an automation action needs an aliased use
 * statement, so the collision is named here rather than discovered.
 */
interface ValidatesPayload
{
    /**
     * @param array<string, mixed> $params
     *
     * @return list<string> the refusals, empty when the params are acceptable
     */
    public function validateParams(array $params, PayloadValidationContext $context): array;
}
