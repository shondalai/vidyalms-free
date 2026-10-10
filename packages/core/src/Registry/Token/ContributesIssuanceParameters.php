<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Token;

use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;

/**
 * A token resolver that supplies its own inputs to the issuance parameter
 * bag.
 *
 * **Absent means the resolver contributes nothing**, and reads only what the
 * surface already assembled. That is the correct default for a family whose
 * values are plain facts of the issuance (the code, the dates, the names),
 * and it is why this is a capability probed with instanceof rather than a
 * method on the shipped interface.
 *
 * It exists because the alternative is the failure it replaced: a static
 * parameter builder with a closed argument list, so a family whose inputs
 * that builder did not happen to assemble could never resolve at all. The
 * contribution is merged before resolve() runs, so a resolver reads back in
 * resolve() exactly what it wrote here.
 *
 * A contribution never overwrites a key another contributor already wrote:
 * first registration wins, which makes the merge order the registry's own and
 * stops a package silently changing a family it does not own.
 */
interface ContributesIssuanceParameters
{
    /**
     * @return array<string, mixed> keys this resolver's own tokens read
     */
    public function parametersFor(IssuanceParameterContext $context): array;
}
