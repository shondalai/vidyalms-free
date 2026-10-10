<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * An importer that reads the four-way upsert choice and obeys it.
 *
 * Declaring the option is not the same as honouring it: an importer could
 * offer the select through ConfigurableImporterInterface and then ignore what
 * came back, and a screen has no way to tell. So the promise is a type. A
 * mapper that implements this is saying that create_only really creates only,
 * that update_only really creates nothing, and that a screen may therefore
 * show the choice as meaningful rather than decorative.
 *
 * It extends ConfigurableImporterInterface rather than ImporterInterface
 * because an importer that honours the policy must also declare it: an option
 * that changes behaviour and appears on no screen is reachable by an API
 * client and invisible to the operator whose roster it rewrites.
 */
interface HonoursUpsertPolicy extends ConfigurableImporterInterface
{
    /**
     * The policies this importer really implements, which is normally all four
     * and is allowed to be fewer.
     *
     * A source with no natural key cannot implement create_with_suffix
     * honestly, and saying so here is better than offering it and doing
     * something else. What is refused is the empty list: an importer honouring
     * no policy at all is one that should not declare this interface.
     *
     * @return list<string> a non-empty subset of UpsertPolicy::POLICIES
     */
    public function upsertPolicies(): array;
}
