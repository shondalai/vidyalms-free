<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The accept seam, with golden rule two enforced at registration.
 *
 * "No task targets a grading, availability, credential or enrolment use
 * case" was asserted over the REGISTERED FREE POPULATION only, which is a
 * population that by construction cannot break it, so the rule could never
 * see the thing it exists to stop: a vendor acceptor. One registering under
 * its own draft kind wrote a real artefact with no registration-time check of
 * any kind.
 *
 * An acceptor now declares the extension point it writes through, and the
 * declaration is checked against the forbidden set here. It is declared
 * rather than read off the draft at accept time for the reason every other
 * rule in this design is a registration-time one: a check that runs when
 * somebody presses a button is a check a site meets on a screen months later.
 *
 * An acceptor that declares nothing is admitted and its accept-time target is
 * still refused by the use case, which is the published miss direction: a
 * provider written before the declaration existed is not retroactively
 * illegal, and a provider that wants the guarantee says so.
 *
 * @extends AbstractProviderRegistry<AiDraftAcceptorInterface>
 */
final class AiDraftAcceptorRegistry extends AbstractProviderRegistry
{
    /**
     * The four use-case families no AI output may ever be written through.
     *
     * Matched on the declared extension point, lowercased and compared whole,
     * so `grading` is refused and `grade_report_narrative` is not.
     *
     * @var list<string>
     */
    public const FORBIDDEN_TARGETS = ['grading', 'availability', 'credential', 'enrolment'];

    protected function providerInterface(): string
    {
        return AiDraftAcceptorInterface::class;
    }

    protected function guardProvider(ProviderInterface $provider): void
    {
        if (!$provider instanceof AiDraftAcceptorInterface || !$provider instanceof TargetsExtensionPoint) {
            return;
        }

        $target = strtolower(trim($provider->targetExtensionPoint()));

        if (!\in_array($target, self::FORBIDDEN_TARGETS, true)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'The draft acceptor "%s" declares the extension point "%s". No AI output is written through a '
            . 'grading, availability, credential or enrolment use case: a mark, a gate, a credential and an '
            . 'enrolment are decisions about a person, and a human pressing accept does not make one of '
            . 'them a suggestion.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $provider->key(),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $target,
        ));
    }
}
