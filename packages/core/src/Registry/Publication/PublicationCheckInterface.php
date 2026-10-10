<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Publication;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One check run on the draft to ready transition.
 *
 * **In the core because the free build has checks to run.** Several of the
 * design's list are blocks over free types and free features: an item marked
 * with correct_answers_only whose min_choices is below 1, a definition
 * carrying a negative per-entry fraction on a quiz whose policy does not
 * enable negative marking, an option reading "all of the above" inside a
 * shuffled set with fixed unset, guess_neutral on an item with fewer
 * distractors than keys. A site with no paid package must run those, and a
 * third party who wants to add a check over a free type must not have to
 * take a dependency on a paid one.
 *
 * Each check is a registered provider so a package adds one without a core
 * edit, and so a site can replace a check it disagrees with by registering
 * over its key rather than by forking the type that used to contain the
 * rule. That last property is why these are not methods on the question
 * type: a check that lives inside a type can only be changed by replacing
 * the type, which changes how every already-authored item of that type is
 * presented and marked.
 */
interface PublicationCheckInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * @return list<PublicationFinding>
     */
    public function run(PublicationContext $context): array;
}
