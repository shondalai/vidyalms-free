<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\StatementForwarder;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<StatementForwarderInterface>
 */
final class StatementForwarderRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'statement_forwarder';

    protected function providerInterface(): string
    {
        return StatementForwarderInterface::class;
    }

    /**
     * Hands one statement to every forwarder that wants its event type.
     *
     * A forwarder that throws is contained here rather than in each caller: the
     * statement is built and published either way, and an LRS being unreachable
     * must never fail the attempt that produced the statement. The failure
     * reaches the caller as a returned list of provider keys rather than as an
     * exception, so a caller that wants to log it can and a caller that does
     * not is unaffected.
     *
     * @return list<string> the keys that failed
     */
    public function fanOut(XapiStatement $statement, StatementForwardContext $context): array
    {
        $failed = [];

        foreach ($this->all() as $forwarder) {
            $wanted = $forwarder->eventNames();

            // The event name AND the statement's verb. One event is published
            // into this fan-out, so a filter on the event name alone had one
            // value it could ever be given and a forwarder wanting completions
            // received everything or nothing. The verb is the originating fact
            // and the statement already carries it.
            if (
                $wanted !== []
                && !\in_array($context->eventName, $wanted, true)
                && !\in_array($statement->verb, $wanted, true)
            ) {
                continue;
            }

            try {
                $forwarder->forward($statement, $context);
            } catch (\Throwable) {
                $failed[] = $forwarder->key();
            }
        }

        return $failed;
    }
}
