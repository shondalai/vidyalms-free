<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Context\BridgeContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The one thing in the design that reaches a model, and the only holder of a
 * transport.
 *
 * It is a registry and not a port: a bridge is a package concern exactly as a
 * notification channel is, so no adapter is edited and an adapter written
 * before the AI wave keeps booting. Ports documents itself as the complete
 * set of services an adapter must supply to boot the core, and a model is not
 * one of them.
 *
 * Cost is denominated in the unit the bridge declares and never in credits,
 * because a bring-your-own-key bridge that meters nothing would otherwise
 * record zero spend for ever and pass every ceiling.
 */
interface ModelBridgeInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The unit this bridge denominates its costs in, which is one of the four
     * CostRecord declares: `provider_units`, `tokens`, `usd_micros` or `none`.
     *
     * A metering service's own product word is not among them and never will
     * be: the core declares no such unit, so an implementer following the
     * sentence that used to stand here returned a value nothing downstream
     * could read. `provider_units` is the slot that word occupied, and what
     * one of them is worth is the bridge's business.
     */
    public function unit(): string;

    /**
     * The one work method, never widened.
     *
     * It takes a BridgeRequest, whose constructor accepts a RedactedPayload
     * and nothing else, so redaction and transmission are the same object
     * rather than two unrelated ones and a bridge cannot be handed a raw
     * segment list. Neither the request nor the context carries a Principal,
     * a domain entity, a course id or a learner name: a bridge that cannot
     * name a course cannot leak one, and that boundary holds even when the
     * redactor is replaced.
     *
     * The return is a union rather than a result carrying a nullable refusal,
     * so a caller never has to decide which of two fields is authoritative
     * about a result whose text is empty. A refusal is an ordinary outcome
     * and never an exception, and its kind is closed: the load-bearing pair
     * is a model that declined, which is a content signal and is not
     * retryable, against a transport that failed, which is retryable and says
     * nothing at all about the content.
     *
     * Everything else a bridge can do is a capability probed with instanceof,
     * because each absence is an honest answer a site owner has to be able to
     * give: a bridge that meters nothing records unknown and never zero, and
     * a route that declares no data handling answers "not declared by this
     * route" rather than nothing.
     */
    public function run(BridgeRequest $request, BridgeContext $context): BridgeResult|BridgeRefusal;
}
