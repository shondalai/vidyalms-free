<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Domain\Events\XapiStatementBuilt;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwardContext;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwarderRegistry;

/**
 * Hands every built statement to whatever forwarders are registered.
 *
 * The split the design settled on stays exactly as it was: the core builds
 * statements and publishes `XapiStatementBuilt`, and holds no LRS, no
 * endpoint, no credential and no queue. What this adds is the one thing that
 * was missing, which is a free code path reaching the seam. A registry whose
 * only free provider nothing calls is the failure the free-provider rule exists
 * to prevent, one step further in, and `log` exists precisely so a site owner
 * can see what a forwarder would send before buying one.
 *
 * **It is a listener and it swallows nothing quietly.** The registry contains a
 * forwarder's failure and answers which keys failed; this logs them once and
 * returns. An LRS being unreachable must never fail the attempt that produced
 * the statement, and the shipped event bus logs and swallows a listener failure
 * by design anyway, so a forwarder that threw past here would be a failure
 * nobody could see.
 */
final class ForwardBuiltStatements
{
    public function __construct(
        private readonly StatementForwarderRegistry $forwarders,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The bus hands every listener a `DomainEvent`, so the narrowing happens
     * here rather than in the signature: a listener typed on one concrete event
     * is a listener the bus cannot accept.
     */
    public function __invoke(DomainEvent $event): void
    {
        if (!$event instanceof XapiStatementBuilt) {
            return;
        }

        $failed = $this->forwarders->fanOut($event->statement, StatementForwardContext::create(
            $event->eventName(),
            $event->attemptId,
            $event->quizId,
            $event->courseId,
            $event->userId,
            // The moment the fact happened, never the fan-out time. It is the
            // one field a redelivery must not move.
            $event->statement->occurredAt,
        ));

        if ($failed === []) {
            return;
        }

        $this->logger->warning('vidyalms.xapi.forward_failed', [
            'statement_id' => $event->statement->id,
            'forwarders' => $failed,
        ]);
    }
}
