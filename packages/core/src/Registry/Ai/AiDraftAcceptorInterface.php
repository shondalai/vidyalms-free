<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Context\AiAcceptContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * How one kind of draft becomes a real artefact.
 *
 * Accepting resolves an acceptor from this registry rather than naming use
 * cases in a switch, which is what keeps the accept path open to a draft kind
 * a package added. A human always accepts: no risk tier permits automatic
 * acceptance.
 */
interface AiDraftAcceptorInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The domain write, keyed by draft kind.
     *
     * The accept use case keeps what only it may own: the human-Principal
     * check, the transaction boundary, the provenance write, the disclosure
     * tier derivation and the refusal of a draft naming no target. Everything
     * else was a switch over three use cases inside a free class, which meant
     * a bank-targeted draft had no accept path at all and the promise of zero
     * core edits was false the moment a vendor's target was not one of the
     * three.
     */
    public function accept(AiDraft $draft, AiAcceptContext $context): AcceptedArtefact;
}
